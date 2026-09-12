<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu app di katalog — tabel `apps` milik Core, dibaca saja.
 *
 * Ia hanya dipakai untuk mengisi pilihan "app yang dibeli" pada dialog pelanggan baru. Yang
 * **memutuskan** app mana boleh dibeli tetap Core: ia yang menghitung prerequisite dan menolak
 * yang tidak tersedia saat permintaannya tiba. Daftar di sini cuma supaya operator tidak perlu
 * mengetikkan id dari ingatan.
 *
 * ::: peringatan
 * Katalog adalah tabel **sisi environment**, bukan sisi pusat. Hari ini keduanya berada di satu
 * database, jadi membacanya dari koneksi konsol berhasil. Begitu penyimpanannya dipisah, pembacaan
 * ini yang pertama patah — dan penggantinya sudah jelas: satu endpoint katalog di Core, dibaca
 * lewat jalur HTTP yang sama dengan pembuatan pelanggan. Dicatat di sini supaya patahnya tidak
 * mengejutkan.
 * :::
 *
 * @property string $id
 * @property string $name
 * @property string $status
 */
class Aplikasi extends Model
{
    protected $table = 'apps';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Pilihan app untuk formulir, urut nama.
     *
     * Hanya yang berstatus `available`. Menawarkan app yang belum tersedia berarti membiarkan
     * operator menjanjikannya kepada pelanggan di telepon, lalu Core menolaknya beberapa detik
     * kemudian.
     *
     * @return list<array{id: string, nama: string}>
     */
    public static function pilihan(): array
    {
        return array_values(
            self::query()
                ->where('status', 'available')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (self $a): array => ['id' => $a->id, 'nama' => $a->name])
                ->all()
        );
    }
}
