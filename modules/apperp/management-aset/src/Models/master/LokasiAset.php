<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Platform\Modules\Contracts\DataClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Services\LocationInheritance;

/**
 * Pohon lokasi fisik aset; padanan "Functional location" di Dynamics 365 F&O.
 *
 * Hierarkinya hidup di data, bukan di skema: `parent_id` menunjuk dirinya sendiri
 * sedalam yang dibutuhkan tenant. Ia sengaja terpisah dari struktur organisasi Core,
 * karena "di mana benda ini berada" dan "siapa yang bertanggung jawab" adalah dua
 * pertanyaan berbeda yang berubah karena sebab berbeda. `org_unit_id` adalah
 * jembatan opsional antara keduanya.
 *
 * Kolom di bawah adalah tambahan atas bentuk dasar master; bentuk dasarnya disebutkan
 * pada `MasterData`. `org_unit_id` tanpa foreign key: unit organisasi dimiliki Core.
 *
 * `alamat_id` menunjuk tempat beralamat pos di buku alamat Core, dan `departemen_bawaan_id` unit kerja
 * yang mengisi unit penanggung jawab aset yang ditempatkan di sini. Keduanya tanpa foreign key karena
 * dimiliki Core, dan keduanya diwarisi lokasi anak yang mengosongkannya ({@see LocationInheritance}).
 *
 * @property ?string $parent_id
 * @property ?string $tipe_lokasi_id
 * @property ?string $org_unit_id
 * @property ?string $alamat_id
 * @property ?string $departemen_bawaan_id
 */
class LokasiAset extends MasterData
{
    /**
     * `alamat_id` hanya penunjuk ke tempat di buku alamat Core, tetapi tempat itu alamat organisasi:
     * dinyatakan eksplisit, tidak ikut bawaan.
     *
     * @var array<string, DataClass>
     */
    public const COLUMN_CLASSIFICATION = [
        ...MasterData::COLUMN_CLASSIFICATION,
        'alamat_id' => DataClass::OrganizationIdentifiableInformation,
    ];

    protected $table = 'aset_m_lokasi_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'parent_id', 'tipe_lokasi_id', 'org_unit_id', 'alamat_id', 'departemen_bawaan_id',
    ];

    /** @return BelongsTo<self, $this> */
    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsTo<TipeLokasiAset, $this> */
    public function tipeLokasi(): BelongsTo
    {
        return $this->belongsTo(TipeLokasiAset::class, 'tipe_lokasi_id');
    }
}
