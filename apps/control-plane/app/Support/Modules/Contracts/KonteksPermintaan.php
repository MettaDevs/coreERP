<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Pengguna, izin, dan kebijakan data yang berlaku pada permintaan ini.
 *
 * Antarmuka ini melengkapi `KonteksTenant`, bukan menggantikannya. Pembagiannya bukan
 * selera: tenant dan batas organisasi adalah **tempat** sebuah query boleh membaca, sedang
 * izin dan kebijakan data adalah **apa** yang boleh dilakukan pengguna di tempat itu. Dua
 * pertanyaan berbeda, dua pintu berbeda, dan tidak ada satu pun jawaban yang punya dua
 * sumber — dua sumber untuk satu jawaban pasti menyimpang cepat atau lambat.
 *
 * Sumbernya adalah atribut permintaan yang ditulis middleware konteks module. Kunci atribut
 * itu sengaja sama persis dengan yang dipakai app lama saat masih memverifikasi token,
 * karena 22 berkas pada modul aset membacanya langsung; mengganti bentuknya berarti
 * mengubah 22 berkas tanpa alasan.
 */
interface KonteksPermintaan
{
    /** Melempar bila konteks module belum terpasang. Tidak pernah mengembalikan tebakan. */
    public function penggunaId(): string;

    /**
     * Kode izin yang dipegang pengguna **untuk module ini saja**.
     *
     * @return list<string>
     */
    public function izin(): array;

    /**
     * Gagal menutup: tanpa konteks, jawabannya `false`, bukan `true`.
     */
    public function punyaIzin(string $kode): bool;

    /**
     * Lingkup kebijakan data per kode kebijakan, sebagaimana disusun Core.
     *
     * Bentuk tiap nilai: `array{all: bool, scope_grants: list<array{legal_entity_id: ?string,
     * operating_unit_ids: list<string>}>}`. Module membacanya sebagai data biasa; ia tidak
     * pernah menerima objek Core.
     *
     * @return array<string, mixed>
     */
    public function kebijakanData(): array;
}
