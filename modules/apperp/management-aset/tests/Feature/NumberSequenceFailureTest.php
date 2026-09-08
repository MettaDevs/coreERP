<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sebab kegagalan penerbitan nomor harus dapat dibedakan dari responsnya.
 *
 * Ini pernah menjadi masalah nyata: token service app tidak sinkron dengan Control Plane,
 * Core menjawab 403, dan app melaporkannya sebagai "Nomor belum dapat diterbitkan" —
 * pesan yang sama persis dengan Core yang sedang mati. Menelusurinya berakhir dengan
 * membandingkan digest kredensial secara manual. Kode di sini yang mencegah itu terulang.
 */
class NumberSequenceFailureTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
    }

    /**
     * @return array<string, array{0:int, 1:string}>
     */
    public static function coreAnswers(): array
    {
        return [
            // Kredensial app ditolak: bukan gangguan, dan mencoba ulang tidak menolong.
            'kredensial ditolak' => [403, 'number_sequence_forbidden'],
            'tidak terautentikasi' => [401, 'number_sequence_forbidden'],
            'reference belum terdaftar' => [404, 'number_sequence_reference_unknown'],
            'melebihi batas laju' => [429, 'number_sequence_throttled'],
            'permintaan ditolak' => [422, 'number_sequence_rejected'],
            // Hanya ini yang benar-benar berarti layanannya sedang tidak tersedia.
            'core bermasalah' => [500, 'number_sequence_unavailable'],
        ];
    }

    #[DataProvider('coreAnswers')]
    public function test_setiap_jawaban_core_menghasilkan_kode_error_sendiri(int $status, string $expectedCode): void
    {
        Http::fake(['*' => Http::response(['message' => 'ditolak'], $status)]);

        $this->createGroup()
            ->assertStatus(503)
            ->assertJsonPath('error.code', $expectedCode);
    }

    /** Core tidak terjangkau berbeda dari Core yang menjawab dengan galat. */
    public function test_core_tidak_terjangkau_dibedakan_dari_core_yang_menjawab_galat(): void
    {
        Http::fake(fn () => throw new ConnectionException('gagal terhubung'));

        $this->createGroup()
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'number_sequence_unreachable');
    }

    /** Konfigurasi yang belum diisi tidak boleh terbaca sebagai layanan yang tumbang. */
    public function test_token_service_belum_diisi_menghasilkan_kode_konfigurasi(): void
    {
        config(['services.coreerp.service_token' => '']);

        $this->createGroup()
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'number_sequence_not_configured');
    }

    /** Nomor yang tidak sah dari Core adalah bug Core, bukan gangguan jaringan. */
    public function test_jawaban_tanpa_nomor_yang_sah_dilaporkan_terpisah(): void
    {
        Http::fake(['*' => Http::response(['data' => ['number' => '']])]);

        $this->createGroup()
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'number_sequence_invalid_response');
    }

    /**
     * Status HTTP dan reference wajib masuk log. Tanpa keduanya, satu-satunya cara
     * mengetahui sebabnya adalah membaca token — yang justru tidak boleh dicatat.
     */
    public function test_log_memuat_status_core_dan_reference_tanpa_token(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ditolak'], 403)]);
        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = ['message' => $message->message, 'context' => $message->context];
        });

        $this->createGroup()->assertStatus(503);

        $entry = collect($captured)->firstWhere(fn (array $item): bool => str_contains($item['message'], 'Penerbitan nomor gagal'));
        $this->assertNotNull($entry, 'Kegagalan penerbitan nomor harus tercatat di log.');
        $this->assertSame('number_sequence_forbidden', $entry['context']['error_code']);
        $this->assertSame('management-aset.group-aset', $entry['context']['reference']);
        $this->assertSame(403, $entry['context']['core_http_status']);
        $this->assertSame($this->tenantId, $entry['context']['tenant_id']);
        // Token tidak pernah ikut dicatat, termasuk potongannya.
        $this->assertStringNotContainsString('service-token', json_encode($entry['context'], JSON_THROW_ON_ERROR));
    }

    private function createGroup(): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.group-aset.create'])
            ->withHeader('Idempotency-Key', 'group-aset:'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/group-aset', ['nama' => 'Bangunan']);
    }
}
