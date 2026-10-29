<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Illuminate\Support\Collection;

interface PlanningYearDirectory
{
    /** @param array<string, mixed> $input
     * @return array{data: Collection<int, \stdClass>, next_cursor: ?string}
     */
    public function lookup(string $tenantId, string $legalEntityId, array $input): array;

    /** @param list<string> $ids
     * @return array<string, array{id: string, legal_entity_id: string, year: int, active: bool, version: int}>
     */
    public function resolve(string $tenantId, string $legalEntityId, array $ids): array;

    /** Pemindahan angka tahun lama tanpa menggandakan master.
     * @return array{id: string, legal_entity_id: string, year: int, active: bool, version: int}
     */
    public function importLegacy(string $tenantId, string $legalEntityId, int $year): array;
}
