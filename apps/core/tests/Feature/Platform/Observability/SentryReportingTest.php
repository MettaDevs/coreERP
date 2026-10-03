<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Observability;

use App\Platform\Modules\Support\ModuleRequestContext;
use App\Platform\Observability\Support\ErrorReporter;
use Illuminate\Http\Request;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

use function Sentry\captureMessage;

/**
 * Laporan kesalahan sampai di Sentry dengan tenant tempat ia terjadi, tanpa data pribadi, dan tanpa
 * meninggalkan tenant itu pada scope yang dipakai permintaan berikutnya di worker yang sama.
 *
 * Transport tiruan mencatat event yang akan dikirim; tidak ada jaringan yang disentuh.
 */
class SentryReportingTest extends TestCase
{
    /** @var list<Event> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $events = &$this->events;
        $transport = new class($events) implements TransportInterface
        {
            /** @param  list<Event>  $events */
            public function __construct(private array &$events) {}

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        SentrySdk::setCurrentHub(new Hub(
            ClientBuilder::create(['dsn' => 'http://kunci@sentry.test/1'])->setTransport($transport)->getClient(),
        ));
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub(new Hub);

        parent::tearDown();
    }

    private function permintaanTenant(): Request
    {
        $permintaan = Request::create('https://erp.test/aset', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'PerambanUji/1.0',
        ]);
        $permintaan->attributes->set(ErrorReporter::HTTP_MARKER, true);
        $permintaan->attributes->set(ModuleRequestContext::TENANT_ID, '01TENANTSENTRY0000000000AA');
        $permintaan->attributes->set(ModuleRequestContext::USER_ID, '42');

        return $permintaan;
    }

    public function test_kesalahan_sampai_di_sentry_dengan_tag_tenant_dan_pengguna_sebagai_id(): void
    {
        ErrorReporter::report(new RuntimeException('gagal menyimpan aset'), $this->permintaanTenant());

        $this->assertCount(1, $this->events);
        $event = $this->events[0];
        $this->assertSame('01TENANTSENTRY0000000000AA', $event->getTags()['coreerp.tenant_id'] ?? null);
        $this->assertSame('42', $event->getUser()?->getId());
        $this->assertNull($event->getUser()?->getEmail());
        $this->assertSame('gagal menyimpan aset', $event->getExceptions()[0]->getValue());

        $konteks = $event->getContexts()['coreerp'] ?? [];
        $this->assertStringContainsString('gagal menyimpan aset', (string) ($konteks['laporan'] ?? ''));
    }

    public function test_alamat_klien_dan_peramban_tidak_dikirim(): void
    {
        ErrorReporter::report(new RuntimeException('gagal'), $this->permintaanTenant());

        $konteks = $this->events[0]->getContexts()['coreerp'] ?? [];
        $this->assertArrayNotHasKey('client.address', $konteks);
        $this->assertArrayNotHasKey('user_agent.original', $konteks);
        // Termasuk di dalam teks laporan, yang baris kepalanya memuat alamat klien.
        $seluruhnya = (string) json_encode($this->events[0]->getContexts());
        $this->assertStringNotContainsString('203.0.113.7', $seluruhnya);
        $this->assertStringNotContainsString('PerambanUji', $seluruhnya);
    }

    /**
     * Di worker Octane hub dan scope-nya melayani permintaan berikutnya. Tag tenant yang menempel di sana
     * akan menandai kesalahan tenant lain — atau kejadian tanpa tenant — sebagai milik tenant ini.
     */
    public function test_tag_tenant_tidak_menempel_pada_kejadian_berikutnya(): void
    {
        ErrorReporter::report(new RuntimeException('gagal'), $this->permintaanTenant());

        captureMessage('kejadian berikutnya tanpa tenant');

        $this->assertCount(2, $this->events);
        $this->assertArrayNotHasKey('coreerp.tenant_id', $this->events[1]->getTags());
        $this->assertNull($this->events[1]->getUser());
        $this->assertArrayNotHasKey('coreerp', $this->events[1]->getContexts());
    }

    public function test_tanpa_dsn_tidak_ada_yang_dikirim_dan_tidak_ada_yang_dilempar(): void
    {
        SentrySdk::setCurrentHub(new Hub);

        ErrorReporter::report(new RuntimeException('gagal'), $this->permintaanTenant());

        $this->assertSame([], $this->events);
    }
}
