<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\Support;

use App\Foundation\Vendor\Models\Vendor;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Tenant\Models\TenantMembership;

/** FactBox membaca dataset pemilik, dengan perusahaan aktif, izin, dan kebijakan data pengguna. */
final class VendorActivity
{
    public function __construct(private readonly RunQuery $queries, private readonly QueryParser $parser) {}

    /** @return array<string, mixed> */
    public function forVendor(Vendor $vendor, TenantMembership $membership, string $timezone): array
    {
        $principal = UserPrincipal::fromMembership($membership, $timezone);
        if ($principal->activeLegalEntityId() !== $vendor->legal_entity_id) {
            return ['available' => false, 'message' => 'Pilih perusahaan vendor ini sebagai perusahaan aktif untuk melihat perolehan asetnya.'];
        }

        $base = [
            'dataset' => 'management-aset.asset-receipts',
            'filters' => ['vendor_id' => [$vendor->id], 'legal_entity_id' => [$vendor->legal_entity_id], 'status' => ['selesai']],
        ];

        try {
            $summary = $this->queries->handle($principal, $this->parser->parse([
                ...$base,
                'dimensions' => ['currency_code'],
                'measures' => ['receipt_count', 'quantity', 'completed_value'],
                'limit' => 500,
            ]));
            if ($summary->meta['truncated']) {
                return ['available' => false, 'message' => 'Ringkasan belum dapat ditampilkan lengkap. Persempit data penerimaan melalui laporan aset.'];
            }
            $documents = $this->queries->handle($principal, $this->parser->parse([
                ...$base,
                'dimensions' => ['receipt_id', 'receipt_number', 'received_on', 'currency_code'],
                'measures' => ['quantity', 'completed_value'],
                'sort' => [['key' => 'received_on', 'direction' => 'desc'], ['key' => 'receipt_number', 'direction' => 'desc']],
                'limit' => 10,
            ]));
        } catch (AnalyticsQueryException $exception) {
            if (in_array($exception->status, [403, 404], true)) {
                return ['available' => false, 'message' => 'Ringkasan aset belum tersedia. Aplikasi pengelolaan aset harus terpasang dan Anda perlu akses lihat penerimaan aset.'];
            }

            throw $exception;
        }

        return [
            'available' => true,
            'totals' => $summary->rows,
            'documents' => $documents->rows,
            'generated_at' => $summary->meta['generated_at'],
        ];
    }
}
