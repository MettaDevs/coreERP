<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Menerbitkan posting finance dari module ke feed Core (area 6, K-01..K-23).
 *
 * Module menyusun jurnalnya sendiri — akun dari pemetaannya, nilai yang sudah dibulatkan lewat
 * `PresisiMataUang`, dan unit organisasi sumber dimensi per baris. Core memeriksa, melengkapi
 * salinan nama dan nomor pada saat terbit, lalu menyajikannya ke pembaca.
 *
 * Bentuk masukan:
 *
 * ```php
 * [
 *     'tenant_id' => '01J…',
 *     'posting_id' => 'AST-ACQ-01J…',          // tetap untuk dokumen yang sama, maks. 120 karakter
 *     'posting_type' => 'asset.acquisition',
 *     'legal_entity_id' => '01J…',
 *     'currency_code' => 'IDR',
 *     'posting_date' => '2026-09-28',           // tanggal akuntansi
 *     'document_date' => '2026-09-28',
 *     'occurred_at' => '2026-09-28T23:50:00+07:00', // jam kejadian, wajib dengan offset
 *     'settlement_mode' => 'direct_payable',   // opsional; koreksi mewarisi dari posting asalnya
 *     'requires_vendor' => true,               // opsional; true untuk pembelian direct_payable
 *     'vendor_id' => '01J…',                   // opsional
 *     'vendor_invoice_reference' => null,      // opsional
 *     'source_document' => ['module' => 'management-aset', 'type' => 'penerimaan-aset',
 *                           'number' => 'PNA-2026-09-0007', 'description' => '…', 'id' => '01J…',
 *                           'url' => '/management-aset/inventarisasi-aset/penerimaan/01J…'], // url opsional
 *     'reverses_posting_id' => null,           // opsional, `posting_id` yang dibalik
 *     'adjusts_posting_id' => null,            // opsional, `posting_id` yang dikoreksi
 *     'lines' => [[
 *         'account_id' => '01J…',              // akun referensi (DaftarAkun), null = belum dipetakan
 *         'debit' => '500000000.00',           // string desimal, tidak lebih halus dari presisi
 *         'credit' => '0',
 *         'description' => 'KEND-0012 Ambulans',
 *         'org_unit_id' => '01J…',             // sumber dimensi BUSINESS_UNIT dan DEPARTMENT
 *         'mapping' => ['label' => 'Group KENDARAAN · akun aset', 'fix_url' => '/m/…'], // opsional
 *     ]],
 *     'details' => ['assets' => [...]],        // opsional, hanya informasi
 * ]
 * ```
 *
 * `mapping` menamai asal akun baris itu. Bila akunnya kosong atau nonaktif, masalah yang
 * ditampilkan memakai label itu dan tautan perbaikannya — Core tidak tahu posting group module.
 *
 * `source_document.url` adalah alamat layar dokumen itu di module, dipakai layar pantau posting
 * untuk menautkannya. Alasannya sama: Core tidak tahu alamat layar module. Hanya jalur relatif yang
 * diawali satu `/`; tidak ikut disajikan ke pembaca.
 *
 * Setiap hasil berbentuk:
 *
 * ```php
 * ['posting_id' => '…', 'status' => 'pending|held|manual|posted|rejected',
 *  'problems' => [['line_no' => 1, 'code' => 'ACCOUNT_INACTIVE', 'message' => '…',
 *                  'object' => ['type' => 'account', 'id' => '…', 'label' => '…'],
 *                  'fix' => ['label' => '…', 'url' => '…']]],
 *  'payload' => [...], // bentuk kontrak feed, sama dengan yang disajikan ke pembaca
 *  'created' => true]
 * ```
 */
interface PenerbitPosting
{
    /**
     * Menerbitkan satu posting **di dalam transaksi dokumen sumber pemanggil**. Tidak membuka
     * transaksi sendiri: dokumen yang batal tidak meninggalkan posting, dan posting yang gagal
     * membatalkan dokumennya.
     *
     * Idempoten: `posting_id` yang sudah terbit mengembalikan posting yang ada (`created` false),
     * selama isi jurnalnya sama.
     *
     * @param  array<string, mixed>  $posting
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     *
     * @throws PostingTidakSah Bug penerbit; lihat kelasnya.
     */
    public function terbitkan(array $posting): array;

    /**
     * Pemeriksaan yang sama persis dengan `terbitkan`, tanpa menyimpan apa pun. Untuk pratinjau
     * jurnal dan masalahnya sebelum pengguna mengonfirmasi (K-22).
     *
     * @param  array<string, mixed>  $posting
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     *
     * @throws PostingTidakSah
     */
    public function pratinjau(array $posting): array;

    /**
     * Keadaan satu posting untuk ditampilkan di dokumen sumbernya, atau `null` bila belum terbit.
     *
     * `settlement_mode` adalah mode yang tercatat saat posting itu terbit. Koreksinya memilih akun
     * lawan dari mode ini, bukan dari setelan hari ini (K-10).
     *
     * @return array{posting_id: string, status: string, settlement_mode: ?string, external_reference: ?string, reason_code: ?string, reason: ?string, acknowledged_at: ?string, problems: list<array<string, mixed>>}|null
     */
    public function status(string $tenantId, string $postingId): ?array;
}
