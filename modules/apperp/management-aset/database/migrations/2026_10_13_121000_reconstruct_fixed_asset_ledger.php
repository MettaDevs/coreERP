<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Apperp\ManagementAset\Services\FixedAssetLedgerBackfill;

return new class extends Migration
{
    public function up(): void
    {
        app(FixedAssetLedgerBackfill::class)->run();
    }

    public function down(): void {}
};
