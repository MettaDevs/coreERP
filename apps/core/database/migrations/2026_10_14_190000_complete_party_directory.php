<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->softDeletes();
            $table->string('number', 40)->nullable();
            $table->string('creation_key', 160)->nullable();
            foreach (['known_as', 'language', 'personal_title', 'personal_suffix', 'first_name', 'middle_name', 'last_name', 'initials', 'professional_title', 'professional_suffix', 'gender', 'marital_status', 'organization_number', 'duns_number', 'abc_code'] as $field) {
                $table->string($field, 200)->nullable();
            }
            $table->date('birth_date')->nullable();
            $table->date('anniversary_date')->nullable();
            $table->unsignedInteger('number_of_children')->nullable();
            $table->unsignedInteger('number_of_employees')->nullable();
            $table->text('interests')->nullable();
            $table->text('memo')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX parties_number_active_unique ON parties (tenant_id, number) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX parties_creation_key_unique ON parties (tenant_id, creation_key) WHERE creation_key IS NOT NULL');
        Schema::table('postal_addresses', function (Blueprint $table): void {
            $table->string('village', 100)->nullable();
            $table->string('rt', 3)->nullable();
            $table->string('rw', 3)->nullable();
        });
        Schema::table('vendors', function (Blueprint $table): void {
            $table->unsignedInteger('payment_priority')->nullable();
        });
        DB::table('app_number_sequence_references')->insertOrIgnore([
            'id' => (string) str()->ulid(), 'app_id' => 'core', 'code' => 'core.party',
            'name' => 'Nomor pihak', 'default_prefix' => 'PIHK', 'allowed_scopes' => json_encode(['tenant']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('party_reference_values', function (Blueprint $table): void {
            $table->string('category', 40);
            $table->string('code', 40);
            $table->string('name', 120);
            $table->primary(['category', 'code']);
        });
        Schema::create('address_books', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name', 120);
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX address_books_name_unique ON address_books (tenant_id, lower(name)) WHERE deleted_at IS NULL');

        Schema::create('party_address_books', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('party_id');
            $table->ulid('address_book_id');
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreign(['tenant_id', 'party_id'])->references(['tenant_id', 'id'])->on('parties');
            $table->foreign(['tenant_id', 'address_book_id'])->references(['tenant_id', 'id'])->on('address_books');
        });
        DB::statement('CREATE UNIQUE INDEX party_address_books_active_unique ON party_address_books (tenant_id, party_id, address_book_id) WHERE deleted_at IS NULL');

        Schema::create('party_relationship_types', function (Blueprint $table): void {
            $table->string('code', 40)->primary();
            $table->string('name', 120);
            $table->string('inverse_name', 120);
            $table->string('from_type', 20);
            $table->string('to_type', 20);
        });
        Schema::create('party_relationships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('from_party_id');
            $table->ulid('to_party_id');
            $table->string('relationship_type_code', 40);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreign(['tenant_id', 'from_party_id'])->references(['tenant_id', 'id'])->on('parties');
            $table->foreign(['tenant_id', 'to_party_id'])->references(['tenant_id', 'id'])->on('parties');
            $table->foreign('relationship_type_code')->references('code')->on('party_relationship_types');
        });
        DB::statement('CREATE UNIQUE INDEX party_relationships_active_unique ON party_relationships (tenant_id, from_party_id, to_party_id, relationship_type_code, valid_from) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE party_relationships ADD CONSTRAINT party_relationships_distinct_parties CHECK (from_party_id <> to_party_id)');
        DB::statement('ALTER TABLE party_relationships ADD CONSTRAINT party_relationships_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)');
        // Tanpa kelas Core, agar migration juga dapat dijalankan konsol operator.
        foreach (['address_books', 'party_address_books', 'party_relationships'] as $table) {
            DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
            DB::statement("CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()");
            DB::statement("CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()");
        }
    }

    public function down(): void
    {
        // Mundur image tidak menghapus identitas, alamat, atau hubungan pihak.
    }
};
