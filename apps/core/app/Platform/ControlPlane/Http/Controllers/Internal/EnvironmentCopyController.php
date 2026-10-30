<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Platform\ControlPlane\Jobs\CopyEnvironment as CopyEnvironmentJob;
use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Models\EnvironmentOperation;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class EnvironmentCopyController extends Controller
{
    public function store(Request $request, string $environment): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'requested_by' => ['required', 'integer', 'exists:users,id'],
        ], [
            'name.required' => 'Isi nama sandbox.',
            'name.string' => 'Nama sandbox harus berupa teks.',
            'name.max' => 'Nama sandbox paling panjang 100 karakter.',
            'requested_by.required' => 'Identitas operator harus disertakan.',
            'requested_by.integer' => 'Identitas operator tidak valid.',
            'requested_by.exists' => 'Akun operator sudah tidak tersedia.',
        ]);
        $slug = Str::slug($input['name']);
        abort_if($slug === '' || mb_strlen($slug) > 120, 422, 'Nama sandbox harus berisi huruf atau angka.');
        abort_if(config('queue.default') === 'sync', 503, 'Penyalinan memerlukan worker antrean latar.');
        $job = null;
        $acquired = false;
        $target = null;
        $lock = new UniqueLock(Cache::store());

        try {
            $target = DB::transaction(function () use ($request, $environment, $input, $slug, $lock, &$job, &$acquired): Environment {
                $source = Environment::query()->whereKey($environment)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
                abort_if($source->hostedOnClientServer() || $source->kind !== 'production' || $source->status !== 'active' || $source->database_name === null, 409,
                    'Yang dapat disalin hanya production aktif di server kita dengan database sendiri.');
                $target = Environment::query()->where('tenant_id', $source->tenant_id)->where('kind', 'sandbox')->whereNull('deleted_at')->lockForUpdate()->first();

                $newTarget = ! $target instanceof Environment;

                if ($target instanceof Environment) {
                    abort_if($target->source_environment_id !== $source->id || $target->slug !== $slug, 409,
                        'Tenant sudah punya sandbox lain. Penyalinan tidak menimpanya.');
                    abort_unless(in_array($target->status, ['copying', 'degraded'], true), 409,
                        'Sandbox sudah aktif atau tidak dapat dilanjutkan. Penyalinan tidak menimpanya.');
                } else {
                    abort_if(Environment::query()->where('tenant_id', $source->tenant_id)->where('slug', $slug)->whereNull('deleted_at')->exists(), 409,
                        'Nama ini sudah dipakai lingkungan lain. Pilih nama sandbox yang berbeda.');
                    $target = Environment::query()->create([
                        'tenant_id' => $source->tenant_id, 'kind' => 'sandbox', 'name' => $input['name'],
                        'slug' => $slug, 'source_environment_id' => $source->id, 'status' => 'copying',
                        'hosting' => 'provider', 'outbound_allowed' => false, 'created_by' => $input['requested_by'],
                    ]);
                }

                abort_if(EnvironmentOperation::query()->where('source_environment_id', $source->id)
                    ->where('operation', 'copy')->where('status', 'running')->where('lease_until', '>', now())->exists(),
                    409, 'Penyalinan dari production ini masih berjalan. Tunggu hasilnya di riwayat.');

                $job = new CopyEnvironmentJob($source->id, $target->id, $target->name, (int) $input['requested_by']);
                $acquired = $lock->acquire($job);
                abort_if(! $acquired && $newTarget, 409, 'Penyalinan sedang dipesan proses lain. Periksa kembali riwayat lingkungan.');

                if ($acquired) {
                    $target->update(['status' => 'copying']);
                    DB::table('operator_audit_events')->insert([
                        'id' => (string) Str::ulid(), 'user_id' => $input['requested_by'],
                        'action' => 'environment.copy_requested', 'subject_type' => 'environment', 'subject_id' => $target->id,
                        'detail' => json_encode(['source_environment_id' => $source->id, 'tenant_id' => $source->tenant_id], JSON_THROW_ON_ERROR),
                        'ip_address' => $request->ip(), 'occurred_at' => now(),
                    ]);
                }

                return $target;
            });

            if ($acquired && $job instanceof CopyEnvironmentJob) {
                // Seperti antrean upgrade, reservasi unik diambil sebelum dispatch supaya respons
                // kedua benar-benar idempotent. Registry sudah commit sebelum worker dapat membacanya.
                app(Dispatcher::class)->dispatch($job);
            }
        } catch (Throwable $error) {
            if ($acquired && $job instanceof CopyEnvironmentJob) {
                $lock->release($job);

                if ($target instanceof Environment) {
                    $target->newQuery()->whereKey($target->id)->where('status', 'copying')->update(['status' => 'degraded']);
                }
            }

            if ($error instanceof UniqueConstraintViolationException && str_contains($error->getMessage(), 'environments_')) {
                abort(409, 'Lingkungan berubah saat permintaan diproses. Muat ulang daftar sebelum mencoba lagi.');
            }

            throw $error;
        }

        return response()->json([
            'source_environment_id' => $environment,
            'target_environment_id' => $target->id,
            'tenant_id' => $target->tenant_id,
            'status' => $target->status,
            'queued' => true,
        ], 202);
    }
}
