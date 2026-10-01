<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Urutan nomor module yang lahir dengan nomor awal 0 dan belum pernah menerbitkan nomor, dimulai dari 1.
 *
 * Sampai 1 Oktober 2026 `EnsureNumberSequenceDrafts` membuat draf urutan module dengan `minimum_number`
 * 0, sehingga dokumen pertama setiap module bernomor `XXXX00000`. Bawaannya sudah dibetulkan menjadi 1,
 * tetapi draf hanya dibuat sekali per tenant dan module, jadi tenant yang sudah memasang module tidak
 * pernah menerimanya lewat jalur itu. Karena itu perbaikannya dikirim sebagai migration data.
 *
 * ## Yang disentuh dan yang tidak
 *
 * Hanya urutan milik module (bukan `core`, yang sejak lahir memakai 1) dengan nomor awal 0 yang **belum
 * pernah menerbitkan nomor**. Penentunya adalah tidak adanya satu pun baris di tabel yang mencatat nomor
 * yang sudah keluar atau sudah dipotong dari counter:
 *
 * - `number_sequence_issues`: nomor yang sudah terbit. Tidak pernah dihapus, termasuk oleh retensi.
 * - `number_sequence_reservations`: nomor continuous yang sudah dipegang app, apa pun statusnya. Yang
 *   dibatalkan pun pernah dipegang dan mungkin sudah tampil di layar.
 * - `number_sequence_allocations` dan `number_sequence_continuous_pool`: blok yang sudah dipotong dari
 *   counter. Keduanya selalu lahir dalam transaksi yang sama dengan penerbitan atau reservasinya, jadi
 *   tanpa issue atau reservasi seharusnya kosong; diperiksa juga supaya keadaan yang tak terduga dibiarkan.
 * - `number_sequence_reusable_numbers`: nomor yang dikembalikan, hanya ada sesudah reservasi.
 *
 * Counter boleh ada (misalnya dibuat lalu tidak terpakai): yang `next_number`-nya 0 ikut dinaikkan ke 1,
 * yang lebih besar adalah pilihan admin lewat "Lompati ke nomor" dan dibiarkan.
 *
 * Urutan yang sudah menerbitkan nomor 0 **tidak diubah**. Nomor itu sudah tercatat di dokumen, dan
 * mengubah nomor awalnya tidak mengubah counter yang sudah berjalan; yang berubah justru counter scope
 * atau periode baru, sehingga satu urutan memakai dua aturan berbeda.
 *
 * Kolom `version` naik sendiri lewat trigger, jadi form Atur nomor yang terbuka sebelum migration ini
 * ditolak saat menyimpan alih-alih menimpa nomor awal kembali menjadi 0.
 *
 * Aman dijalankan ulang: urutan yang sudah dipindahkan nomor awalnya bukan 0 lagi dan tidak terpilih.
 */
return new class extends Migration
{
    /** Tabel yang menandai bahwa sebuah nomor sudah keluar atau sudah dipotong dari counter. */
    private const NUMBERING_STATE_TABLES = [
        'number_sequence_issues',
        'number_sequence_reservations',
        'number_sequence_allocations',
        'number_sequence_continuous_pool',
        'number_sequence_reusable_numbers',
    ];

    /** Id app milik Core sendiri; urutannya sejak awal mulai dari 1. */
    private const CORE_APP_ID = 'core';

    public function up(): void
    {
        DB::transaction(function (): void {
            $sequenceIds = DB::table('tenant_number_sequences as sequences')
                ->join('app_number_sequence_references as references', 'references.id', '=', 'sequences.reference_id')
                ->where('sequences.minimum_number', 0)
                ->where('references.app_id', '<>', self::CORE_APP_ID)
                ->where(function ($query): void {
                    foreach (self::NUMBERING_STATE_TABLES as $table) {
                        $query->whereNotExists(fn ($state) => $state->select(DB::raw(1))
                            ->from($table)
                            ->whereColumn($table.'.sequence_id', 'sequences.id'));
                    }
                })
                ->lockForUpdate()
                ->pluck('sequences.id');

            foreach ($sequenceIds->chunk(500) as $chunk) {
                DB::table('tenant_number_sequences')->whereIn('id', $chunk)->update([
                    'minimum_number' => 1,
                    'updated_at' => now(),
                ]);
                DB::table('number_sequence_counters')->whereIn('sequence_id', $chunk)->where('next_number', 0)->update([
                    'next_number' => 1,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Perbaikan data satu arah. Mengembalikan nomor awal ke 0 sesudah nomor pertama terbit dari 1
        // akan menerbitkan nomor 0 di scope atau periode baru, jadi rollback tidak menulis ulang apa pun.
    }
};
