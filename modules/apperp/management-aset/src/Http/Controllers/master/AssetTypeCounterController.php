<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\AssetTypeCounter;
use Modules\Apperp\ManagementAset\Models\master\CounterType;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;

/**
 * Counter yang boleh dibaca pada aset satu jenis, disunting dari seksi Counter aset di form jenis
 * aset; padanan FastTab *Counters* pada *Asset types* F&O.
 *
 * Hanya dari arah jenis aset, jadi cukup versi jenis aset yang diklaim. Kaitan yang dilepas
 * diarsipkan, bukan dihapus.
 */
final class AssetTypeCounterController extends Controller
{
    public function index(Request $request, string $jenisAsetId): JsonResponse
    {
        $this->permission($request, 'read');
        $jenisAset = JenisAset::query()->findOrFail($jenisAsetId);

        return response()->json($this->transfer($jenisAsetId, (int) $jenisAset->version));
    }

    public function replace(Request $request, string $jenisAsetId): JsonResponse
    {
        return $this->replacePayload($request, $jenisAsetId, $request->all());
    }

    /** @param array<string, mixed> $payload */
    public function replacePayload(Request $request, string $jenisAsetId, array $payload, ?int $expectedVersion = null): JsonResponse
    {
        $this->permission($request, 'update');
        $tenant = (string) $request->attributes->get('coreerp.tenant_id');
        JenisAset::query()->findOrFail($jenisAsetId);
        $data = validator($payload, [
            'jenis_counter_ids' => ['present', 'array', 'max:200'],
            'jenis_counter_ids.*' => ['required', 'distinct', 'ulid'],
        ])->validate();
        $linked = AssetTypeCounter::query()->where('jenis_aset_id', $jenisAsetId)
            ->whereIn('jenis_counter_id', $data['jenis_counter_ids'])->pluck('jenis_counter_id')->all();
        $counters = CounterType::withTrashed()->whereIn('id', $data['jenis_counter_ids'])->get(['id', 'aktif', 'deleted_at'])->keyBy('id');
        $errors = [];
        foreach ($data['jenis_counter_ids'] as $index => $counterId) {
            $counter = $counters->get($counterId);
            if ($counter === null || ($counter->trashed() && ! in_array($counterId, $linked, true))) {
                $errors['jenis_counter_ids.'.$index] = 'Counter yang dipilih tidak tersedia.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $active = $counters->filter(fn (CounterType $counter): bool => $counter->aktif && ! $counter->trashed())->keys()->all();
        if (array_diff($data['jenis_counter_ids'], $active, $linked) !== []) {
            throw ValidationException::withMessages(['jenis_counter_ids' => 'Counter yang dipilih sudah tidak aktif.']);
        }
        $expected = ($expectedVersion ?? RowVersion::expected($request));

        DB::transaction(function () use ($jenisAsetId, $data, $expected, $tenant): void {
            RowVersion::claim(JenisAset::query()->findOrFail($jenisAsetId), $expected);
            $current = AssetTypeCounter::query()->where('jenis_aset_id', $jenisAsetId)->pluck('jenis_counter_id')->map(strval(...))->all();
            AssetTypeCounter::query()->where('jenis_aset_id', $jenisAsetId)
                ->whereNotIn('jenis_counter_id', $data['jenis_counter_ids'])->delete();
            foreach (array_diff($data['jenis_counter_ids'], $current) as $counterId) {
                AssetTypeCounter::query()->create([
                    'tenant_id' => $tenant,
                    'jenis_aset_id' => $jenisAsetId,
                    'jenis_counter_id' => $counterId,
                ]);
            }
        });

        return response()->json($this->transfer($jenisAsetId, (int) JenisAset::query()->whereKey($jenisAsetId)->value('version')));
    }

    /** @return array<string, mixed> */
    private function transfer(string $jenisAsetId, int $version): array
    {
        $selected = AssetTypeCounter::query()->where('jenis_aset_id', $jenisAsetId)->pluck('jenis_counter_id')->map(strval(...))->all();
        $all = CounterType::withTrashed()->where(fn ($query) => $query
            ->where(fn ($available) => $available->whereNull('deleted_at')->where('aktif', true))
            ->orWhereIn('id', $selected))
            ->orderBy('kode')->get(['id', 'kode', 'nama', 'satuan']);

        return ['data' => [
            'remaining' => $all->reject(fn (CounterType $item): bool => in_array((string) $item->id, $selected, true))->values(),
            'selected' => $all->filter(fn (CounterType $item): bool => in_array((string) $item->id, $selected, true))->values(),
        ], 'version' => $version];
    }

    private function permission(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.jenis-aset.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }
}
