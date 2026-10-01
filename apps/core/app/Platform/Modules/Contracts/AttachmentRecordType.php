<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/**
 * Satu jenis record yang boleh diberi lampiran dokumen (gap 7, K-09), didaftarkan pemilik tabelnya.
 *
 * Lampiran semua record disimpan Core di satu tabel, `document_attachments`, seperti `Document Attachment`
 * di Business Central. Yang tidak dipegang Core adalah hak atas record induknya: Core tidak pernah membaca
 * tabel module, jadi ia bertanya kepada pemilik tabel lewat kontrak ini. Pemiliknya menjawab dengan aturan
 * yang sama dengan saat record itu dibuka atau diubah di layarnya sendiri: permission dan kebijakan
 * organisasinya. Karena itu lampiran tidak pernah lebih terbuka daripada record-nya.
 *
 * Module mendaftarkannya ke {@see AttachmentRecordTypes} dari penyedia layanannya. Sebelum bertanya, Core
 * memasang konteks module yang disebut {@see self::moduleId()} pada permintaan itu, sama seperti rute module
 * sendiri, sehingga `KonteksPermintaan`, kebijakan data, dan penyaringan tenant model module berlaku.
 */
interface AttachmentRecordType
{
    /** Nama tabel record induk, misalnya `aset_tr_aset`. Kunci pendaftaran dan nilai `record_type`. */
    public function recordType(): string;

    /** Id module pemilik tabel, yang konteksnya dipasang sebelum bertanya; `null` untuk tabel milik Core. */
    public function moduleId(): ?string;

    /**
     * Klasifikasi isi lampiran record jenis ini, mengikuti induknya: lampiran pekerja adalah data pribadi.
     * Disalin ke setiap baris lampiran saat diunggah.
     */
    public function dataClass(): DataClass;

    /** Apakah pengguna permintaan ini boleh membuka record itu. Record yang tidak ada atau diarsipkan: false. */
    public function canRead(string $tenantId, string $recordId): bool;

    /** Apakah pengguna permintaan ini boleh mengubah record itu, termasuk melampirkan dan mengarsipkan lampirannya. */
    public function canChange(string $tenantId, string $recordId): bool;

    /**
     * Apakah dokumen itu punya baris bernomor ini, untuk lampiran yang menempel ke baris dokumen, seperti
     * `Line No.` di BC. Jenis record tanpa baris selalu menjawab false.
     */
    public function hasLine(string $tenantId, string $recordId, int $lineNumber): bool;
}
