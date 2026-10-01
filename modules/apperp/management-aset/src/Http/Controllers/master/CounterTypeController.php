<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\CounterType;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Services\AssetUnitOfMeasureDirectory;
use Modules\Apperp\ManagementAset\Support\MasterChild;
use RuntimeException;

/**
 * Jenis counter aset; padanan *Counter* di Dynamics 365 F&O Asset Management.
 *
 * Satuan wajib dan diambil dari daftar satuan Core, bukan diketik, supaya "jam" pada counter berarti
 * hal yang sama dengan "jam" di aplikasi lain.
 *
 * @extends MasterDataController<CounterType>
 */
class CounterTypeController extends MasterDataController
{
    /** Tenant permintaan, ditangkap dari `extraRules()` karena `extraPayload()` tidak menerimanya. */
    private string $tenantId = '';

    protected function resource(): string
    {
        return 'jenis-counter';
    }

    protected function model(): string
    {
        return CounterType::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild('aset_m_rencana_pemeliharaan_baris', 'jenis_counter_id', 'baris rencana pemeliharaan'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $this->tenantId = $tenantId;

        return ['satuan_id' => [...($creating ? ['required'] : ['sometimes', 'required']), 'ulid']];
    }

    protected function extraPayload(array $data): array
    {
        if (! array_key_exists('satuan_id', $data)) {
            return [];
        }

        try {
            $units = app(AssetUnitOfMeasureDirectory::class)->resolve($this->tenantId, [$data['satuan_id']]);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'satuan_id' => 'Satuan tidak ditemukan, tidak aktif, atau belum dapat diperiksa.',
            ]);
        }

        // `satuan` adalah salinan kode untuk tampilan; yang dirujuk tetap `satuan_id`.
        return ['satuan_id' => $data['satuan_id'], 'satuan' => (string) ($units[$data['satuan_id']]['code'] ?? '')];
    }

    protected function extraPresent(MasterData $record): array
    {
        return $record->only(['satuan_id', 'satuan']);
    }
}
