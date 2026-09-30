<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use App\Support\Modules\Contracts\MilikTenant;
use App\Support\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Baris pekerjaan work order: satu aset, satu jenis pekerjaan, satu pelaksana.
 *
 * Berbeda dari baris detail transaksi lain di modul ini, baris ini TIDAK boleh diganti dengan
 * pola hapus-lalu-sisip saat dokumen disunting: ia memikul hasil checklist, jam aktual,
 * penugasan, sebab, dan tindakan. Penggantian massal hanya sah selama status masih `draft`.
 *
 * `lokasi_aset_id` disalin saat baris dibuat, bukan dibaca dari aset, supaya riwayat tetap
 * menunjukkan tempat pekerjaan dikerjakan meski asetnya kemudian dipindahkan.
 *
 * `estimasi_jam` dan `aktual_jam` di-cast `decimal:2`, jadi Eloquent memulangkannya sebagai
 * string, bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pemeliharaan_aset_id
 * @property int $line_number
 * @property string $aset_id
 * @property ?string $lokasi_aset_id
 * @property string $maintenance_job_type_id
 * @property ?string $variant_id
 * @property ?string $trade_id
 * @property ?string $ditugaskan_ke_user_id
 * @property ?Carbon $dijadwalkan_mulai
 * @property ?Carbon $dijadwalkan_selesai
 * @property ?string $estimasi_jam
 * @property ?string $aktual_jam
 * @property ?string $hasil
 * @property ?string $sebab_kerusakan_id
 * @property ?string $sebab_kerusakan_keterangan
 * @property ?string $tindakan_perbaikan_id
 * @property ?string $tindakan_perbaikan_keterangan
 * @property ?string $catatan
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class PemeliharaanAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'ditugaskan_ke_user_id' => DataClass::EndUserPseudonymousIdentifiers,
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
        'lokasi_aset_id' => 'Lokasi',
        'maintenance_job_type_id' => 'Jenis pekerjaan',
        'variant_id' => 'Varian pekerjaan',
        'trade_id' => 'Bidang keahlian',
        'ditugaskan_ke_user_id' => 'Ditugaskan ke',
        'estimasi_jam' => 'Estimasi jam',
        'aktual_jam' => 'Jam aktual',
        'hasil' => 'Hasil',
        'sebab_kerusakan_id' => 'Sebab kerusakan',
        'sebab_kerusakan_keterangan' => 'Keterangan sebab kerusakan',
        'tindakan_perbaikan_id' => 'Tindakan perbaikan',
        'tindakan_perbaikan_keterangan' => 'Keterangan tindakan perbaikan',
        'catatan' => 'Catatan',
    ];

    /** @var array<string, array<string, string>> `hasil` dihitung dari checklist saat hasil pekerjaan disimpan. */
    public const FIELD_OPTIONS = [
        'hasil' => ['lulus' => 'Lulus', 'gagal' => 'Gagal', 'tidak_dinilai' => 'Tidak dinilai', 'tidak_berlaku' => 'Tidak berlaku'],
    ];

    /** @var array<string, string> Resource pemilih untuk kolom rujukan. */
    public const FIELD_LOOKUPS = [
        'aset_id' => 'aset',
        'lokasi_aset_id' => 'lokasi-aset',
        'maintenance_job_type_id' => 'maintenance-job-types',
        'variant_id' => 'maintenance-job-type-variants',
        'trade_id' => 'trade',
        'ditugaskan_ke_user_id' => 'reference-data/anggota',
        'sebab_kerusakan_id' => 'sebab-kerusakan',
        'tindakan_perbaikan_id' => 'tindakan-perbaikan',
    ];

    /** @var array<string, string> Kolom yang sengaja tidak ditawarkan sebagai filter, dengan alasannya. */
    public const FIELD_HIDDEN = [
        'pemeliharaan_aset_id' => 'Kunci dokumen induk; saring lewat bagian Work order.',
        'dijadwalkan_mulai' => 'Jadwal tersimpan tanpa zona; filter tanggal-jam membacanya sebagai UTC.',
        'dijadwalkan_selesai' => 'Jadwal tersimpan tanpa zona; filter tanggal-jam membacanya sebagai UTC.',
    ];

    protected $table = 'aset_tr_pemeliharaan_aset_details';

    protected $fillable = [
        'tenant_id', 'pemeliharaan_aset_id', 'line_number', 'aset_id', 'lokasi_aset_id',
        'maintenance_job_type_id', 'variant_id', 'trade_id', 'ditugaskan_ke_user_id',
        'dijadwalkan_mulai', 'dijadwalkan_selesai', 'estimasi_jam', 'aktual_jam', 'hasil',
        'sebab_kerusakan_id', 'sebab_kerusakan_keterangan',
        'tindakan_perbaikan_id', 'tindakan_perbaikan_keterangan', 'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'dijadwalkan_mulai' => 'datetime',
            'dijadwalkan_selesai' => 'datetime',
            'estimasi_jam' => 'decimal:2',
            'aktual_jam' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PemeliharaanAset, $this> */
    public function pemeliharaan(): BelongsTo
    {
        return $this->belongsTo(PemeliharaanAset::class, 'pemeliharaan_aset_id');
    }

    /** @return HasMany<PemeliharaanAsetChecklist, $this> */
    public function checklist(): HasMany
    {
        return $this->hasMany(PemeliharaanAsetChecklist::class, 'pemeliharaan_aset_detail_id');
    }
}
