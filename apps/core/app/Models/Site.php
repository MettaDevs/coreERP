<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;

/**
 * Penanda sisi pusat untuk tabel `sites`. Core sendiri tidak membacanya.
 *
 * Registry situs dibaca dan ditulis admin.erp, lewat `ControlPlane\Models\Site`. Model ini ada
 * karena daftar tabel sisi pusat diturunkan dari model Core yang memakai `OwnedByControlPlane`:
 * penjaga batas foreign key membacanya begitu, dan pemisahan database kelak pun begitu. Tanpa
 * penanda, `sites.tenant_id -> tenants` terhitung menyeberang batas, padahal kedua tabel itu
 * pindah bersama.
 *
 * Sengaja tanpa kolom yang boleh diisi dan tanpa relasi. Pembaca di Core berarti registry situs
 * punya dua penulis, dan aturannya hanya dijaga di satu tempat.
 */
class Site extends Model
{
    use OwnedByControlPlane;

    protected $table = 'sites';
}
