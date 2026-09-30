<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use App\Support\Modules\Contracts\MilikTenant;
use App\Support\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;

/**
 * Header work order pemeliharaan aset.
 *
 * Asetnya tidak ada di header melainkan di baris pekerjaan, mengikuti Dynamics 365 F&O,
 * supaya satu perintah kerja dapat mencakup beberapa aset tanpa memecah dokumen.
 *
 * Tiga pasang waktu yang tidak boleh saling menggantikan: yang diharapkan pemohon, yang
 * dijadwalkan perencana, dan yang benar-benar terjadi di lapangan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $responsible_org_unit_id
 * @property string $tipe_work_order_id
 * @property ?string $tingkat_layanan_id
 * @property ?string $keterangan
 * @property ?string $penanggung_jawab_user_id
 * @property ?Carbon $diharapkan_mulai
 * @property ?Carbon $diharapkan_selesai
 * @property ?Carbon $dijadwalkan_mulai
 * @property ?Carbon $dijadwalkan_selesai
 * @property ?Carbon $aktual_mulai
 * @property ?Carbon $aktual_selesai
 * @property string $status
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class PemeliharaanAset extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'penanggung_jawab_user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    /**
     * Nama kolom untuk filter tambahan laporan (K-30), padanan Caption field tabel di BC; tipe kolom dibaca
     * dari database. Lihat {@see TableFields}.
     *
     * @var array<string, string>
     */
    public const FIELD_CAPTIONS = [
        'kode' => 'Nomor work order',
        'responsible_org_unit_id' => 'Unit organisasi',
        'tipe_work_order_id' => 'Tipe work order',
        'tingkat_layanan_id' => 'Tingkat layanan',
        'keterangan' => 'Keterangan',
        'penanggung_jawab_user_id' => 'Penanggung jawab',
        'aktual_mulai' => 'Aktual mulai',
        'aktual_selesai' => 'Aktual selesai',
        'status' => 'Status',
    ];

    /** @var array<string, array<string, string>> */
    public const FIELD_OPTIONS = [
        'status' => [
            WorkOrderStatus::DRAFT => 'Draf',
            WorkOrderStatus::DIJADWALKAN => 'Dijadwalkan',
            WorkOrderStatus::DIKERJAKAN => 'Dikerjakan',
            WorkOrderStatus::SELESAI => 'Selesai',
            WorkOrderStatus::DITUTUP => 'Ditutup',
            WorkOrderStatus::DIBATALKAN => 'Dibatalkan',
        ],
    ];

    /** @var array<string, string> Resource pemilih untuk kolom rujukan. */
    public const FIELD_LOOKUPS = [
        'responsible_org_unit_id' => 'reference-data/unit-kerja',
        'tipe_work_order_id' => 'tipe-work-order',
        'tingkat_layanan_id' => 'tingkat-layanan',
        'penanggung_jawab_user_id' => 'reference-data/anggota',
    ];

    /**
     * Kolom yang sengaja tidak ditawarkan sebagai filter, dengan alasannya.
     *
     * Jadwal disimpan persis seperti diketik pengguna, tanpa zona (lihat `WorkOrderList`), sedangkan filter
     * tanggal-jam membaca kolomnya sebagai UTC; menawarkannya membuat batas harinya bergeser sebesar selisih
     * zona pengguna.
     *
     * @var array<string, string>
     */
    public const FIELD_HIDDEN = [
        'legal_entity_id' => 'Badan hukum dipilih lewat workspace, bukan filter laporan.',
        'diharapkan_mulai' => 'Jadwal tersimpan tanpa zona; filter tanggal-jam membacanya sebagai UTC.',
        'diharapkan_selesai' => 'Jadwal tersimpan tanpa zona; filter tanggal-jam membacanya sebagai UTC.',
        'dijadwalkan_mulai' => 'Jadwal tersimpan tanpa zona; filter tanggal-jam membacanya sebagai UTC.',
        'dijadwalkan_selesai' => 'Jadwal tersimpan tanpa zona; filter tanggal-jam membacanya sebagai UTC.',
    ];

    protected $table = 'aset_tr_pemeliharaan_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id',
        'tipe_work_order_id', 'tingkat_layanan_id', 'keterangan', 'penanggung_jawab_user_id',
        'diharapkan_mulai', 'diharapkan_selesai', 'dijadwalkan_mulai', 'dijadwalkan_selesai',
        'aktual_mulai', 'aktual_selesai', 'status', 'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diharapkan_mulai' => 'datetime',
            'diharapkan_selesai' => 'datetime',
            'dijadwalkan_mulai' => 'datetime',
            'dijadwalkan_selesai' => 'datetime',
            'aktual_mulai' => 'datetime',
            'aktual_selesai' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<PemeliharaanAsetDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(PemeliharaanAsetDetail::class, 'pemeliharaan_aset_id');
    }

    /** @return HasMany<PemeliharaanAsetStatusLog, $this> */
    public function statusLog(): HasMany
    {
        return $this->hasMany(PemeliharaanAsetStatusLog::class, 'pemeliharaan_aset_id');
    }
}
