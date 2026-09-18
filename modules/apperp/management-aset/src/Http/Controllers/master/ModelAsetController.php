<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Support\MasterChild;
use Modules\Apperp\ManagementAset\Support\MasterParent;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/**
 * Katalog model barang per pabrikan. Master pertama dengan dua induk yang saling
 * lepas: pabrikan wajib, jenis opsional, dan tidak ada penyaringan bertingkat di
 * antara keduanya.
 *
 * @extends MasterDataController<ModelAset>
 */
class ModelAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'model-aset';
    }

    protected function model(): string
    {
        return ModelAset::class;
    }

    protected function parentMasters(): array
    {
        return [
            new MasterParent(
                table: 'aset_m_pabrikan_aset',
                column: 'pabrikan_aset_id',
                relation: 'pabrikanAset',
                label: 'pabrikan aset',
            ),
            new MasterParent(
                table: 'aset_m_jenis_aset',
                column: 'jenis_aset_id',
                relation: 'jenisAset',
                label: 'jenis aset',
                required: false,
            ),
        ];
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'aset_tr_aset', column: 'model_aset_id', label: 'aset'),
        ];
    }

    /**
     * Jumlah aset dipakai oleh grid gabungan pabrikan-model. Ia dihitung sebagai
     * subquery terkorlasi agar satu permintaan daftar tidak membuat query per model,
     * dan tetap dilewatkan ke policy organisasi aset.
     */
    protected function prepareQuery(Builder $query, ?Request $request = null): Builder
    {
        if (! $request || ! in_array('management-aset.aset.read', $request->attributes->get('coreerp.permissions', []), true)) {
            return $query;
        }

        // Tanpa alias tabel: scope tenant menyaring dengan nama tabel yang sebenarnya, dan
        // alias akan menyembunyikan nama itu dari klausa yang disisipkannya.
        $daftarAset = Aset::query()
            ->whereColumn('aset_tr_aset.model_aset_id', 'aset_m_model_aset.id')
            ->whereNotIn('aset_tr_aset.lifecycle_state', StatusAset::tidakLagiBeredar());

        $daftarAset = app(OrganizationScope::class)->asetQuery($daftarAset, $request);

        return $query->addSelect([
            'aset_count' => $daftarAset->selectRaw('count(*)'),
        ]);
    }

    protected function extraPresent(MasterData $record): array
    {
        return [
            'aset_count' => $record->getAttribute('aset_count') === null
                ? null
                : (int) $record->getAttribute('aset_count'),
        ];
    }
}
