<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Klasifikasi data sebuah kolom, nilainya sama persis dengan properti `DataClassification` di Business
 * Central (gap 5, `docs/todo/AnalisaGapCoreErpkeBCPhase1`).
 *
 * - `CustomerContent`: isi bisnis milik tenant, misalnya nama aset, nilai perolehan, catatan dokumen.
 * - `EndUserIdentifiableInformation`: langsung menunjuk orang, misalnya nama, email, telepon, NIK,
 *   NPWP orang, tanggal lahir, alamat, catatan tentang orang, dan catatan medis.
 * - `EndUserPseudonymousIdentifiers`: ID yang hanya menunjuk orang lewat tabel lain, misalnya
 *   `created_by_user_id` atau `membership_id`.
 * - `OrganizationIdentifiableInformation`: menunjuk organisasi, misalnya nama, alamat, dan nomor pajak
 *   perusahaan.
 * - `AccountData`: kredensial dan data akun, misalnya hash token, signing secret, kode undangan.
 * - `SystemMetadata`: data teknis yang dibuat sistem, misalnya status antrean dan log penerapan.
 * - `ToBeClassified`: belum diklasifikasi. Test boundary menolaknya, sama seperti AS0016 di BC.
 */
enum DataClass: string
{
    case CustomerContent = 'CustomerContent';
    case EndUserIdentifiableInformation = 'EndUserIdentifiableInformation';
    case EndUserPseudonymousIdentifiers = 'EndUserPseudonymousIdentifiers';
    case OrganizationIdentifiableInformation = 'OrganizationIdentifiableInformation';
    case AccountData = 'AccountData';
    case SystemMetadata = 'SystemMetadata';
    case ToBeClassified = 'ToBeClassified';
}
