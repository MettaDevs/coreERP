<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation\NumberSequence;

use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Foundation\NumberSequence\Models\NumberSequenceReference;
use App\Foundation\NumberSequence\Models\TenantNumberSequence;
use App\Platform\Modules\Actions\InstallModule;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dokumen pertama dari urutan nomor milik module bernomor 1, bukan 0.
 *
 * Sampai 1 Oktober 2026 draf urutan module lahir dengan `minimum_number` 0, sehingga dokumen
 * pertama setiap module bernomor `XXXX00000`. Default kolom dan urutan nomor Core sudah 1, dan
 * Business Central juga memulai dari `Starting No.` yang lazimnya `…00001`. Hanya bawaan draf
 * module yang menyimpang.
 */
class NumberSequenceStartsAtOneTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_01_140000_start_unused_module_number_sequences_at_one.php';

    public function test_first_number_of_a_freshly_installed_module_is_one(): void
    {
        $tenantId = $this->tenant();
        $this->registerModuleReference('contoh-a', 'contoh-a.barang', 'BRGA');

        app(InstallModule::class)->handle('contoh-a', $tenantId);

        $sequence = TenantNumberSequence::query()->where('tenant_id', $tenantId)->firstOrFail();
        $this->assertSame(1, $sequence->minimum_number);

        $issued = app(NumberSequenceIssuer::class)->issue(
            ['tenant_id' => $tenantId, 'app_id' => 'contoh-a'],
            'contoh-a.barang',
            'dokumen-pertama',
        );

        $this->assertSame('BRGA00001', $issued['number']);
    }

    public function test_migration_moves_only_sequences_that_never_issued_a_number(): void
    {
        $tenantId = $this->tenant();
        $this->registerApp('sample-app');
        $context = ['tenant_id' => $tenantId, 'app_id' => 'sample-app'];
        $service = app(NumberSequenceService::class);

        $unused = $this->zeroBasedSequence($tenantId, 'sample-app.unused', 'UNUA');
        $advancedOnly = $this->zeroBasedSequence($tenantId, 'sample-app.counter-only', 'CNTA');
        DB::table('number_sequence_counters')->insert([
            'id' => (string) Str::ulid(), 'sequence_id' => $advancedOnly->id, 'scope_key' => 'tenant',
            'period_key' => 'all', 'next_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $issued = $this->zeroBasedSequence($tenantId, 'sample-app.issued', 'ISSA');
        $this->assertSame('ISSA00000', $service->issue($context, 'sample-app.issued', 'sudah-terbit')['number']);

        $reserved = $this->zeroBasedSequence($tenantId, 'sample-app.reserved', 'RSVA', [
            'profile_code' => 'continuous-strict', 'is_continuous' => true, 'preallocation_quantity' => 5,
        ]);
        $this->assertSame('RSVA00000', $service->reserve($context, 'sample-app.reserved', 'dipesan')['number']);

        $chosenByAdmin = $this->zeroBasedSequence($tenantId, 'sample-app.chosen', 'CHSA', ['minimum_number' => 5]);

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up();

        $this->assertSame(1, $unused->refresh()->minimum_number);
        $this->assertSame(1, $advancedOnly->refresh()->minimum_number);
        $this->assertSame(1, (int) DB::table('number_sequence_counters')->where('sequence_id', $advancedOnly->id)->value('next_number'));

        $this->assertSame(0, $issued->refresh()->minimum_number, 'Nomor 0 sudah tercatat di dokumen.');
        $this->assertSame(0, $reserved->refresh()->minimum_number, 'Nomor 0 sudah dipegang aplikasi lewat reservasi.');
        $this->assertSame(5, $chosenByAdmin->refresh()->minimum_number);

        $this->assertSame('UNUA00001', $service->issue($context, 'sample-app.unused', 'pertama')['number']);
        $this->assertSame('CNTA00001', $service->issue($context, 'sample-app.counter-only', 'pertama')['number']);
        $this->assertSame('ISSA00001', $service->issue($context, 'sample-app.issued', 'kedua')['number']);
    }

    private function tenant(): string
    {
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Klien Uji', 'slug' => 'klien-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $tenantId = (string) Str::ulid();
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'Tenant Uji', 'slug' => 'tenant-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $tenantId;
    }

    private function registerApp(string $appId): void
    {
        DB::table('apps')->updateOrInsert(['id' => $appId], [
            'name' => $appId, 'version' => '0.1.0', 'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function registerModuleReference(string $appId, string $code, string $prefix): void
    {
        $this->registerApp($appId);
        NumberSequenceReference::query()->create([
            'app_id' => $appId, 'code' => $code, 'name' => 'Nomor uji', 'default_prefix' => $prefix, 'allowed_scopes' => ['tenant'],
        ]);
    }

    /**
     * Bentuk persis draf module sebelum perbaikan.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function zeroBasedSequence(string $tenantId, string $code, string $prefix, array $overrides = []): TenantNumberSequence
    {
        $reference = NumberSequenceReference::query()->create([
            'app_id' => 'sample-app', 'code' => $code, 'name' => 'Nomor uji', 'default_prefix' => $prefix, 'allowed_scopes' => ['tenant'],
        ]);

        return TenantNumberSequence::query()->create([
            'tenant_id' => $tenantId,
            'reference_id' => $reference->id,
            'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'active',
            'is_continuous' => false,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => true,
            'preallocation_quantity' => 20,
            'minimum_number' => 0,
            'maximum_number' => 19999,
            'segments' => [['type' => 'constant', 'value' => $prefix], ['type' => 'number', 'length' => 5]],
            ...$overrides,
        ]);
    }
}
