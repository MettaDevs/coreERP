<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class ProfilPenyusutanTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    private int $issued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
        Http::fake(function () {
            $this->issued++;

            return Http::response(['data' => ['number' => sprintf('DPRE-%06d', $this->issued)]]);
        });
    }

    public function test_profil_menjalankan_crud_penuh_setelah_menjadi_master(): void
    {
        // Sebelum dijadikan master, resource ini hanya punya GET dan POST.
        $id = $this->create(['nama' => 'Garis lurus 5 tahun', 'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 60])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'DPRE-000001')
            ->assertJsonPath('data.method', 'straight_line')
            ->json('data.id');

        $this->request('get', '/api/v1/profil-penyusutan/'.$id)->assertOk()->assertJsonPath('data.id', $id);

        $this->request('patch', '/api/v1/profil-penyusutan/'.$id, ['useful_life_periods' => 48])
            ->assertOk()
            ->assertJsonPath('data.useful_life_periods', 48);

        $this->request('delete', '/api/v1/profil-penyusutan/'.$id)->assertNoContent();
        $this->assertSoftDeleted('m_profil_penyusutan', ['id' => $id, 'tenant_id' => $this->tenantId]);
    }

    public function test_daftar_dipaginasi_seperti_master_lain(): void
    {
        $this->create(['nama' => 'Profil A', 'method' => 'consumption', 'frequency' => 'monthly', 'year_basis' => 'calendar'])->assertCreated();
        $this->create(['nama' => 'Profil B', 'method' => 'consumption', 'frequency' => 'yearly', 'year_basis' => 'fiscal'])->assertCreated();

        $this->request('get', '/api/v1/profil-penyusutan')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1);
    }

    /**
     * `manual_schedule` berkolom json dan divalidasi sebagai array. Tanpa cast pada model,
     * menyimpannya lewat API melempar di PDO PostgreSQL. Test lama tidak menangkap ini
     * karena selalu menulis lewat DB::table() dengan json_encode manual.
     */
    public function test_jadwal_manual_bulat_balik_lewat_api(): void
    {
        $schedule = [['amount' => 1000], ['amount' => 750.5]];

        $id = $this->create([
            'nama' => 'Jadwal manual',
            'method' => 'manual',
            'frequency' => 'monthly',
            'year_basis' => 'calendar',
            'manual_schedule' => $schedule,
        ])->assertCreated()->json('data.id');

        $this->request('get', '/api/v1/profil-penyusutan/'.$id)
            ->assertOk()
            ->assertJsonPath('data.manual_schedule.0.amount', 1000)
            ->assertJsonPath('data.manual_schedule.1.amount', 750.5);

        $this->assertDatabaseHas('m_profil_penyusutan', ['id' => $id]);
    }

    public function test_kewajiban_field_mengikuti_metode(): void
    {
        $this->create(['nama' => 'Tanpa umur', 'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('useful_life_periods');

        $this->create(['nama' => 'Tanpa tarif', 'method' => 'reducing_balance', 'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 12])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_percent');

        $this->create(['nama' => 'Tanpa jadwal', 'method' => 'manual', 'frequency' => 'monthly', 'year_basis' => 'calendar'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manual_schedule');

        // Metode konsumsi tidak memerlukan satu pun dari ketiganya.
        $this->create(['nama' => 'Pemakaian', 'method' => 'consumption', 'frequency' => 'monthly', 'year_basis' => 'calendar'])
            ->assertCreated();

        // Nomor hanya terbit untuk yang berhasil; validasi berjalan lebih dahulu.
        Http::assertSentCount(1);
    }

    public function test_metode_di_luar_daftar_ditolak(): void
    {
        $this->create(['nama' => 'Metode karangan', 'method' => 'anuitas', 'frequency' => 'monthly', 'year_basis' => 'calendar'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
    }

    public function test_retry_dengan_kunci_sama_tidak_menerbitkan_nomor_kedua(): void
    {
        $payload = ['nama' => 'Garis lurus', 'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 12];
        $first = $this->create($payload, 'kunci-tetap')->assertCreated();

        $this->create($payload, 'kunci-tetap')
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        Http::assertSentCount(1);
    }

    /** @param array<string, mixed> $payload */
    private function create(array $payload, ?string $key = null): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissions()))
            ->withHeader('Idempotency-Key', $key ?? 'profil-'.Str::ulid())
            ->postJson('/api/v1/profil-penyusutan', $payload);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissions()))
            ->json(strtoupper($method), $uri, $payload);
    }

    /** @return list<string> */
    private function permissions(): array
    {
        return array_map(
            fn (string $action): string => 'management-aset.profil-penyusutan.'.$action,
            ['read', 'create', 'update', 'archive'],
        );
    }
}
