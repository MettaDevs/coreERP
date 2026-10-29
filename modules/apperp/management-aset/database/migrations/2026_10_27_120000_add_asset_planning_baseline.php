<?php

use App\Platform\Modules\Contracts\FundingSourceDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @kompatibel-mundur Kolom harga hanya dilonggarkan; nama dan tipe angka tetap. Migration lanjutan memulihkan default nol dan menandai estimasi kosong sebelum kode lama membaca data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_perencanaan_aset', function (Blueprint $table): void {
            $table->unsignedInteger('unestimated_line_count')->default(0);
        });
        Schema::table('aset_tr_perencanaan_aset_details', function (Blueprint $table): void {
            $table->date('needed_on')->nullable();
            $table->ulid('funding_source_id')->nullable();
            $table->string('funding_source_name', 250)->nullable();
            $table->softDeletes();
            $table->decimal('estimated_unit_price', 18, 2)->nullable()->default(null)->change();
            $table->decimal('estimated_total_price', 18, 2)->nullable()->default(null)->change();
            $table->index(['tenant_id', 'needed_on']);
            $table->foreign(['tenant_id', 'funding_source_id'], 'asset_plan_line_funding_source_fk')
                ->references(['tenant_id', 'id'])->on('funding_sources')->restrictOnDelete();
        });

        DB::table('aset_tr_perencanaan_aset')->whereNotNull('funding_source')->orderBy('id')->chunkById(100, function ($plans): void {
            foreach ($plans as $plan) {
                if (trim($plan->funding_source) === '') {
                    continue;
                }
                $source = app(FundingSourceDirectory::class)->importLegacy((string) $plan->tenant_id, (string) $plan->funding_source);
                DB::table('aset_tr_perencanaan_aset_details')->where('tenant_id', $plan->tenant_id)->where('planning_id', $plan->id)->update([
                    'funding_source_id' => $source['id'], 'funding_source_name' => $plan->funding_source,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Tidak membuang rincian dan sumber dana yang sudah diisi setelah pembaruan.
    }
};
