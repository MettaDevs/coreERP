<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\Master;

use App\Platform\Modules\Contracts\DataClass;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property ?string $creation_payload_hash
 * @property string $nomor_prakualifikasi
 * @property string $tanggal_prakualifikasi
 * @property string $vendor_id
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
 * @property int $version
 */
class VendorPrequalification extends MasterData
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_vendor' => DataClass::CustomerContent,
        'nama_pic' => DataClass::EndUserIdentifiableInformation,
        'npwp_ktp' => DataClass::EndUserIdentifiableInformation,
        'telepon' => DataClass::EndUserIdentifiableInformation,
        'email' => DataClass::EndUserIdentifiableInformation,
        'alamat' => DataClass::EndUserIdentifiableInformation,
    ];

    protected $table = 'procurement_prakualifikasi';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'creation_payload_hash',
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
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<VendorPrequalificationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VendorPrequalificationDocument::class, 'prakualifikasi_id')->orderBy('urutan');
    }

    /**
     * @return HasMany<VendorPrequalificationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VendorPrequalificationItem::class, 'prakualifikasi_id')->orderBy('urutan');
    }
}
