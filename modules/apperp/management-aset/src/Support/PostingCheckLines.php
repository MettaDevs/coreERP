<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Baris jurnal payload posting finance dalam bentuk yang dibaca komponen pemeriksaan posting di layar
 * (`PostingCheck`, milik layar pantau posting Core). Satu pemetaan untuk pratinjau penerimaan dan
 * pratinjau "Post penyusutan", supaya keduanya tidak menampilkan jurnal yang sama dengan cara berbeda.
 */
final class PostingCheckLines
{
    /**
     * @param  array<string, mixed>|null  $payload  Payload kontrak hasil `PenerbitPosting`.
     * @return list<array{line_no: int, account_code: ?string, account_name: ?string, description: ?string, debit: string, credit: string, dimensions: list<array{code: string, display_name: ?string, value_code: ?string, value_display_name: ?string}>}>
     */
    public static function from(?array $payload): array
    {
        $lines = $payload['journal_lines'] ?? [];
        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_map(static fn (array $baris): array => [
            'line_no' => (int) $baris['line_no'],
            'account_code' => $baris['account']['code'] ?? null,
            'account_name' => $baris['account']['name'] ?? null,
            'description' => $baris['description'] ?? null,
            'debit' => (string) $baris['debit'],
            'credit' => (string) $baris['credit'],
            'dimensions' => array_values(array_map(static fn (array $dimensi): array => [
                'code' => (string) $dimensi['code'],
                'display_name' => $dimensi['display_name'] ?? null,
                'value_code' => $dimensi['value_code'] ?? null,
                'value_display_name' => $dimensi['value_display_name'] ?? null,
            ], $baris['financial_dimensions'] ?? [])),
        ], $lines));
    }
}
