<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

use InvalidArgumentException;

/**
 * Posting yang tidak mungkin benar, apa pun pemetaannya: jurnal tidak seimbang, baris berisi debit
 * sekaligus kredit, nilai lebih halus dari presisi mata uang, atau `posting_id` yang sama dengan isi
 * berbeda.
 *
 * Ini bug penerbit, bukan keadaan yang diserahkan ke pengguna (K-22). Dilempar dari dalam transaksi
 * dokumen sumber, jadi dokumennya ikut batal, dan pelapor kesalahan yang biasa meneruskannya ke
 * SigNoz. Module boleh menangkapnya untuk menampilkan "dokumen gagal disimpan karena kesalahan
 * sistem", tetapi tidak boleh menelannya lalu menyimpan dokumen tanpa posting.
 *
 * Masalah pemetaan — akun kosong atau nonaktif, unit tanpa nomor — **tidak** dilempar sebagai ini.
 * Posting tetap terbit dengan status `held` beserta daftar masalahnya.
 */
final class PostingTidakSah extends InvalidArgumentException {}
