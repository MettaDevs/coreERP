<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Daftar jenis record yang boleh diberi lampiran dokumen, diisi Core dan penyedia layanan tiap module saat
 * boot (gap 7).
 *
 * Satu benda untuk seluruh proses (`CoreServices::PEMETAAN_TUNGGAL`), supaya pendaftaran dan layanan lampiran
 * memegang daftar yang sama. Tabel yang tidak terdaftar tidak dapat diberi lampiran.
 */
interface AttachmentRecordTypes
{
    /** Melempar bila jenis record itu sudah didaftarkan: satu tabel hanya punya satu pemilik. */
    public function register(AttachmentRecordType $type): void;

    public function for(string $recordType): ?AttachmentRecordType;

    /** @return list<string> */
    public function recordTypes(): array;
}
