<?php

declare(strict_types=1);

namespace App\Support\Finance;

/**
 * Masukan penerbit posting yang sudah diperiksa bentuknya dan dinormalkan.
 *
 * Nilai uang sudah berupa string berskala persis presisi mata uang, tanggal sudah `Y-m-d`, dan
 * `occurredAt` sudah ISO 8601 dengan offset aslinya. `hash` menangkap isi akuntansinya — jenis,
 * entitas, tanggal, vendor, dan baris jurnal — untuk mengenali `posting_id` yang diterbitkan ulang
 * dengan isi berbeda.
 */
final readonly class PostingInput
{
    /**
     * @param  array{id: string, number: string, name: string}|null  $vendor
     * @param  array{module: string, type: string, number: ?string, description: ?string, id: ?string}  $sourceDocument
     * @param  list<array{line_no: int, account_id: ?string, debit: string, credit: string, description: ?string, org_unit_id: ?string, mapping: ?array{label: string, fix_url: ?string}}>  $lines
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $tenantId,
        public string $postingId,
        public string $postingType,
        public string $legalEntityId,
        public ?string $legalEntityCode,
        public string $currencyCode,
        public int $decimals,
        public string $postingDate,
        public string $documentDate,
        public string $occurredAt,
        public ?string $settlementMode,
        public ?array $vendor,
        public ?string $vendorInvoiceReference,
        public array $sourceDocument,
        public ?string $reversesPostingId,
        public ?string $adjustsPostingId,
        public array $lines,
        public array $details,
        public string $total,
        public string $hash,
    ) {}
}
