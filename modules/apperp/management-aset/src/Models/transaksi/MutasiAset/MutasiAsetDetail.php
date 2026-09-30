<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use App\Support\Modules\Contracts\MilikTenant;
use App\Support\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Satu aset pada satu berita acara serah terima.
 *
 * Kolom `asal_*` kosong selama dokumen masih draf dan diisi saat dokumen diselesaikan.
 * Keadaan asal yang benar adalah keadaan pada saat serah terima terjadi, bukan pada saat
 * dokumen diketik; selama masih draf, layar membacanya langsung dari asetnya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mutasi_aset_id
 * @property int $line_number
 * @property string $aset_id
 * @property ?string $kondisi_aset_id
 * @property ?string $asal_lokasi_id
 * @property ?string $asal_org_unit_id
 * @property ?string $asal_custodian_user_id
 * @property ?string $catatan
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class MutasiAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'asal_custodian_user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    /**
     * Nama kolom untuk filter tambahan laporan (K-30), padanan Caption field tabel di BC; tipe kolom dibaca
     * dari database. Lihat {@see TableFields}.
     *
     * @var array<string, string>
     */
    public const FIELD_CAPTIONS = [
        'line_number' => 'No. baris',
        'aset_id' => 'Aset',
        'kondisi_aset_id' => 'Kondisi',
        'asal_lokasi_id' => 'Lokasi asal',
        'asal_org_unit_id' => 'Unit asal',
        'asal_custodian_user_id' => 'Penanggung jawab asal',
        'catatan' => 'Catatan',
    ];

    /** @var array<string, string> Resource pemilih untuk kolom rujukan. */
    public const FIELD_LOOKUPS = [
        'aset_id' => 'aset',
        'kondisi_aset_id' => 'kondisi-aset',
        'asal_lokasi_id' => 'lokasi-aset',
        'asal_org_unit_id' => 'reference-data/unit-kerja',
        'asal_custodian_user_id' => 'reference-data/anggota',
    ];

    /** @var array<string, string> Kolom yang sengaja tidak ditawarkan sebagai filter, dengan alasannya. */
    public const FIELD_HIDDEN = [
        'mutasi_aset_id' => 'Kunci dokumen induk; saring lewat bagian Mutasi.',
    ];

    protected $table = 'aset_tr_mutasi_aset_details';

    protected $fillable = [
        'tenant_id', 'mutasi_aset_id', 'line_number', 'aset_id', 'kondisi_aset_id',
        'asal_lokasi_id', 'asal_org_unit_id', 'asal_custodian_user_id', 'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_number' => 'integer'];
    }

    /** @return BelongsTo<MutasiAset, $this> */
    public function mutasi(): BelongsTo
    {
        return $this->belongsTo(MutasiAset::class, 'mutasi_aset_id');
    }

    /** @return BelongsTo<Aset, $this> */
    public function aset(): BelongsTo
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
}
