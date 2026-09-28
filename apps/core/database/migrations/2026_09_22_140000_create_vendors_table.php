<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor master di Core, mengikuti Dynamics 365 (K-06).
 *
 * Vendor bukan identitas baru: ia sebuah party di buku alamat yang memegang peran `vendor` pada satu
 * entitas legal. Nama, alamat, dan kontaknya milik party; yang disimpan di sini hanya yang khas
 * akun vendor — nomor, NPWP, dan status. Satu party paling banyak satu vendor per entitas legal,
 * sama seperti satu akun vendor per company di F&O.
 *
 * **Nomor vendor dari number sequence milik Core.** Referensi nomor selama ini selalu milik app
 * lewat manifest, dan Core sendiri tidak punya baris di katalog app. Migration ini menambahkan
 * baris `core` berstatus `internal` — tidak `available`, jadi tidak pernah tampil di peluncur,
 * pendaftaran, atau katalog produk — beserta referensi `core.vendor`: lingkup entitas legal, tidak
 * kontinu, boleh diketik manual supaya `Supplier_ID` lama dapat dipindahkan, tidak direset.
 * Urutan nomor per tenant dibuat saat vendor pertama disimpan (`CoreNumberSequences`).
 *
 * `tenant_id` tanpa foreign key ke `tenants` (sisi pusat). Entitas legal dan party ditunjuk lewat
 * foreign key gabungan dengan `tenant_id`, jadi database menolak entitas atau party tenant lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('apps')->insertOrIgnore([
            'id' => 'core',
            'name' => 'CoreERP',
            'version' => '1.0.0',
            'status' => 'internal',
            'database_name' => null,
            'description' => 'Pemilik referensi nomor milik Core sendiri, misalnya nomor vendor. Bukan produk yang dipasang.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_number_sequence_references')->insertOrIgnore([
            'id' => (string) str()->ulid(),
            'app_id' => 'core',
            'code' => 'core.vendor',
            'name' => 'Nomor vendor',
            'default_prefix' => 'VND',
            'allowed_scopes' => json_encode(['legal_entity']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::create('vendors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('legal_entity_id');
            $table->ulid('party_id');
            $table->string('number', 40);
            $table->string('tax_number', 32)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('creation_key', 160)->nullable();
            $table->string('created_by_user_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'legal_entity_id', 'number']);
            $table->unique(['tenant_id', 'legal_entity_id', 'party_id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'updated_at']);
            $table->foreign(['tenant_id', 'legal_entity_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'party_id'])
                ->references(['tenant_id', 'id'])->on('parties')->cascadeOnDelete();
        });

        DB::statement(
            "alter table vendors add constraint vendors_status_check check (status in ('active', 'inactive'))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
        DB::table('app_number_sequence_references')->where('code', 'core.vendor')->delete();
        DB::table('apps')->where('id', 'core')->where('status', 'internal')->delete();
    }
};
