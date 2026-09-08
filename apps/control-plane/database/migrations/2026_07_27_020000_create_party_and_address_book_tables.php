<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buku Alamat: party, lokasi, alamat pos, dan kontak elektronik.
 *
 * Mengikuti Global Address Book Dynamics 365, yang berada di `fin-ops-core` dan
 * bukan sebuah modul. Party adalah identitas satu pihak — orang atau organisasi —
 * beserta alamat dan kontaknya. Peran party (pelanggan, pemasok, pegawai) dimiliki
 * app masing-masing dan berlaku per legal entity; Core hanya menyimpan registry
 * perannya supaya pertanyaan "pemasok ini pelanggan kita juga?" dapat dijawab
 * tanpa query lintas database app.
 *
 * Legal entity dan operating unit ikut menjadi party, karena keduanya punya nama
 * dan alamat yang tercetak pada dokumen resmi. Itulah alasan party berada di Core:
 * identitas organisasi sudah di sini, dan memisahkannya berarti membelah satu
 * identitas ke dua database.
 *
 * Alamat dan kontak dibagi seluruh peran party. Mengubah alamat satu kali berarti
 * setiap peran ikut berubah — inilah manfaat utama yang hilang bila tiap app
 * menyimpan alamatnya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Diperlukan agar tabel anak dapat memakai composite foreign key, sehingga
        // database menolak induk milik tenant lain — bukan hanya service.
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
        });

        // Reference data global, bukan milik tenant. Kode ISO 3166-1 alpha-2.
        Schema::create('country_regions', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->char('iso3', 3)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('parties', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            // `person` atau `organization`. Menentukan bentuk nama, bukan perannya.
            $table->string('type', 20);
            $table->string('name', 200);
            // Bentuk nama yang dinormalisasi untuk pencarian: huruf kecil, spasi rapat.
            $table->string('search_name', 200);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'search_name']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('party_locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('party_id');
            $table->string('name', 120);
            // Kegunaan alamat: business, delivery, invoice, payment, home.
            $table->string('purpose', 30);
            $table->boolean('is_primary')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'party_id']);
            $table->foreign(['tenant_id', 'party_id'])
                ->references(['tenant_id', 'id'])->on('parties')->cascadeOnDelete();
        });

        Schema::create('postal_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('location_id');
            $table->char('country_region_code', 2);
            $table->string('province', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('street', 250)->nullable();
            $table->string('building', 120)->nullable();
            $table->string('postbox', 60)->nullable();
            $table->string('postal_code', 20)->nullable();
            // Bentuk tercetak saat alamat disimpan. Dokumen resmi menyalin bentuk ini
            // supaya perubahan alamat kemudian tidak menulis ulang dokumen lama.
            $table->string('formatted', 600);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'location_id']);
            $table->foreign(['tenant_id', 'location_id'])
                ->references(['tenant_id', 'id'])->on('party_locations')->cascadeOnDelete();
            $table->foreign('country_region_code')->references('code')->on('country_regions')->restrictOnDelete();
        });

        Schema::create('electronic_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('party_id');
            // `email`, `phone`, `whatsapp`, `fax`, atau `url`.
            $table->string('type', 20);
            $table->string('value', 250);
            $table->string('purpose', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'party_id']);
            $table->foreign(['tenant_id', 'party_id'])
                ->references(['tenant_id', 'id'])->on('parties')->cascadeOnDelete();
        });

        // Legal entity dan operating unit adalah party. Satu organisasi menunjuk
        // tepat satu party, dan satu party mewakili paling banyak satu organisasi.
        Schema::create('organization_parties', function (Blueprint $table): void {
            $table->ulid('organization_id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('party_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'party_id']);
            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'party_id'])
                ->references(['tenant_id', 'id'])->on('parties')->cascadeOnDelete();
        });

        // Registry peran. Ditulis app pemilik peran lewat API internal; datanya
        // bisnisnya sendiri tetap di database app. `legal_entity_id` wajib karena
        // peran party di Dynamics 365 berlaku per company, bukan global.
        Schema::create('party_role_registrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('party_id');
            // `customer`, `vendor`, `worker`, `contact`, `prospect`, `competitor`, `applicant`.
            $table->string('role_code', 40);
            $table->ulid('legal_entity_id');
            $table->string('owning_app_id', 80);
            $table->timestamps();

            $table->unique(['tenant_id', 'party_id', 'role_code', 'legal_entity_id'], 'party_role_unique');
            $table->index(['tenant_id', 'role_code']);
            $table->foreign(['tenant_id', 'party_id'])
                ->references(['tenant_id', 'id'])->on('parties')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->cascadeOnDelete();
        });

        // Satu party paling banyak punya satu lokasi utama, dan itu dijaga database.
        // Tanpa partial index ini, dua request serentak dapat menandai dua lokasi
        // sebagai utama dan dokumen akan memilih alamat yang berbeda-beda.
        DB::statement(
            'create unique index party_locations_one_primary
             on party_locations (tenant_id, party_id) where is_primary',
        );
        DB::statement(
            'create unique index electronic_addresses_one_primary
             on electronic_addresses (tenant_id, party_id, type) where is_primary',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('party_role_registrations');
        Schema::dropIfExists('organization_parties');
        Schema::dropIfExists('electronic_addresses');
        Schema::dropIfExists('postal_addresses');
        Schema::dropIfExists('party_locations');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('country_regions');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
