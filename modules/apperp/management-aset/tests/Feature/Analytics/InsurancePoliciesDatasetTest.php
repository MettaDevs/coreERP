<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksTimeZoneBuckets;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.insurance-policies` terhadap layar daftar polis (`GET polis-asuransi`): polis
 * milik entitas legal tanpa unit, jadi hibah pada entitas legalnya cukup, unit mana pun. Polis di entitas
 * legal lain tidak terlihat oleh pengguna yang tidak punya hibah di sana.
 */
class InsurancePoliciesDatasetTest extends TestCase
{
    use ChecksTimeZoneBuckets, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.insurance-policies';
    }

    protected function readPermission(): string
    {
        return 'management-aset.polis-asuransi.read';
    }

    protected function listResource(): string
    {
        return 'polis-asuransi';
    }

    protected function dimensions(): array
    {
        return ['kode'];
    }

    /** Hibah unit A dan unit B sama-sama menjangkau dua polis entitas legal itu; yang ketiga milik entitas lain. */
    protected function expectedRows(): array
    {
        return ['all' => 3, 'unitA' => 2, 'unitB' => 2];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $otherLegalEntity = $this->organization($tenant, 'legal_entity', 'PT Entitas Lain '.$tag);

        $this->policy($tenant, $legalEntity, "{$tag}-POL1", '2026-01-01', '2026-12-31');
        $this->policy($tenant, $legalEntity, "{$tag}-POL2", '2026-02-01', '2027-01-31', true);
        $this->policy($tenant, $otherLegalEntity, "{$tag}-POL3", '2026-03-01', '2026-12-31');
    }

    protected function timeField(): string
    {
        return 'berlaku_sampai';
    }

    protected function timeKind(): string
    {
        return 'date';
    }

    /** @return array<string, string|list<string>> */
    protected function insertBoundaryRow(string $value): array
    {
        $this->policy($this->tenantId, $this->legalEntity, 'BATAS', '2026-01-01', $value);

        return ['kode' => 'BATAS'];
    }

    public function test_blocked_policies_are_counted_apart_and_only_where_the_user_may_see_them(): void
    {
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count', 'blocked']])
            ->assertOk()->assertJsonPath('rows.0.count', 3)->assertJsonPath('rows.0.blocked', 1);

        // Polis yang diblokir ada di entitas legal pengguna ini, jadi terlihat; tanpa hibah tidak terlihat.
        $grantee = $this->member($this->readPermission(), [[$this->legalEntity, $this->unitA]]);
        $this->analyze($grantee, ['dataset' => $this->datasetCode(), 'measures' => ['blocked']])->assertOk()->assertJsonPath('rows.0.blocked', 1);
        $this->analyze($this->member($this->readPermission()), ['dataset' => $this->datasetCode(), 'measures' => ['blocked']])
            ->assertOk()->assertJsonPath('rows.0.blocked', 0);
    }

    public function test_policies_can_be_counted_by_their_end_date(): void
    {
        $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'measures' => ['count'], 'time_range' => ['field' => 'berlaku_sampai', 'range' => '31/12/2026'],
        ])->assertOk()->assertJsonPath('rows.0.count', 2);
    }

    private function policy(string $tenant, string $legalEntity, string $code, string $from, string $until, bool $blocked = false): void
    {
        DB::table('aset_m_polis_asuransi')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $code,
            'legal_entity_id' => $legalEntity, 'nama' => 'Polis '.$code, 'nomor_polis' => 'NP-'.$code,
            'berlaku_mulai' => $from, 'berlaku_sampai' => $until, 'diblokir' => $blocked, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
