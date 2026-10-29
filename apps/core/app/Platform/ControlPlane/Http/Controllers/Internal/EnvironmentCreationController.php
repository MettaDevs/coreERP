<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Models\EnvironmentOperation;
use App\Platform\Tenant\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EnvironmentCreationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
            'kind' => ['required', 'string', 'in:production,demo,sandbox'],
            'name' => ['required', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:today', 'required_if:kind,demo'],
            'hosting' => ['nullable', 'string', 'in:provider,client_server'],
            'requested_by' => ['required', 'integer', 'exists:users,id'],
        ], [
            'expires_at.required_if' => 'Lingkungan demo wajib punya tanggal berakhir.',
            'expires_at.after' => 'Tanggal berakhir harus setelah hari ini.',
            'hosting.in' => 'Pilih server kita atau server klien.',
        ]);
        $hosting = $input['hosting'] ?? 'provider';
        if ($hosting === 'client_server' && $input['kind'] !== 'production') {
            throw ValidationException::withMessages(['hosting' => 'Server klien hanya untuk lingkungan produksi. Demo dan sandbox selalu berjalan di server kita.']);
        }
        $tenant = Tenant::query()->whereKey($input['tenant_id'])->firstOrFail();
        $environment = DB::transaction(function () use ($request, $input, $hosting, $tenant): Environment {
            $environment = $this->insert($tenant, $input['kind'], $input['name'], isset($input['expires_at']) ? Carbon::parse($input['expires_at']) : null, (int) $input['requested_by'], $hosting);
            EnvironmentOperation::query()->create([
                'environment_id' => $environment->id,
                'operation' => 'provision', 'status' => 'succeeded',
                'requested_by' => $input['requested_by'], 'step' => 'tercatat-di-registry',
                'detail' => ['kind' => $input['kind'], 'hosting' => $hosting, 'database' => ($environment->database_name ?? (string) DB::connection()->getDatabaseName())],
                'started_at' => now(), 'finished_at' => now(),
            ]);
            DB::table('operator_audit_events')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => $input['requested_by'], 'action' => 'environment.created',
                'subject_type' => 'environment', 'subject_id' => $environment->id,
                'detail' => json_encode(['tenant_id' => $tenant->id, 'kind' => $input['kind'], 'hosting' => $hosting], JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);

            return $environment;
        });

        return response()->json(['environment_id' => $environment->id, 'tenant_id' => $environment->tenant_id], 201);
    }

    /**
     * Sisip dulu, tangkap bentrokannya — bukan periksa dulu lalu sisip.
     *
     * Memeriksa lebih dulu menyisakan jendela antara pemeriksaan dan penyisipan, dan dua operator
     * yang menekan tombolnya pada detik yang sama akan sama-sama lolos pemeriksaan. Yang benar-benar
     * memutuskan adalah partial unique index di PostgreSQL, jadi ia yang ditanya.
     *
     * Dua index dapat menolak di sini dan keduanya berarti hal yang sangat berbeda bagi operator,
     * sehingga pesannya dibedakan dari nama constraint-nya — bukan ditebak dari urutan percobaan.
     *
     * **Tiap percobaan dibungkus transaksi bersarang, dan itu bukan kerapian.** Di PostgreSQL,
     * sebuah pernyataan yang gagal membatalkan seluruh blok transaksi: pernyataan berikutnya
     * ditolak dengan `25P02 current transaction is aborted` sampai blok itu ditutup. Menangkap
     * exception-nya di PHP tidak memulihkan apa pun — percobaan kedua gagal karena percobaan
     * pertama, bukan karena slugnya. Transaksi bersarang Laravel diterjemahkan menjadi SAVEPOINT,
     * dan rollback ke savepoint itulah yang mengembalikan blok luarnya ke keadaan dapat dipakai.
     */
    private function insert(
        Tenant $tenant,
        string $kind,
        string $name,
        ?Carbon $expiresAt,
        int $requestedBy,
        string $hosting,
    ): Environment {
        $base = Str::slug($name) !== '' ? Str::slug($name) : $kind;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $slug = $attempt === 0 ? $base : $base.'-'.($attempt + 1);

            try {
                return DB::transaction(fn (): Environment => Environment::query()->create([
                    'tenant_id' => $tenant->id,
                    'kind' => $kind,
                    'name' => $name,
                    'slug' => Str::limit($slug, 120, ''),
                    'status' => 'provisioning',
                    'expires_at' => $expiresAt,
                    'hosting' => $hosting,
                    'outbound_allowed' => $kind === 'production',
                    'created_by' => $requestedBy,
                ]));
            } catch (UniqueConstraintViolationException $conflict) {
                if (str_contains($conflict->getMessage(), 'environments_satu_produksi')) {
                    throw ValidationException::withMessages(['name' => 'Tenant '.$tenant->name.' sudah punya lingkungan produksi. Satu tenant hanya boleh punya satu.',
                    ]);
                }

                if (str_contains($conflict->getMessage(), 'environments_satu_per_jenis')) {
                    throw ValidationException::withMessages(['name' => 'Tenant '.$tenant->name.' sudah punya lingkungan '.$kind.'. Satu tenant hanya boleh punya satu demo dan satu sandbox, karena alamatnya hanya memuat tenant dan jenis.',
                    ]);
                }

                if (! str_contains($conflict->getMessage(), 'environments_slug_per_tenant')) {
                    throw $conflict;
                }
            }
        }

        throw ValidationException::withMessages(['name' => 'Nama "'.$name.'" sudah dipakai berkali-kali di tenant ini. Beri nama yang lebih membedakan.',
        ]);
    }
}
