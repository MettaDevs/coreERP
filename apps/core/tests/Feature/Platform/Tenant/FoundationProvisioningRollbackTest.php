<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Tenant;

use App\Platform\Modules\Actions\RegisterAppCatalog;
use App\Platform\Modules\Events\AppNumberSequenceReferencesDeclared;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Events\TenantCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Penyiapan data Foundation tetap ikut transaksi Platform yang memicunya.
 *
 * Platform tidak lagi memanggil Foundation langsung; ia mengirim event dan Foundation
 * mendengarkannya. Pembalikan arah itu hanya sah bila waktunya tidak bergeser: listener harus
 * berjalan sinkron di dalam transaksi pendaftaran, sehingga kegagalan penyiapan membatalkan
 * seluruh pendaftaran, persis seperti saat pemanggilannya masih langsung.
 *
 * Kegagalannya dipasang sebagai listener kedua, sesudah listener Foundation yang sungguhan.
 * Dengan begitu test ini juga membuktikan data yang sudah ditulis Foundation ikut batal.
 */
class FoundationProvisioningRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_foundation_setup_cancels_the_whole_business_registration(): void
    {
        $outerLevel = DB::transactionLevel();
        $unitsWritten = 0;

        Event::listen(TenantCreated::class, function (TenantCreated $event) use ($outerLevel, &$unitsWritten): void {
            $this->assertGreaterThan($outerLevel, DB::transactionLevel(), 'Listener harus berjalan di dalam transaksi pendaftaran.');
            $unitsWritten = DB::table('units_of_measure')->where('tenant_id', $event->tenantId)->count();

            throw new RuntimeException('Penyiapan Foundation gagal.');
        });

        try {
            app(RegisterBusiness::class)->handle([
                'name' => 'Pemilik',
                'email' => 'batal@metta.test',
                'password' => 'password',
                'business_name' => 'PT Batal',
                'app_ids' => [],
            ]);
            $this->fail('Pendaftaran seharusnya gagal bersama penyiapan Foundation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Penyiapan Foundation gagal.', $exception->getMessage());
        }

        $this->assertGreaterThan(0, $unitsWritten, 'Satuan bawaan harus sudah ditulis sebelum kegagalan.');
        $this->assertSame(0, DB::table('units_of_measure')->count());
        $this->assertDatabaseMissing('users', ['email' => 'batal@metta.test']);
        $this->assertSame(0, DB::table('tenants')->where('name', 'PT Batal')->count());
        $this->assertSame(0, DB::table('clients')->where('legal_name', 'PT Batal')->count());
        $this->assertSame(0, DB::table('outbox_events')->count());
    }

    public function test_failed_number_sequence_reference_cancels_the_app_catalog_registration(): void
    {
        $referenceWritten = false;

        Event::listen(AppNumberSequenceReferencesDeclared::class, function () use (&$referenceWritten): void {
            $referenceWritten = DB::table('app_number_sequence_references')->where('code', 'batal.dokumen')->exists();

            throw new RuntimeException('Penyiapan Foundation gagal.');
        });

        try {
            app(RegisterAppCatalog::class)->handle(
                [
                    'id' => 'app-batal',
                    'name' => 'App Batal',
                    'description' => null,
                    'version' => '0.1.0',
                    'database_name' => null,
                    'has_ui' => false,
                    'navigation' => null,
                    'repository_url' => null,
                    'contract_url' => null,
                    'status' => 'available',
                ],
                ['entry_points' => [], 'permissions' => [], 'privileges' => [], 'duties' => []],
                [['code' => 'batal.dokumen', 'name' => 'Dokumen batal', 'default_prefix' => 'BTL', 'allowed_scopes' => ['tenant']]],
            );
            $this->fail('Pendaftaran katalog seharusnya gagal bersama penyimpanan referensi nomor.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Penyiapan Foundation gagal.', $exception->getMessage());
        }

        $this->assertTrue($referenceWritten, 'Referensi nomor harus sudah ditulis listener Foundation sebelum kegagalan.');
        $this->assertDatabaseMissing('apps', ['id' => 'app-batal']);
        $this->assertDatabaseMissing('app_number_sequence_references', ['code' => 'batal.dokumen']);
    }
}
