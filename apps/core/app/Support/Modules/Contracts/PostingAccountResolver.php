<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Pemetaan akun milik module, dibaca ulang Core saat posting yang belum sampai ke pembaca dibentuk
 * ulang (Validasi ulang dan penilaian ulang cutover).
 *
 * Module menyusun jurnalnya dengan akun dari pemetaannya sendiri — posting group aset, misalnya —
 * lalu menyerahkan `account_id` hasilnya di masukan posting. Masukan itu yang disimpan dan dibentuk
 * ulang. Tanpa jalan kembali ke pemetaan, posting yang tertahan karena kolom pemetaannya kosong
 * (`ACCOUNT_NOT_MAPPED`) tidak pernah lepas lewat Validasi ulang: pengguna mengisi posting group,
 * tetapi masukan yang tersimpan tetap menyebut akun kosong.
 *
 * Karena itu setiap baris boleh membawa `mapping.reference`, kunci pemetaan dalam bahasa module
 * sendiri (misalnya `posting-group:01J…:acquisition_account_id`). Saat membentuk ulang, Core
 * menanyakan kunci itu ke module pemilik dokumen sumbernya dan memakai akun yang berlaku sekarang.
 * Padanannya source document framework F&O: distribusi akuntansi diturunkan ulang dari setelan yang
 * berlaku selama jurnalnya belum ditransfer ke buku besar.
 *
 * **Hanya akun yang dibaca ulang.** Nilai, unit organisasi, dan susunan baris tetap milik posting
 * yang terbit: nilainya sudah dibulatkan dengan presisinya sendiri (K-20), dan menyusun ulang
 * nilainya berarti jurnal yang berbeda dari dokumen yang sudah disetujui pengguna.
 *
 * Module mendaftarkan pelaksananya ke {@see PostingAccountResolvers} saat boot, sama seperti
 * penyedia laporannya.
 */
interface PostingAccountResolver
{
    /** Id module pemilik dokumen sumber, sama dengan `source_document.module`. */
    public function moduleId(): string;

    /**
     * Akun yang dipetakan kunci itu pada tanggal posting, atau `null` bila pemetaannya masih kosong.
     *
     * Dipanggil dengan tenant posting sebagai tenant aktif, jadi model module menyaring seperti
     * biasa. Kunci yang tidak dikenali module juga dijawab `null`: posting tetap tertahan dengan
     * masalah yang terlihat, bukan diam-diam memakai akun lama.
     */
    public function account(string $tenantId, string $reference, string $postingDate): ?string;
}
