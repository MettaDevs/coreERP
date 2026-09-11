<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Menerbitkan nomor dokumen.
 *
 * Ini pintu resmi module ke penerbitan nomor Core. Antarmukanya menerima **id**, bukan objek
 * Core: module yang harus mengambil objek sequence lebih dulu justru melanggar batas yang
 * antarmuka ini buat ada.
 *
 * Keuntungan yang membenarkan seluruh pemindahan ke satu runtime ada di sini. Penerbitan
 * berjalan di koneksi yang sama dengan dokumen yang sedang disimpan, jadi ia bisa berada di
 * dalam transaksi dokumen itu: dokumen gagal, nomornya ikut batal, tidak ada lompatan nomor
 * yang harus dijelaskan ke pemeriksa.
 */
interface PenerbitNomor
{
    /**
     * @param  array{tenant_id: string, app_id: string, legal_entity_id?: string|null, org_unit_id?: string|null}  $konteks
     * @return array{id: string, number: string, status: string}
     */
    public function terbitkan(array $konteks, string $kodeReferensi, string $kunciIdempoten, ?string $nilaiManual = null): array;

    /**
     * Menyiapkan nomor tanpa memakainya. Dipakai layar yang menampilkan nomor sebelum
     * pengguna menekan simpan.
     *
     * @param  array{tenant_id: string, app_id: string, legal_entity_id?: string|null, org_unit_id?: string|null}  $konteks
     * @return array{id: string, number: string, status: string}
     */
    public function cadangkan(array $konteks, string $kodeReferensi, string $kunciIdempoten): array;
}
