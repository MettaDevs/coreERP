<?php

use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Foundation\NumberSequence\Models\TenantNumberSequence;
use App\Foundation\NumberSequence\Support\CoreNumberSequences;
use App\Foundation\Vendor\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Ganti referensi, jangan mengubah scope riwayat penerbitan yang sudah terpakai.
        DB::statement('LOCK TABLE vendors IN SHARE ROW EXCLUSIVE MODE');
        $legacyReference = DB::table('app_number_sequence_references')->where('code', 'core.vendor')->value('id');
        if ($legacyReference !== null) {
            DB::table('app_number_sequence_references')->where('id', $legacyReference)->update([
                'code' => 'core.vendor-company-legacy', 'name' => 'Nomor vendor lama (per perusahaan)', 'updated_at' => now(),
            ]);
        }
        // Materialisasikan urutan bersama juga sebelum vendor pertama, agar image N-1 memakainya.
        $tenantIds = DB::table('tenant_memberships')->select('tenant_id')
            ->union(DB::table('vendors')->select('tenant_id'))
            ->union(DB::table('tenant_number_sequences')->select('tenant_id'))->pluck('tenant_id');
        $issuer = app(NumberSequenceService::class);

        foreach ($tenantIds as $tenantId) {
            app(CoreNumberSequences::class)->ensure($tenantId, Vendor::NUMBER_SEQUENCE);
            $sequence = TenantNumberSequence::query()->where('tenant_id', $tenantId)
                ->whereHas('reference', fn ($query) => $query->where('code', Vendor::NUMBER_SEQUENCE))->firstOrFail();
            $context = ['tenant_id' => $tenantId, 'app_id' => CoreNumberSequences::APP_ID];
            $vendors = DB::table('vendors')->where('tenant_id', $tenantId)->orderBy('created_at')->orderBy('id')->get();
            $numbers = [];
            $duplicates = [];

            // Daftarkan semua nomor yang dipertahankan sebelum menerbitkan penggantinya.
            foreach ($vendors as $vendor) {
                if (isset($numbers[$vendor->number])) {
                    $duplicates[] = $vendor;

                    continue;
                }
                $numbers[$vendor->number] = true;
                $issuer->issue($context, Vendor::NUMBER_SEQUENCE, 'vendor-number-migration:'.$vendor->id, $vendor->number);
            }

            $lastIssued = $legacyReference === null ? 0 : (int) DB::table('number_sequence_issues')
                ->whereIn('sequence_id', DB::table('tenant_number_sequences')->select('id')
                    ->where('tenant_id', $tenantId)->where('reference_id', $legacyReference))->max('numeric_value');
            // Nomor manual yang mengikuti format baru juga tidak boleh membuat counter mundur.
            $prefix = collect($sequence->segments)->where('type', 'constant')->pluck('value')->implode('');
            foreach (array_keys($numbers) as $number) {
                if (preg_match('/^'.preg_quote($prefix, '/').'([0-9]+)$/D', (string) $number, $match)) {
                    $lastIssued = max($lastIssued, (int) $match[1]);
                }
            }
            if ($lastIssued > 0) {
                $issuer->advance($sequence, $context, min($lastIssued + 1, $sequence->maximum_number ?? PHP_INT_MAX), null);
            }

            foreach ($duplicates as $vendor) {
                $issued = $issuer->issue($context, Vendor::NUMBER_SEQUENCE, 'vendor-number-migration:'.$vendor->id);
                DB::table('vendors')->where('tenant_id', $tenantId)->where('id', $vendor->id)->update([
                    'number' => $issued['number'], 'updated_at' => now(), 'version' => DB::raw('version + 1'),
                ]);
                DB::table('number_sequence_audit_events')->insert([
                    'id' => strtolower((string) Str::ulid()), 'sequence_id' => $sequence->id,
                    'app_id' => CoreNumberSequences::APP_ID, 'event_type' => 'vendor_renumbered',
                    'formatted_value' => $issued['number'],
                    'details' => json_encode(['vendor_id' => $vendor->id, 'legal_entity_id' => $vendor->legal_entity_id,
                        'previous_number' => $vendor->number, 'number' => $issued['number']], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if ($legacyReference !== null) {
            DB::table('tenant_number_sequences')->where('reference_id', $legacyReference)->update(['status' => 'stopped', 'updated_at' => now()]);
        }
        DB::statement('CREATE UNIQUE INDEX vendors_tenant_number_unique ON vendors (tenant_id, number)');
    }

    public function down(): void
    {
        // Mundur image tidak mengembalikan nomor kembar atau menghapus riwayat perubahan.
    }
};
