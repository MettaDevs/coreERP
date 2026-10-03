<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\ScopeFingerprint;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Sidik jari scope (area 4.5), tanpa database dan tanpa Laravel: jangkauan yang sama menghasilkan sidik jari
 * yang sama walau urutannya berbeda, dan setiap hal yang mengubah baris atau label yang terlihat mengubah
 * sidik jarinya. Sidik jari yang terlalu longgar membuat dua pengguna berbeda berbagi cache hasil (area 9),
 * jadi kasus "harus berbeda" di sini sama pentingnya dengan kasus "harus sama".
 */
class ScopeFingerprintTest extends TestCase
{
    private const DATASET = 'contoh.register';

    private const POLICY = 'contoh.tanggung-jawab';

    public function test_the_same_reach_in_another_order_gives_the_same_fingerprint(): void
    {
        $a = self::principal(grants: [['le-1', ['unit-b', 'unit-a']], ['le-2', ['unit-c']]], locked: ['status' => ['b', 'a'], 'kode' => 'X*']);
        $b = self::principal(grants: [['le-2', ['unit-c']], ['le-1', ['unit-a', 'unit-b']]], locked: ['kode' => 'X*', 'status' => ['a', 'b']]);

        $this->assertSame(ScopeFingerprint::of($a, self::DATASET, self::POLICY), ScopeFingerprint::of($b, self::DATASET, self::POLICY));
        $this->assertStringStartsWith('sha256:', ScopeFingerprint::of($a, self::DATASET, self::POLICY));
    }

    public function test_everything_that_changes_visible_rows_or_labels_changes_the_fingerprint(): void
    {
        $base = ScopeFingerprint::of(self::principal(), self::DATASET, self::POLICY);

        $different = [
            'unit lain' => self::principal(grants: [['le-1', ['unit-b']]]),
            'unit tambahan' => self::principal(grants: [['le-1', ['unit-a', 'unit-b']]]),
            'legal entity lain' => self::principal(grants: [['le-2', ['unit-a']]]),
            'jangkauan penuh' => self::principal(all: true, grants: []),
            'tanpa hibah' => self::principal(grants: []),
            'hak data pribadi' => self::principal(personalData: true),
            'saringan terkunci' => self::principal(locked: ['status' => ['aktif']]),
            'saringan terkunci kosong' => self::principal(locked: ['status' => []]),
            'tenant lain' => self::principal(tenant: 'tenant-b'),
        ];

        $seen = [$base => 'dasar'];
        foreach ($different as $case => $principal) {
            $fingerprint = ScopeFingerprint::of($principal, self::DATASET, self::POLICY);
            $this->assertArrayNotHasKey($fingerprint, $seen, $case.' bersidik jari sama dengan '.($seen[$fingerprint] ?? '').'.');
            $seen[$fingerprint] = $case;
        }
    }

    public function test_grants_do_not_matter_for_a_dataset_without_a_data_policy(): void
    {
        $this->assertSame(
            ScopeFingerprint::of(self::principal(grants: [['le-1', ['unit-a']]]), self::DATASET, null),
            ScopeFingerprint::of(self::principal(grants: [['le-2', ['unit-b']]]), self::DATASET, null),
        );
    }

    /**
     * @param  list<array{0: ?string, 1: list<string>}>  $grants
     * @param  array<string, string|list<string>>  $locked
     */
    private static function principal(
        bool $all = false,
        array $grants = [['le-1', ['unit-a']]],
        bool $personalData = false,
        array $locked = [],
        string $tenant = 'tenant-a',
    ): AnalyticsPrincipal {
        $scope = ['all' => $all, 'scope_grants' => array_map(
            static fn (array $grant): array => ['legal_entity_id' => $grant[0], 'operating_unit_ids' => $grant[1]],
            $grants,
        )];

        return new readonly class($tenant, $scope, $personalData, $locked) implements AnalyticsPrincipal
        {
            /**
             * @param  array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}  $scope
             * @param  array<string, string|list<string>>  $locked
             */
            public function __construct(private string $tenant, private array $scope, private bool $personalData, private array $locked) {}

            public function tenantId(): string
            {
                return $this->tenant;
            }

            public function holdsPermission(string $moduleId, string $permission): bool
            {
                return true;
            }

            public function policyScope(string $policyCode): array
            {
                return $this->scope;
            }

            public function mayUsePersonalData(): bool
            {
                return $this->personalData;
            }

            public function lockedFilters(string $dataset): array
            {
                return $this->locked;
            }

            public function timezone(): string
            {
                return 'Asia/Jakarta';
            }

            public function now(): CarbonImmutable
            {
                return CarbonImmutable::now('Asia/Jakarta');
            }

            public function rowLimit(): int
            {
                return 5000;
            }

            public function timeoutMs(): int
            {
                return 8000;
            }

            public function fingerprint(CompiledDataset $dataset): string
            {
                return ScopeFingerprint::of($this, $dataset->code, $dataset->policy['code'] ?? null);
            }

            public function describe(): string
            {
                return 'uji:'.$this->tenant;
            }
        };
    }
}
