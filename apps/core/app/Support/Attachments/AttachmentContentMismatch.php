<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use RuntimeException;

/**
 * Isi berkas lampiran di disk tidak sama lagi dengan saat diunggah: hash SHA-256-nya berbeda, atau berkasnya
 * hilang. Berkas seperti itu tidak dikirim ke pengguna; kejadiannya dilaporkan supaya ada yang menelusuri
 * penyimpanannya.
 *
 * Pesannya hanya membawa id lampiran dan kedua hash, tanpa nama berkas: nama berkas bisa berisi nama orang.
 */
final class AttachmentContentMismatch extends RuntimeException
{
    public static function for(string $attachmentId, string $expected, ?string $actual): self
    {
        return new self($actual === null
            ? "Berkas lampiran {$attachmentId} tidak ada di penyimpanan."
            : "Isi berkas lampiran {$attachmentId} tidak cocok dengan hash saat diunggah: tersimpan {$expected}, terbaca {$actual}.");
    }
}
