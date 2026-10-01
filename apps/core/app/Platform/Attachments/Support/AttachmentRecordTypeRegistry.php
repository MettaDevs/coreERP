<?php

declare(strict_types=1);

namespace App\Platform\Attachments\Support;

use App\Platform\Modules\Contracts\AttachmentRecordType;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use LogicException;

/**
 * Jenis record yang boleh diberi lampiran di proses ini, berkunci nama tabel.
 *
 * Diikat sebagai satu benda (`CoreServices::PEMETAAN_TUNGGAL`), supaya pendaftaran dari penyedia layanan
 * module dan layanan lampiran memegang daftar yang sama.
 */
final class AttachmentRecordTypeRegistry implements AttachmentRecordTypes
{
    /** @var array<string, AttachmentRecordType> */
    private array $types = [];

    public function register(AttachmentRecordType $type): void
    {
        $recordType = $type->recordType();
        // Pendaftaran kedua untuk tabel yang sama berarti dua pemilik menjawab hak atas satu record; yang
        // terakhir mendaftar akan menang diam-diam.
        if (isset($this->types[$recordType]) && $this->types[$recordType] !== $type) {
            throw new LogicException("Jenis record lampiran {$recordType} sudah didaftarkan.");
        }

        $this->types[$recordType] = $type;
    }

    public function for(string $recordType): ?AttachmentRecordType
    {
        return $this->types[$recordType] ?? null;
    }

    /** @return list<string> */
    public function recordTypes(): array
    {
        return array_keys($this->types);
    }
}
