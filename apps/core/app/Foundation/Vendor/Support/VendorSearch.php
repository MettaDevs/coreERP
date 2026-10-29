<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\Support;

use App\Platform\Modules\Contracts\RecordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** Resource hanya menetapkan sumber kandidat dan scope; aturan pencocokan milik engine Core. */
final class VendorSearch
{
    public static function apply(Builder $vendors, string $tenantId, string $query): void
    {
        if (trim(RecordSearch::normalize($query)) === '') {
            return;
        }

        $vendors->whereHas('party', fn (Builder $party) => $party->where('tenant_id', $tenantId));
        RecordSearch::applyCandidates($vendors, $query,
            fn (array $term): QueryBuilder => self::identifierCandidates($tenantId, 'number', $term)
                ->union(self::identifierCandidates($tenantId, 'tax_number', $term))
                ->union(self::nameCandidates($tenantId, $term)),
            fn (array $term): QueryBuilder => self::identifierCandidates($tenantId, 'number', $term)
                ->union(self::identifierCandidates($tenantId, 'tax_number', $term)),
            'vendors.id',
        );
    }

    /** @param array{phrase: bool, text: string} $term */
    private static function identifierCandidates(string $tenantId, string $column, array $term): QueryBuilder
    {
        $query = DB::table('vendors')->where('tenant_id', $tenantId)->select('id');
        RecordSearch::matchTerm($query, $column, $term, true);

        return $query;
    }

    /** @param array{phrase: bool, text: string} $term */
    private static function nameCandidates(string $tenantId, array $term): QueryBuilder
    {
        $parties = DB::table('parties')->where('tenant_id', $tenantId)->whereNull('deleted_at')->select('id');
        RecordSearch::matchTerm($parties, 'name', $term);

        return DB::table('vendors')->where('tenant_id', $tenantId)->whereIn('party_id', $parties)->select('id');
    }
}
