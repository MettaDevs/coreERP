<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Modules\Contracts\AuditColumns;
use App\Support\Modules\Contracts\RowVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Versi baris (K-03, area 3): trigger menaikkannya pada setiap UPDATE, dan {@see RowVersion} menolak
 * penyimpanan dengan versi basi. Tabelnya dibuat di test supaya yang diuji mekanismenya, bukan tabel
 * tertentu; pemakaian di endpoint sungguhan diuji di test endpoint masing-masing.
 */
final class RowVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('contoh_versi', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('nama');
            $table->timestamps();
            AuditColumns::add($table);
        });
        AuditColumns::attach('contoh_versi');

        Route::middleware('web')->match(['PUT', 'PATCH'], '/_test/contoh-versi/{id}', function (Request $request, string $id) {
            RowVersion::claim(DB::table('contoh_versi')->where('id', $id), RowVersion::expected($request));
            DB::table('contoh_versi')->where('id', $id)->update(['nama' => $request->input('nama')]);

            $version = (int) DB::table('contoh_versi')->where('id', $id)->value('version');

            return response()->json(['version' => $version])->header('ETag', RowVersion::etag($version));
        });
    }

    public function test_setiap_update_menaikkan_versi_apa_pun_nilai_yang_ditulis_kode(): void
    {
        $id = $this->insert('awal');
        $this->assertSame(1, $this->version($id));

        DB::table('contoh_versi')->where('id', $id)->update(['nama' => 'kedua']);
        $this->assertSame(2, $this->version($id));

        DB::table('contoh_versi')->where('id', $id)->update(['version' => 99, 'nama' => 'ketiga']);
        $this->assertSame(3, $this->version($id));
    }

    public function test_dua_penyimpanan_dengan_versi_yang_sama_yang_kedua_ditolak_409(): void
    {
        $id = $this->insert('awal');

        $this->patchJson("/_test/contoh-versi/{$id}", ['nama' => 'dari tab pertama', 'version' => 1])
            ->assertOk()
            ->assertHeader('ETag', 'W/"3"');

        $this->patchJson("/_test/contoh-versi/{$id}", ['nama' => 'dari tab kedua', 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version')
            ->assertJsonPath('error.message', RowVersion::STALE_MESSAGE);

        $this->assertSame('dari tab pertama', DB::table('contoh_versi')->where('id', $id)->value('nama'));
    }

    public function test_if_match_diterima_dan_etag_basi_ditolak(): void
    {
        $id = $this->insert('awal');

        $this->patchJson("/_test/contoh-versi/{$id}", ['nama' => 'lewat API'], ['If-Match' => 'W/"1"'])->assertOk();
        $this->patchJson("/_test/contoh-versi/{$id}", ['nama' => 'ETag kuat'], ['If-Match' => '"3"'])->assertOk();
        $this->patchJson("/_test/contoh-versi/{$id}", ['nama' => 'ETag basi'], ['If-Match' => 'W/"3"'])->assertStatus(409);

        $this->assertSame('ETag kuat', DB::table('contoh_versi')->where('id', $id)->value('nama'));
    }

    public function test_penyimpanan_tanpa_versi_ditolak_428(): void
    {
        $id = $this->insert('awal');

        $this->patchJson("/_test/contoh-versi/{$id}", ['nama' => 'tanpa versi'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'version_required');
        $this->patchJson("/_test/contoh-versi/{$id}", ['nama' => 'bintang'], ['If-Match' => '*'])->assertStatus(428);

        $this->assertSame('awal', DB::table('contoh_versi')->where('id', $id)->value('nama'));
        $this->assertSame(1, $this->version($id));
    }

    public function test_record_yang_tidak_ada_dijawab_404_bukan_409(): void
    {
        $this->patchJson('/_test/contoh-versi/'.Str::ulid(), ['nama' => 'x', 'version' => 1])->assertNotFound();
    }

    public function test_form_inertia_kembali_dengan_galat_pada_field_version(): void
    {
        $id = $this->insert('awal');
        DB::table('contoh_versi')->where('id', $id)->update(['nama' => 'diubah orang lain']);

        $this->from('/halaman-form')
            ->patch("/_test/contoh-versi/{$id}", ['nama' => 'dari form', 'version' => 1], ['X-Inertia' => 'true'])
            ->assertRedirect('/halaman-form')
            ->assertSessionHasErrors(['version' => RowVersion::STALE_MESSAGE]);
    }

    public function test_kenaikan_versi_tidak_tercatat_di_log_perubahan(): void
    {
        DB::table('change_log_setup_tables')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => null, 'table_name' => 'contoh_versi',
            'log_insertion' => 'none', 'log_modification' => 'all', 'log_deletion' => 'none',
        ]);
        $id = $this->insert('awal');

        DB::table('contoh_versi')->where('id', $id)->update(['nama' => 'diubah']);

        $this->assertSame(['nama'], DB::table('change_log_entries')->where('record_id', $id)->pluck('field_name')->all());
    }

    private function insert(string $nama): string
    {
        $id = (string) Str::ulid();
        DB::table('contoh_versi')->insert(['id' => $id, 'tenant_id' => (string) Str::ulid(), 'nama' => $nama]);

        return $id;
    }

    private function version(string $id): int
    {
        return (int) DB::table('contoh_versi')->where('id', $id)->value('version');
    }
}
