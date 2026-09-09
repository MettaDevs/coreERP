<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Mengajukan dokumen ke alur persetujuan.
 *
 * Mesin Core menerima objek tipe dan versi. Antarmuka ini menerima kode tipe, dan
 * pembungkusnya yang mencari versi yang berlaku.
 *
 * **Kenapa pengaju dan korelasi menjadi parameter tersendiri, bukan kunci di dalam `$data`.**
 * Versi pertama antarmuka ini hanya punya `$data` yang diteruskan apa adanya ke mesin, dan
 * dua kunci di dalamnya menentukan hal yang tidak boleh ditentukan secara kebetulan:
 *
 * - Tanpa `initiator_membership_id`, kolom pengaju terisi `null`, dan penjaga "pengaju tidak
 *   dapat menyetujui dokumennya sendiri" di dalam mesin berhenti berlaku — tanpa kesalahan,
 *   tanpa catatan, hanya sebuah persetujuan yang seharusnya ditolak.
 * - Tanpa korelasi, mesin memakai id instance sebagai korelasi, dan module kehilangan
 *   satu-satunya benang yang mengikat keputusan berhari-hari kemudian kembali ke dokumennya.
 *
 * Keduanya kunci opsional pada sebuah array: yang lupa mengisinya tidak pernah diberi tahu.
 * Sebagai parameter wajib, yang lupa tidak bisa memanggil sama sekali. Itu bedanya penjaga
 * yang bekerja dari penjaga yang berharap.
 *
 * **Pengaju disebut dengan id pengguna, bukan id keanggotaan.** Keanggotaan tenant milik
 * Core; module tidak pernah membacanya dan tidak punya cara mendapatkan idnya tanpa
 * menyentuh tabel Core. Yang dipegang module adalah id pengguna dari konteks permintaan,
 * dan Core yang menerjemahkannya menjadi keanggotaan aktif pada tenant tersebut.
 */
interface MesinWorkflow
{
    /**
     * @param  array{legal_entity_id?: ?string, source_document_type: string, source_document_id: string, decision_context: array<string, mixed>}  $data
     * @return array{id: string, status: string, terulang: bool}
     *                                                           `terulang` benar bila kunci idempoten yang sama sudah pernah diajukan; instance yang
     *                                                           dipulangkan adalah instance yang lama, dan tidak ada yang dibuat.
     */
    public function ajukan(
        string $tenantId,
        string $appId,
        string $kodeTipe,
        string $idPenggunaPengaju,
        string $idKorelasi,
        string $kunciIdempoten,
        array $data,
    ): array;
}
