<?php

declare(strict_types=1);

namespace App\Foundation\FinancePosting\ModuleServices;

use App\Foundation\FinancePosting\Models\FinanceReferenceAccount;
use App\Platform\Modules\Contracts\AccountDirectory;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RecordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Membaca daftar akun referensi untuk module, sebagai baris biasa, bukan model Core.
 *
 * Setiap pembacaan disaring `tenant_id` yang diberikan pemanggil: id akun milik tenant lain tidak
 * pernah terbaca, sekalipun module mengirim id yang benar-benar ada.
 */
final class AccountDirectoryCore implements AccountDirectory
{
    public function lookup(string $tenantId, ?string $legalEntityId, array $input): array
    {
        $query = FinanceReferenceAccount::query()->where('tenant_id', $tenantId)->where('active', true)
            ->where(fn (Builder $scope) => $legalEntityId === null ? $scope->whereNull('legal_entity_id') : $scope->whereNull('legal_entity_id')->orWhere('legal_entity_id', $legalEntityId));
        $batch = IncrementalList::query(Request::create('/lookup', 'GET', $input), $query->toBase(),
            ['id' => 'id', 'code' => 'code', 'name' => 'name'], ['code', 'name', 'external_id'], 'code', identifierSearchColumns: ['code', 'external_id']);
        $batch['data'] = FinanceReferenceAccount::hydrate($batch['data']->map(fn ($row) => (array) $row)->all())->map(self::row(...));

        return $batch;
    }

    public function search(string $tenantId, ?string $legalEntityId, string $keyword = '', int $limit = 20): array
    {
        $keyword = trim($keyword);

        return array_values(FinanceReferenceAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->where(fn (Builder $query) => $legalEntityId === null
                ? $query->whereNull('legal_entity_id')
                : $query->whereNull('legal_entity_id')->orWhere('legal_entity_id', $legalEntityId))
            ->tap(fn ($query) => RecordSearch::apply($query, ['code', 'name', 'external_id'], $keyword, ['code', 'external_id']))
            ->orderBy('code')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->map(self::row(...))
            ->all());
    }

    public function find(string $tenantId, string $accountId): ?array
    {
        $account = FinanceReferenceAccount::query()->where('tenant_id', $tenantId)->find($accountId);

        return $account === null ? null : self::row($account);
    }

    public function findMany(string $tenantId, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return FinanceReferenceAccount::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_values(array_unique($accountIds)))
            ->get()
            ->mapWithKeys(fn (FinanceReferenceAccount $account): array => [$account->id => self::row($account)])
            ->all();
    }

    /** @return array{id: string, external_id: string, code: string, name: string, type: string, active: bool, legal_entity_id: ?string} */
    private static function row(FinanceReferenceAccount $account): array
    {
        return [
            'id' => $account->id,
            'external_id' => $account->external_id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'active' => $account->active,
            'legal_entity_id' => $account->legal_entity_id,
        ];
    }
}
