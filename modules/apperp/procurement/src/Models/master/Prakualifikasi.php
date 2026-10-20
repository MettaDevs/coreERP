<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property string $nomor_prakualifikasi
 * @property string $tanggal_prakualifikasi
 * @property ?string $vendor_id
 * @property string $kode_vendor
 * @property string $nama_vendor
 * @property ?string $jenis_vendor
 * @property ?string $nama_pic
 * @property ?string $npwp_ktp
 * @property ?string $telepon
 * @property ?string $email
 * @property ?string $media_sosial
 * @property ?string $alamat
 * @property string $status_vendor
 * @property string $status_badan_hukum
 * @property string $status_kualifikasi
 * @property ?string $waktu_kesediaan_item
 * @property ?int $jumlah_karyawan
 * @property ?string $catatan
 */
class Prakualifikasi extends MasterData
{
    protected $table = 'procurement_prakualifikasi';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'nomor_prakualifikasi',
        'tanggal_prakualifikasi',
        'vendor_id',
        'kode_vendor',
        'nama_vendor',
        'jenis_vendor',
        'nama_pic',
        'npwp_ktp',
        'telepon',
        'email',
        'media_sosial',
        'alamat',
        'status_vendor',
        'status_badan_hukum',
        'status_kualifikasi',
        'waktu_kesediaan_item',
        'jumlah_karyawan',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_prakualifikasi' => 'date:Y-m-d',
            'jumlah_karyawan' => 'integer',
        ];
    }

    /**
     * @return HasMany<PrakualifikasiDokumen, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PrakualifikasiDokumen::class, 'prakualifikasi_id')->orderBy('urutan');
    }

    /**
     * Alias relasi dokumen.
     *
     * @return HasMany<PrakualifikasiDokumen, $this>
     */
    public function dokumen(): HasMany
    {
        return $this->documents();
    }

    /**
     * @return HasMany<PrakualifikasiItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PrakualifikasiItem::class, 'prakualifikasi_id')->orderBy('urutan');
    }
}
