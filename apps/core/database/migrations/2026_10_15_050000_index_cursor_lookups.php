<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX parties_lookup_cursor ON parties (tenant_id, status, name, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX locations_lookup_cursor ON locations (tenant_id, name, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX organizations_lookup_cursor ON organizations (tenant_id, classification, name, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS parties_lookup_cursor');
        DB::statement('DROP INDEX IF EXISTS locations_lookup_cursor');
        DB::statement('DROP INDEX IF EXISTS organizations_lookup_cursor');
    }
};
