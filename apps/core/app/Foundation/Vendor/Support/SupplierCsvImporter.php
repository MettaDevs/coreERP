<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\Support;

use App\Foundation\Vendor\Actions\SaveVendor;
use App\Foundation\Vendor\Models\Vendor;
use App\Platform\AddressBook\Models\Party;
use App\Platform\Organization\Models\Organization;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Impor awal manual; perubahan identitas vendor existing wajib ditinjau terpisah. */
final class SupplierCsvImporter
{
    public const HEADER = ['source', 'external_id', 'code', 'name', 'active'];

    /** @return list<array<string, string>> */
    public function parse(string $contents): array
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'Simpan CSV dengan encoding UTF-8.']);
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $first = strtok($contents, "\r\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        try {
            if (fgetcsv($stream, null, $delimiter, '"', '') !== self::HEADER) {
                throw ValidationException::withMessages(['file' => 'Gunakan CSV dari Unduh Supplier di Setup BO.']);
            }
            $rows = [];
            $seen = [];
            $names = [];
            $source = null;
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if ($values === [null]) { continue; }
                $line = count($rows) + 2;
                if (count($values) !== count(self::HEADER) || count($rows) >= 5000) {
                    throw ValidationException::withMessages(['file' => "Baris {$line}: kolom tidak lengkap atau melebihi 5.000 supplier."]);
                }
                $row = array_combine(self::HEADER, array_map('trim', $values));
                $nameKey = Party::searchName($row['name']);
                if ($row['source'] === '' || mb_strlen($row['source']) > 256
                    || ! preg_match('/^[1-9][0-9]{0,18}$/D', $row['external_id'])
                    || $row['code'] === '' || mb_strlen($row['code']) > 40
                    || $row['name'] === '' || mb_strlen($row['name']) > 200
                    || preg_match('/[\x00-\x1F\x7F]/', implode('', $row))
                    || ! in_array($row['active'], ['0', '1'], true)
                    || ($source !== null && $source !== $row['source'])
                    || isset($seen[$row['external_id']]) || isset($names[$nameKey])) {
                    throw ValidationException::withMessages(['file' => "Baris {$line}: identitas/status tidak valid, duplikat, atau sumber BO bercampur."]);
                }
                $source = $row['source'];
                $seen[$row['external_id']] = true;
                $names[$nameKey] = true;
                $rows[] = $row;
            }
            if ($rows === []) { throw ValidationException::withMessages(['file' => 'Berkas tidak berisi supplier.']); }
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function run(TenantMembership $actor, string $entity, string $contents, bool $apply): array
    {
        $rows = $this->parse($contents);
        if (! Organization::query()->where('tenant_id', $actor->tenant_id)->where('classification', 'legal_entity')->whereKey($entity)->exists()) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Pilih entitas legal milik tenant ini.']);
        }
        return DB::transaction(function () use ($actor, $entity, $rows, $apply): array {
            if ($apply) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['supplier-csv|'.$actor->tenant_id.'|'.$entity]);
            }
            $existing = Vendor::query()->with('party')->where('tenant_id', $actor->tenant_id)->where('legal_entity_id', $entity)->get();
            $byKey = $existing->keyBy('creation_key');
            $created = 0;
            $unchanged = 0;
            $pending = [];
            $rejected = [];
            foreach ($rows as $row) {
                $key = 'bo-supplier:'.hash('sha256', $row['source'].'|'.$entity.'|'.$row['external_id']);
                $vendor = $byKey->get($key);
                if ($vendor !== null) {
                    if ($vendor->party->name !== $row['name'] || $vendor->status !== ($row['active'] === '1' ? 'active' : 'inactive')) {
                        $rejected[] = $row['code'].': nama/status berubah; periksa vendor existing '.$vendor->number.' terlebih dahulu.';
                    } else { $unchanged++; }
                    continue;
                }
                $conflict = $existing->first(fn (Vendor $item): bool => Party::searchName($item->party->name) === Party::searchName($row['name']) || $item->number === $row['code']);
                if ($conflict !== null) {
                    $rejected[] = $row['code'].': sudah ada kandidat '.$conflict->number.'; gunakan mapping supplier pada Setup BO.';
                    continue;
                }
                $pending[] = [$row, $key];
                $created++;
            }
            if ($apply && $rejected === []) {
                foreach ($pending as [$row, $key]) {
                    app(SaveVendor::class)->create($actor, [
                        'legal_entity_id' => $entity, 'party_id' => null, 'party_type' => 'organization',
                        'party_name' => $row['name'], 'number' => null, 'tax_number' => null,
                        'status' => $row['active'] === '1' ? 'active' : 'inactive',
                    ], $key);
                }
            }
            return ['status' => $rejected !== [] ? 'rejected' : ($apply ? 'applied' : 'preview'), 'created_count' => $created, 'unchanged_count' => $unchanged, 'rejected' => $rejected];
        });
    }
}
