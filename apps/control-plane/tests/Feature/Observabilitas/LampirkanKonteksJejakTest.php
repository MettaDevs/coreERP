<?php

declare(strict_types=1);

namespace Tests\Feature\Observabilitas;

use App\Http\Middleware\LampirkanKonteksJejak;
use App\Http\Middleware\ResolveModuleContext;
use App\Support\Modules\ModuleRequestContext;
use App\Support\Observabilitas\JejakAktif;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Penjaga: konteks CoreERP benar-benar sampai ke span, dan ketiadaan jejak tidak pernah
 * membuat permintaan gagal.
 *
 * Dua sifat yang dijaga di sini, dan keduanya baru terlihat rusak di tempat yang jauh:
 *
 * 1. **Waktunya.** Atribut permintaan ditulis middleware rute, yang berjalan *di dalam*
 *    middleware global ini. Membacanya pada perjalanan masuk menghasilkan span tanpa
 *    atribut, tanpa satu pun error — yang gagal adalah pertanyaan orang tiga minggu
 *    kemudian, bukan test. Karena itu test ini menulis atributnya dari dalam closure
 *    `$next`, meniru urutan yang sebenarnya, bukan menyiapkannya lebih dulu.
 * 2. **Ketiadaannya.** Pemasangan on-prem boleh tidak punya ekstensi, SDK, maupun
 *    collector. Pada keadaan itu span yang aktif adalah span kosong yang tidak merekam,
 *    dan middleware harus melewatinya tanpa suara.
 *
 * Test ini sengaja memakai `PHPUnit\Framework\TestCase` polos, bukan `Tests\TestCase`.
 * Yang diuji adalah satu middleware dan satu kelas pembantu; tidak ada satu pun query di
 * dalamnya, dan menggantungkannya pada PostgreSQL berarti sifat di atas berhenti terjaga
 * setiap kali basis data test kebetulan tidak menyala.
 */
class LampirkanKonteksJejakTest extends TestCase
{
    private const ATRIBUT = [
        'coreerp.tenant_id',
        'coreerp.module_id',
        'coreerp.legal_entity_id',
        'coreerp.org_unit_id',
        'coreerp.user_id',
    ];

    public function test_konteks_module_menempel_pada_span_yang_aktif(): void
    {
        $penampung = new InMemoryExporter;
        $penyedia = new TracerProvider(new SimpleSpanProcessor($penampung));

        $span = $penyedia->getTracer('uji')->spanBuilder('GET /module/app-uji/entitas')->startSpan();
        $lingkup = $span->activate();

        try {
            (new LampirkanKonteksJejak)->handle(
                Request::create('/module/app-uji/entitas'),
                function (Request $permintaan): Response {
                    // Ditulis di sini, bukan sebelum middleware dipanggil: inilah tempat
                    // `ResolveModuleContext` yang sesungguhnya berjalan.
                    $permintaan->attributes->set(ResolveModuleContext::MODULE_AKTIF, 'app-uji');
                    $permintaan->attributes->set(ModuleRequestContext::TENANT_ID, 'tenant-1');
                    $permintaan->attributes->set(ModuleRequestContext::LEGAL_ENTITY_ID, 42);
                    $permintaan->attributes->set(ModuleRequestContext::ORG_UNIT_ID, null);
                    $permintaan->attributes->set(ModuleRequestContext::USER_ID, 'user-9');

                    return new Response('ok');
                },
            );
        } finally {
            $lingkup->detach();
            $span->end();
        }

        $atribut = $penampung->getSpans()[0]->getAttributes()->toArray();

        $this->assertSame('tenant-1', $atribut['coreerp.tenant_id']);
        $this->assertSame('app-uji', $atribut['coreerp.module_id']);
        // Id integer tetap dikirim sebagai teks. Backend jejak mengindeks atribut per tipe,
        // dan id yang kadang angka kadang teks menjadi dua kolom yang tidak bisa dicari
        // sekaligus.
        $this->assertSame('42', $atribut['coreerp.legal_entity_id']);
        $this->assertSame('user-9', $atribut['coreerp.user_id']);
        // Batas organisasi boleh kosong, dan atribut kosong tidak dikirim sama sekali.
        $this->assertArrayNotHasKey('coreerp.org_unit_id', $atribut);
    }

    public function test_atribut_yang_dibaca_sebelum_next_masih_kosong(): void
    {
        $penampung = new InMemoryExporter;
        $penyedia = new TracerProvider(new SimpleSpanProcessor($penampung));

        $span = $penyedia->getTracer('uji')->spanBuilder('GET /module/app-uji/entitas')->startSpan();
        $lingkup = $span->activate();

        try {
            (new LampirkanKonteksJejak)->handle(
                Request::create('/module/app-uji/entitas'),
                fn (): Response => new Response('ok'),
            );
        } finally {
            $lingkup->detach();
            $span->end();
        }

        // Permintaan yang tidak pernah melewati middleware konteks module — misalnya
        // halaman shell Core — tidak menghasilkan atribut palsu berisi string kosong.
        foreach (self::ATRIBUT as $kunci) {
            $this->assertArrayNotHasKey($kunci, $penampung->getSpans()[0]->getAttributes()->toArray());
        }
    }

    public function test_kesalahan_tercatat_pada_span_dan_menandainya_gagal(): void
    {
        $penampung = new InMemoryExporter;
        $penyedia = new TracerProvider(new SimpleSpanProcessor($penampung));

        $span = $penyedia->getTracer('uji')->spanBuilder('GET /gagal')->startSpan();
        $lingkup = $span->activate();

        try {
            JejakAktif::catatKesalahan(new RuntimeException('sequence aktif tidak ditemukan'));
        } finally {
            $lingkup->detach();
            $span->end();
        }

        $terkirim = $penampung->getSpans()[0];

        $this->assertSame('Error', $terkirim->getStatus()->getCode());
        $this->assertSame('sequence aktif tidak ditemukan', $terkirim->getStatus()->getDescription());
        $this->assertSame('exception', $terkirim->getEvents()[0]->getName());
    }

    public function test_tanpa_sdk_middleware_diam_dan_permintaan_tetap_jalan(): void
    {
        // Tidak ada TracerProvider yang diaktifkan: `Span::getCurrent()` mengembalikan span
        // kosong yang tidak merekam, persis seperti pemasangan on-prem tanpa ekstensi.
        $permintaan = Request::create('/module/app-uji/entitas');
        $permintaan->attributes->set(ModuleRequestContext::TENANT_ID, 'tenant-1');

        $jawaban = (new LampirkanKonteksJejak)->handle($permintaan, fn (): Response => new Response('ok'));

        $this->assertSame('ok', $jawaban->getContent());
    }

    public function test_lemparan_dari_hilir_tetap_diteruskan(): void
    {
        // Atribut tetap ditempel lewat `finally`, tetapi lemparannya tidak boleh tertelan:
        // yang menentukan jawaban 500 adalah handler kesalahan, bukan middleware jejak.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hilir gagal');

        (new LampirkanKonteksJejak)->handle(
            Request::create('/module/app-uji/entitas'),
            function (): Response {
                throw new RuntimeException('hilir gagal');
            },
        );
    }
}
