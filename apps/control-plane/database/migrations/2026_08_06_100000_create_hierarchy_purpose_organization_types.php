<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hierarchy_purpose_organization_types', function (Blueprint $table): void {
            $table->foreignUlid('purpose_id')
                ->constrained('hierarchy_purposes')
                ->cascadeOnDelete();
            $table->string('organization_type', 40);
            $table->primary(['purpose_id', 'organization_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hierarchy_purpose_organization_types');
    }
};
