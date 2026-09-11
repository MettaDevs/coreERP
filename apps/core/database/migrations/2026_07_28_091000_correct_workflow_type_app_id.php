<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE workflow_types DROP CONSTRAINT IF EXISTS workflow_types_app_id_foreign');
        DB::statement('ALTER TABLE workflow_types ALTER COLUMN app_id TYPE varchar(80) USING app_id::varchar');
        DB::statement('ALTER TABLE workflow_types ADD CONSTRAINT workflow_types_app_id_foreign FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        // App IDs are string identifiers, so narrowing this column back would corrupt registered workflow types.
    }
};
