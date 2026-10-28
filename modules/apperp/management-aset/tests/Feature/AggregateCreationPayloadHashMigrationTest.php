<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AggregateCreationPayloadHashMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_can_run_when_columns_already_exist(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_10_23_100000_add_aggregate_creation_payload_hashes.php';

        $migration->up();

        $this->assertTrue(Schema::hasColumn('aset_m_pabrikan_aset', 'creation_payload_hash'));
        $this->assertTrue(Schema::hasColumn('aset_m_polis_asuransi', 'creation_payload_hash'));
    }

    public function test_migration_completes_when_only_some_columns_exist(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_10_23_100000_add_aggregate_creation_payload_hashes.php';
        Schema::dropColumns('aset_m_polis_asuransi', 'creation_payload_hash');

        $migration->up();

        $this->assertTrue(Schema::hasColumn('aset_m_pabrikan_aset', 'creation_payload_hash'));
        $this->assertTrue(Schema::hasColumn('aset_m_polis_asuransi', 'creation_payload_hash'));
    }
}
