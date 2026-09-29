<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tempat berdiri sendiri dan dipakai beberapa pihak, mengikuti Global Address Book Dynamics 365
 * (Tahap 1 pada docs/todo/buku-alamat-global/).
 *
 * Sebelumnya `party_locations` adalah tempat sekaligus milik satu party: dua pihak di gedung yang sama
 * menyimpan alamat dua kali, satu alamat hanya punya satu kegunaan, dan kontak menempel ke party. Sesudah
 * migrasi ini:
 *
 * - `locations` adalah tempatnya (padanan `LogisticsLocation`), tanpa kolom party.
 * - `party_locations` menjadi tautan party ke tempat (padanan `DirPartyLocation`), dengan penanda utama.
 * - `party_location_purposes` menyimpan kegunaan tautan itu, boleh lebih dari satu.
 * - `postal_addresses` dan `electronic_addresses` menempel ke tempat.
 *
 * Id tautan lama dipakai ulang sebagai id tempat, sehingga `postal_addresses.location_id` tidak perlu
 * ditulis ulang — yang berubah hanya tabel yang ditunjuknya.
 *
 * Kontak party yang belum punya tempat berpindah ke satu tempat tanpa alamat pos bernama "Informasi kontak",
 * seperti informasi kontak party di Dynamics 365 yang tidak menunjuk alamat mana pun. Tautan itu tidak pernah
 * menjadi utama: alamat utama tetap alamat pos.
 *
 * Tanpa kelas `App\`: admin.erp ikut menjalankan migration Core.
 *
 * @kompatibel-mundur Tidak. `party_locations.name`, `party_locations.purpose`, dan
 * `electronic_addresses.party_id` dibuang, dan kode rilis sebelumnya membacanya. Per 29 September 2026 belum
 * ada pelanggan di CoreERP; `down()` hanya untuk pengembangan, dan mengembalikan satu kegunaan per tautan.
 */
return new class extends Migration
{
    private const PURPOSES = [
        ['business', 'Kantor / usaha', 10],
        ['delivery', 'Pengiriman', 20],
        ['invoice', 'Penagihan', 30],
        ['payment', 'Pembayaran', 40],
        ['home', 'Rumah', 50],
    ];

    private const CONTACT_LOCATION_NAME = 'Informasi kontak';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->createReferenceAndLocations();
            $this->turnPartyLocationsIntoLinks();
            $this->movePurposes();
            $this->pointPostalAddressesAtLocations();
            $this->moveContactsToLocations();
            $this->constrainPartyTypes();

            foreach (['locations', 'party_location_purposes'] as $table) {
                DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
                DB::statement("CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()");
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE parties DROP CONSTRAINT IF EXISTS parties_type_foreign');

            // Kontak kembali ke party pemilik tautan tertua ke tempatnya.
            Schema::table('electronic_addresses', function (Blueprint $table): void {
                $table->ulid('party_id')->nullable();
            });
            DB::statement('UPDATE electronic_addresses e SET party_id = (
                SELECT pl.party_id FROM party_locations pl WHERE pl.location_id = e.location_id ORDER BY pl.created_at LIMIT 1)');
            DB::statement('DELETE FROM electronic_addresses WHERE party_id IS NULL OR deleted_at IS NOT NULL');
            DB::statement('DROP INDEX IF EXISTS electronic_addresses_one_primary');
            Schema::table('electronic_addresses', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'location_id']);
                $table->dropIndex(['tenant_id', 'location_id']);
                $table->dropColumn(['location_id', 'deleted_at']);
                $table->index(['tenant_id', 'party_id']);
                $table->foreign(['tenant_id', 'party_id'])->references(['tenant_id', 'id'])->on('parties')->cascadeOnDelete();
            });
            DB::statement('ALTER TABLE electronic_addresses ALTER COLUMN party_id SET NOT NULL');
            DB::statement('CREATE UNIQUE INDEX electronic_addresses_one_primary ON electronic_addresses (tenant_id, party_id, type) WHERE is_primary');

            Schema::table('party_locations', function (Blueprint $table): void {
                $table->string('name', 120)->nullable();
                $table->string('purpose', 30)->nullable();
            });
            DB::statement('UPDATE party_locations pl SET name = l.name FROM locations l WHERE l.id = pl.location_id');
            DB::statement("UPDATE party_locations pl SET purpose = COALESCE((
                SELECT p.purpose_code FROM party_location_purposes p
                WHERE p.party_location_id = pl.id AND p.deleted_at IS NULL ORDER BY p.created_at LIMIT 1), 'business')");
            DB::statement('DELETE FROM party_locations WHERE deleted_at IS NOT NULL OR location_id <> id');
            DB::statement('ALTER TABLE party_locations ALTER COLUMN name SET NOT NULL');
            DB::statement('ALTER TABLE party_locations ALTER COLUMN purpose SET NOT NULL');

            Schema::table('postal_addresses', function (Blueprint $table): void {
                $table->dropUnique(['tenant_id', 'location_id']);
                $table->dropForeign(['tenant_id', 'location_id']);
            });
            DB::statement('DELETE FROM postal_addresses pa WHERE NOT EXISTS (SELECT 1 FROM party_locations pl WHERE pl.id = pa.location_id)');
            Schema::table('postal_addresses', function (Blueprint $table): void {
                $table->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('party_locations')->cascadeOnDelete();
            });

            Schema::dropIfExists('party_location_purposes');

            DB::statement('DROP INDEX IF EXISTS party_locations_one_primary');
            DB::statement('DROP INDEX IF EXISTS party_locations_one_link');
            Schema::table('party_locations', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'location_id']);
                $table->dropColumn(['location_id', 'deleted_at']);
            });
            DB::statement('CREATE UNIQUE INDEX party_locations_one_primary ON party_locations (tenant_id, party_id) WHERE is_primary');

            Schema::dropIfExists('locations');
            Schema::dropIfExists('location_purposes');
        });
    }

    private function createReferenceAndLocations(): void
    {
        // Referensi platform, bukan milik tenant: daftar kegunaan yang dikenal kode.
        Schema::create('location_purposes', function (Blueprint $table): void {
            $table->string('code', 30)->primary();
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });
        $now = now();
        DB::table('location_purposes')->insert(array_map(
            fn (array $purpose): array => ['code' => $purpose[0], 'name' => $purpose[1], 'sort_order' => $purpose[2], 'created_at' => $now, 'updated_at' => $now],
            self::PURPOSES,
        ));

        Schema::create('locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Tanpa foreign key ke `tenants` (sisi pusat, dibatasi `FkMenyeberangBatasTest`). Tabel yang menunjuk
            // tempat memakai composite key `(tenant_id, location_id)`, jadi tenant lain tetap ditolak database.
            $table->ulid('tenant_id');
            $table->string('name', 120);
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();

            $table->unique(['tenant_id', 'id']);
        });

        DB::statement('INSERT INTO locations (id, tenant_id, name, created_at, updated_at, created_by_user_id, updated_by_user_id)
            SELECT id, tenant_id, name, created_at, updated_at, created_by_user_id, updated_by_user_id FROM party_locations');
    }

    private function turnPartyLocationsIntoLinks(): void
    {
        Schema::table('party_locations', function (Blueprint $table): void {
            $table->ulid('location_id')->nullable();
            $table->softDeletes();
        });
        DB::statement('UPDATE party_locations SET location_id = id');
        DB::statement('ALTER TABLE party_locations ALTER COLUMN location_id SET NOT NULL');

        Schema::table('party_locations', function (Blueprint $table): void {
            // `restrict`: tempat yang masih ditautkan tidak boleh hilang dari bawah tautannya.
            $table->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('locations')->restrictOnDelete();
            $table->index(['tenant_id', 'location_id']);
        });

        // Satu utama per party di antara tautan yang masih berlaku, dan satu tautan per pasangan party–tempat.
        DB::statement('DROP INDEX IF EXISTS party_locations_one_primary');
        DB::statement('CREATE UNIQUE INDEX party_locations_one_primary ON party_locations (tenant_id, party_id) WHERE is_primary AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX party_locations_one_link ON party_locations (tenant_id, party_id, location_id) WHERE deleted_at IS NULL');
    }

    private function movePurposes(): void
    {
        Schema::create('party_location_purposes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id');
            $table->ulid('party_location_id');
            $table->string('purpose_code', 30);
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'party_location_id']);
            $table->foreign(['tenant_id', 'party_location_id'])->references(['tenant_id', 'id'])->on('party_locations')->cascadeOnDelete();
            $table->foreign('purpose_code')->references('code')->on('location_purposes')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX party_location_purposes_one_each ON party_location_purposes (party_location_id, purpose_code) WHERE deleted_at IS NULL');

        foreach (DB::table('party_locations')->get(['id', 'tenant_id', 'purpose', 'created_at', 'updated_at']) as $link) {
            DB::table('party_location_purposes')->insert([
                'id' => strtolower((string) Str::ulid()),
                'tenant_id' => $link->tenant_id,
                'party_location_id' => $link->id,
                'purpose_code' => $link->purpose,
                'created_at' => $link->created_at,
                'updated_at' => $link->updated_at,
            ]);
        }

        Schema::table('party_locations', function (Blueprint $table): void {
            $table->dropColumn(['name', 'purpose']);
        });
    }

    private function pointPostalAddressesAtLocations(): void
    {
        Schema::table('postal_addresses', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'location_id']);
        });
        Schema::table('postal_addresses', function (Blueprint $table): void {
            // Alamat pos ikut hilang hanya bila tempatnya benar-benar dibuang, dan tempat tidak pernah dibuang
            // aplikasi: melepas tautan tidak menyentuh tempatnya.
            $table->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('locations')->cascadeOnDelete();
            $table->unique(['tenant_id', 'location_id']);
        });
    }

    private function moveContactsToLocations(): void
    {
        Schema::table('electronic_addresses', function (Blueprint $table): void {
            $table->ulid('location_id')->nullable();
            $table->softDeletes();
        });

        $parties = DB::table('electronic_addresses')->select('tenant_id', 'party_id')->distinct()->get();
        foreach ($parties as $party) {
            $locationId = DB::table('party_locations')->where('party_id', $party->party_id)
                ->orderByDesc('is_primary')->orderBy('created_at')->value('location_id');

            if ($locationId === null) {
                $locationId = strtolower((string) Str::ulid());
                $now = now();
                DB::table('locations')->insert([
                    'id' => $locationId, 'tenant_id' => $party->tenant_id, 'name' => self::CONTACT_LOCATION_NAME,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('party_locations')->insert([
                    'id' => strtolower((string) Str::ulid()), 'tenant_id' => $party->tenant_id, 'party_id' => $party->party_id,
                    'location_id' => $locationId, 'is_primary' => false, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            DB::table('electronic_addresses')->where('party_id', $party->party_id)->update(['location_id' => $locationId]);
        }

        DB::statement('DROP INDEX IF EXISTS electronic_addresses_one_primary');
        Schema::table('electronic_addresses', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'party_id']);
            $table->dropIndex(['tenant_id', 'party_id']);
            $table->dropColumn('party_id');
        });
        DB::statement('ALTER TABLE electronic_addresses ALTER COLUMN location_id SET NOT NULL');
        Schema::table('electronic_addresses', function (Blueprint $table): void {
            $table->index(['tenant_id', 'location_id']);
            $table->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('locations')->cascadeOnDelete();
        });
        // Satu kontak utama per tempat per jenis, di antara yang belum diarsipkan.
        DB::statement('CREATE UNIQUE INDEX electronic_addresses_one_primary ON electronic_addresses (tenant_id, location_id, type) WHERE is_primary AND deleted_at IS NULL');
    }

    /**
     * `parties.type` tetap bentuk nama — `person` atau `organization` — dan kini dijaga database ke
     * `party_types`. Party legal entity dan operating unit sengaja tidak diubah jenisnya: kontrak vendor
     * menerbitkan `party_type` sebagai `organization|person`, dan organisasi sudah dikenali lewat
     * `organization_parties`.
     */
    private function constrainPartyTypes(): void
    {
        DB::statement('ALTER TABLE parties ADD CONSTRAINT parties_type_foreign FOREIGN KEY (type) REFERENCES party_types (code) ON DELETE RESTRICT');
    }
};
