<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Support\Modules\Contracts\ChangeLogValueResolver;
use App\Support\Modules\Contracts\DirektoriOrganisasi;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/**
 * Nilai log perubahan register aset dalam bentuk yang dibaca orang: nama lokasi, kondisi, dan unit kerja
 * alih-alih ULID-nya, dan label status alih-alih kodenya. Master yang sudah diarsipkan tetap diterjemahkan,
 * karena riwayat lama menyebutnya.
 */
final class AssetChangeLogValues implements ChangeLogValueResolver
{
    public function __construct(private readonly DirektoriOrganisasi $organisasi) {}

    public function table(): string
    {
        return (new Aset)->getTable();
    }

    public function display(string $tenantId, string $field, array $values): array
    {
        return match ($field) {
            'lifecycle_state' => array_intersect_key(StatusAset::LABELS, array_flip($values)),
            'lokasi_aset_id' => $this->names(LokasiAset::withTrashed()->whereIn('id', $values)->get(['id', 'nama'])->all()),
            'kondisi_aset_id' => $this->names(KondisiAset::withTrashed()->whereIn('id', $values)->get(['id', 'nama'])->all()),
            'responsible_org_unit_id' => collect($this->organisasi->unitOperasi($tenantId))
                ->whereIn('id', $values)->pluck('nama', 'id')->map(fn ($nama): string => (string) $nama)->all(),
            default => [],
        };
    }

    /**
     * @param  array<int, LokasiAset|KondisiAset>  $models
     * @return array<string, string>
     */
    private function names(array $models): array
    {
        $names = [];
        foreach ($models as $model) {
            $names[(string) $model->getKey()] = (string) $model->getAttribute('nama');
        }

        return $names;
    }
}
