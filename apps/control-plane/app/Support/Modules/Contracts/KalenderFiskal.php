<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Menjawab periode fiskal sebuah tanggal.
 *
 * Layanan Core menerima objek entitas legal. Antarmuka ini menerima id-nya, dan pembungkusnya
 * yang menerjemahkan. Bedanya bukan gaya: module yang harus mengambil objek `LegalEntity`
 * lebih dulu sudah menyentuh model Core, dan batasnya kembali kabur.
 */
interface KalenderFiskal
{
    /**
     * Bentuknya dinyatakan penuh, bukan `array<string, mixed>`.
     *
     * Yang dipakai pemanggil bukan seluruh jawaban melainkan tanggal mulai dan selesai tahun
     * bukunya, dan selama bentuknya tidak dinyatakan, pemanggil harus menegaskannya sendiri —
     * penegasan yang tidak diperiksa siapa pun dan tetap hijau walau jawabannya berubah.
     *
     * Kolom `id`, `code`, `name`, dan `ordinal` dibiarkan `mixed`: nilainya atribut model Core
     * apa adanya, dan menyempitkannya di sini berarti menjanjikan sesuatu yang tidak dijamin
     * penyedianya.
     *
     * @return array{
     *     calendar: array{id: mixed, code: mixed, name: mixed},
     *     year: array{id: mixed, name: mixed, starts_on: string, ends_on: string},
     *     period: array{id: mixed, ordinal: mixed, name: mixed, starts_on: string, ends_on: string},
     * }
     */
    public function periode(string $legalEntityId, string $tanggal): array;
}
