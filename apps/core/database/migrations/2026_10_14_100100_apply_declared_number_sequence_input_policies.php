<?php

use App\Platform\Modules\Support\ModuleManifestFiles;
use App\Platform\Modules\Support\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Pada skema baru belum ada pilihan tenant yang perlu diperbarui.
        // Konsol juga membangun skema Core untuk test tanpa memuat registry module.
        if (! DB::table('tenant_number_sequences')->exists()) {
            return;
        }

        // Kebijakan datang dari pemilik reference; Core tidak menyebut satu pun nama module.
        foreach (app(ModuleRegistry::class)->all() as $module) {
            $manifest = ModuleManifestFiles::read($module->folder);
            foreach ($manifest['number_sequences']['references'] ?? [] as $reference) {
                $policy = array_intersect_key($reference['default_settings'] ?? [], array_flip(['allow_manual', 'allow_automatic']));
                if ($policy === []) {
                    continue;
                }
                $referenceId = DB::table('app_number_sequence_references')->where('code', $reference['code'])->value('id');
                DB::table('tenant_number_sequences')->where('reference_id', $referenceId)->update($policy);
            }
        }
    }

    public function down(): void
    {
        // Pilihan tenant tidak ditebak kembali ketika image lama dipakai.
    }
};
