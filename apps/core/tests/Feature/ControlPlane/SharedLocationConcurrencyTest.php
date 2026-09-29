<?php

namespace Tests\Feature\ControlPlane;

use App\Support\Modules\ModuleMigrator;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aturan buku alamat yang tidak boleh dijaga kode saja: satu party punya satu alamat utama, satu tempat
 * punya satu kontak utama per jenis, dan satu party menautkan satu tempat paling banyak sekali.
 *
 * `OrganizationAddressBook` mengantrekan penulisnya dengan mengunci baris party, tetapi penulis lain —
 * modul, impor, atau kode yang belum ditulis — tidak wajib lewat sana. Yang tersisa untuk menolak dua
 * permintaan yang berpacu adalah partial unique index, jadi test ini menulis langsung lewat dua koneksi.
 * `pgsql_test_secondary` adalah koneksi kedua ke database yang sama, berdiri sebagai instance Core kedua.
 * Bila index-nya dilepas, test ini merah.
 *
 * DatabaseTruncation, bukan RefreshDatabase: transaksi RefreshDatabase tidak pernah di-commit, sehingga
 * koneksi kedua tidak melihat datanya.
 */
class SharedLocationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $exceptTables = [
        'country_regions',
        'hierarchy_purposes',
        'location_purposes',
        'number_sequence_profiles',
        'party_types',
        ModuleMigrator::TABEL_RIWAYAT,
    ];

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE clients, apps RESTART IDENTITY CASCADE');
        self::reinstallCoreSecurityCatalog();

        parent::tearDown();
    }

    public function test_two_instances_cannot_both_make_their_address_the_primary_one(): void
    {
        [$tenantId, $partyId] = $this->party();
        $first = $this->location($tenantId, 'Kantor A');
        $second = $this->location($tenantId, 'Kantor B');

        $this->assertRejected(
            fn (Connection $connection) => $this->link($connection, $tenantId, $partyId, $first, primary: true),
            fn (Connection $connection) => $this->link($connection, $tenantId, $partyId, $second, primary: true),
            'Dua alamat utama untuk satu pihak berhasil tersimpan; kop dokumen akan memilih alamat yang berbeda-beda.',
        );
    }

    public function test_two_instances_cannot_link_the_same_place_to_one_party_twice(): void
    {
        [$tenantId, $partyId] = $this->party();
        $place = $this->location($tenantId, 'Menara Bersama');

        $this->assertRejected(
            fn (Connection $connection) => $this->link($connection, $tenantId, $partyId, $place),
            fn (Connection $connection) => $this->link($connection, $tenantId, $partyId, $place),
            'Satu tempat tertaut dua kali ke pihak yang sama; kegunaan dan status utamanya akan terbelah.',
        );
    }

    public function test_two_instances_cannot_both_make_a_contact_the_primary_one(): void
    {
        [$tenantId] = $this->party();
        $place = $this->location($tenantId, 'Kantor');

        $this->assertRejected(
            fn (Connection $connection) => $this->contact($connection, $tenantId, $place, '021-1'),
            fn (Connection $connection) => $this->contact($connection, $tenantId, $place, '021-2'),
            'Dua telepon utama pada satu tempat berhasil tersimpan.',
        );
    }

    public function test_an_archived_link_does_not_block_linking_the_place_again(): void
    {
        [$tenantId, $partyId] = $this->party();
        $place = $this->location($tenantId, 'Gudang');
        $connection = DB::connection('pgsql_test');

        $this->link($connection, $tenantId, $partyId, $place, primary: true, archived: true);
        $this->link($connection, $tenantId, $partyId, $place, primary: true);

        $this->assertSame(2, DB::table('party_locations')->where('location_id', $place)->count());
    }

    /**
     * @param  callable(Connection): void  $first
     * @param  callable(Connection): void  $second
     */
    private function assertRejected(callable $first, callable $second, string $message): void
    {
        $primary = DB::connection('pgsql_test');
        $secondary = DB::connection('pgsql_test_secondary');
        $secondary->statement("SET lock_timeout = '500ms'");

        $primary->beginTransaction();
        $secondary->beginTransaction();

        $rejected = false;
        try {
            $first($primary);

            try {
                // Permintaan kedua melihat keadaan yang sama dan menyimpulkan hal yang sama. Yang menahannya
                // hanya index: ia menunggu transaksi pertama, lalu ditolak atau kehabisan waktu tunggu.
                $second($secondary);
            } catch (QueryException) {
                $rejected = true;
            }
        } finally {
            $primary->rollBack();
            $secondary->rollBack();
        }

        $this->assertTrue($rejected, $message);
    }

    /** @return array{0: string, 1: string} */
    private function party(): array
    {
        $clientId = strtolower((string) Str::ulid());
        DB::table('clients')->insert([
            'id' => $clientId, 'legal_name' => 'Demo', 'slug' => 'demo-'.Str::lower(Str::random(6)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenantId = strtolower((string) Str::ulid());
        DB::table('tenants')->insert([
            'id' => $tenantId, 'client_id' => $clientId, 'name' => 'Demo',
            'slug' => 'demo-'.Str::lower(Str::random(6)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $partyId = strtolower((string) Str::ulid());
        DB::table('parties')->insert([
            'id' => $partyId, 'tenant_id' => $tenantId, 'type' => 'organization',
            'name' => 'PT Uji', 'search_name' => 'pt uji', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$tenantId, $partyId];
    }

    private function location(string $tenantId, string $name): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('locations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function link(Connection $connection, string $tenantId, string $partyId, string $locationId, bool $primary = false, bool $archived = false): void
    {
        $connection->table('party_locations')->insert([
            'id' => strtolower((string) Str::ulid()), 'tenant_id' => $tenantId, 'party_id' => $partyId,
            'location_id' => $locationId, 'is_primary' => $primary, 'deleted_at' => $archived ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contact(Connection $connection, string $tenantId, string $locationId, string $value): void
    {
        $connection->table('electronic_addresses')->insert([
            'id' => strtolower((string) Str::ulid()), 'tenant_id' => $tenantId, 'location_id' => $locationId,
            'type' => 'phone', 'value' => $value, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
