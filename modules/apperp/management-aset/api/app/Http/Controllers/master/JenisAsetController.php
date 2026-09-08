<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\JenisAset;
use App\Support\MasterChild;

/**
 * Sumbu klasifikasi teknis; padanan "Asset type" di Dynamics 365 F&O. Datar dan tanpa
 * induk, sehingga tenant yang hanya mengenal satu tingkat klasifikasi tetap terlayani.
 *
 * Jumlah turunan (berapa atribut, model, dan aset yang memakai jenis ini) sengaja tidak
 * disajikan di sini. Menaruhnya pada respons master berarti setiap baris daftar ikut
 * menghitungnya, dan berarti pemegang izin baca jenis aset ikut mengetahui isi resource
 * lain yang belum tentu boleh ia lihat. Keduanya ditangani
 * {@see JenisAsetDetailController}, yang hanya dipanggil untuk satu record terbuka.
 */
class JenisAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'jenis-aset';
    }

    protected function model(): string
    {
        return JenisAset::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'm_model_aset', column: 'jenis_aset_id', label: 'model aset'),
            new MasterChild(table: 'tr_penerimaan_aset', column: 'jenis_aset_id', label: 'aset'),
        ];
    }
}
