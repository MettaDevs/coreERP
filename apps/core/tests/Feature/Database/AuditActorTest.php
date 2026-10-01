<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Platform\Identity\Models\User;
use App\Support\Database\AuditActor;
use App\Support\Modules\Contracts\AuditColumns;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Kolom jejak diisi trigger dari pengguna yang terpasang di guard (K-01). Tabelnya dibuat di test
 * supaya yang diuji trigger dan pemasangan pelakunya, bukan aturan tabel tertentu.
 */
final class AuditActorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('contoh_jejak', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('nama');
            AuditColumns::add($table);
        });
        AuditColumns::attach('contoh_jejak');
    }

    public function test_pengguna_yang_login_tercatat_sebagai_pembuat_dan_pengubah(): void
    {
        $pembuat = User::factory()->create();
        $pengubah = User::factory()->create();

        $this->actingAs($pembuat);
        $id = $this->insert('awal');
        $this->assertSame([$pembuat->id, $pembuat->id], $this->actors($id));

        // Update lewat query builder, jalur yang tidak melewati event model.
        $this->actingAs($pengubah);
        DB::table('contoh_jejak')->where('id', $id)->update(['nama' => 'diubah']);
        $this->assertSame([$pembuat->id, $pengubah->id], $this->actors($id));
    }

    public function test_pembuat_tidak_dapat_ditimpa_lewat_update(): void
    {
        $pembuat = User::factory()->create();
        $this->actingAs($pembuat);
        $id = $this->insert('awal');

        DB::table('contoh_jejak')->where('id', $id)->update([AuditColumns::CREATED_BY => 999999, AuditColumns::UPDATED_BY => 999999]);

        $this->assertSame([$pembuat->id, $pembuat->id], $this->actors($id));
    }

    public function test_perubahan_tanpa_pengguna_tercatat_sebagai_sistem(): void
    {
        $pembuat = User::factory()->create();
        $this->actingAs($pembuat);
        $id = $this->insert('awal');

        AuditActor::clear();
        DB::table('contoh_jejak')->where('id', $id)->update(['nama' => 'oleh job sistem']);

        $this->assertSame([$pembuat->id, null], $this->actors($id));
    }

    public function test_tanpa_pengguna_nilai_yang_ditulis_kode_dipakai_saat_membuat(): void
    {
        $id = (string) Str::ulid();
        DB::table('contoh_jejak')->insert([
            'id' => $id, 'tenant_id' => (string) Str::ulid(), 'nama' => 'impor', AuditColumns::CREATED_BY => 42,
        ]);

        $this->assertSame([42, 42], $this->actors($id));
    }

    public function test_run_as_melepas_pelaku_walau_pekerjaannya_gagal(): void
    {
        $pengguna = User::factory()->create();

        try {
            AuditActor::runAs($pengguna->id, fn () => throw new RuntimeException('gagal'));
        } catch (RuntimeException) {
        }

        $this->assertSame('', DB::selectOne("select current_setting('".AuditActor::SETTING."', true) as nilai")->nilai);
    }

    public function test_pengguna_yang_dipulihkan_dari_sesi_pada_permintaan_nyata_menjadi_pelaku(): void
    {
        $pengguna = User::factory()->create();
        AuditActor::clear();

        // Bukan `actingAs`: sesi berisi tanda login, dan guard memulihkan penggunanya sendiri di dalam
        // permintaan — jalur yang dilewati setiap permintaan sungguhan.
        $this->withSession([Auth::guard('web')->getName() => $pengguna->id])->get('/settings/profile');

        $this->assertSame((string) $pengguna->id, $this->setting());
    }

    public function test_id_yang_bukan_angka_tidak_dipasang_supaya_penulisan_tidak_gagal(): void
    {
        AuditActor::set('01JABCDEFGHJKMNPQRSTVWXYZ0');
        $this->assertSame('', $this->setting());

        $id = $this->insert('tetap tersimpan');
        $this->assertSame([null, null], $this->actors($id));
    }

    private function setting(): string
    {
        return (string) DB::selectOne("select coalesce(current_setting('".AuditActor::SETTING."', true), '') as nilai")->nilai;
    }

    private function insert(string $nama): string
    {
        $id = (string) Str::ulid();
        DB::table('contoh_jejak')->insert(['id' => $id, 'tenant_id' => (string) Str::ulid(), 'nama' => $nama]);

        return $id;
    }

    /** @return array{0: int|null, 1: int|null} */
    private function actors(string $id): array
    {
        $row = DB::table('contoh_jejak')->where('id', $id)->first();

        return [
            $row->created_by_user_id === null ? null : (int) $row->created_by_user_id,
            $row->updated_by_user_id === null ? null : (int) $row->updated_by_user_id,
        ];
    }
}
