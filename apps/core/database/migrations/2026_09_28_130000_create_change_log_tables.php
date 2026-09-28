<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log perubahan per field, padanan Change Log Business Central (area 2 TODO analisa gap BC fase 1, K-02).
 *
 * Tiga tabel dengan peran yang sama dengan BC: `change_log_setup_tables` (Change Log Setup (Table)),
 * `change_log_setup_fields` (Change Log Setup (Field)), dan `change_log_entries` (Change Log Entry), satu
 * tabel entri untuk semua record. Per tabel, untuk penambahan, perubahan, dan penghapusan, dipilih `none`,
 * `some` (hanya field yang dicentang di setup field), atau `all`.
 *
 * Baris setup ber-`tenant_id` kosong adalah **bawaan**: didaftarkan module lewat `ChangeLogDefaults`
 * (atau Core sendiri) beserta nama tabel dan field yang dibaca pengguna. Tenant yang menyimpan setelannya
 * sendiri mendapat baris ber-`tenant_id`, dan baris itu menggantikan bawaan untuk tabel tersebut, termasuk
 * daftar field-nya.
 *
 * Penangkapnya trigger `AFTER` `coreerp_log_change`, bukan event Eloquent: update lewat query builder tidak
 * melewati event model. Trigger terpasang di setiap tabel tenant dan keluar lebih dulu — sebelum baris
 * diubah menjadi JSON — bila log tidak menyala, seperti `GetDatabaseTableTriggerSetup` di BC. Argumen
 * trigger adalah kolom kunci record; tanpa argumen, kuncinya `id`.
 *
 * Tabel keamanan dan setup log sendiri **selalu dicatat**, seperti `IsAlwaysLoggedTable` di BC: admin yang
 * mematikan log lalu mengubah peran tetap meninggalkan jejak. Baris bertenant kosong (katalog produk) tidak
 * dicatat. Pelaku dibaca dari `coreerp.user_id` (migration kolom jejak); `coreerp.change_log = off`
 * mematikannya selama migration. Arsip di aplikasi ini adalah pengisian `deleted_at`, jadi tercatat sebagai
 * perubahan field itu.
 *
 * Tanpa kelas `App\`: admin.erp ikut menjalankan migration Core.
 */
return new class extends Migration
{
    /** Tabel tenant Core yang sudah ada, sama dengan migration kolom jejak, beserta kolom kunci yang bukan `id`. */
    private const TABLES = [
        'access_audit_events' => [], 'app_service_credentials' => [], 'automatic_role_assignment_rules' => [],
        'core_module_installations' => ['module_id'], 'currency_precisions' => [], 'electronic_addresses' => [],
        'finance_posting_deliveries' => [], 'finance_posting_events' => [], 'finance_posting_lines' => [],
        'finance_posting_settings' => ['legal_entity_id'], 'finance_postings' => [],
        'finance_reference_account_imports' => [], 'finance_reference_accounts' => [],
        'finance_settlement_modes' => [], 'fiscal_calendars' => [], 'integration_clients' => [],
        'invitation_codes' => [], 'legal_entities' => [], 'operating_units' => [], 'organization_hierarchies' => [],
        'organization_parties' => [], 'organizations' => [], 'outbox_events' => [], 'parties' => [],
        'party_locations' => [], 'party_role_registrations' => [], 'postal_addresses' => [],
        'print_identities' => [], 'ref_buildings' => [], 'ref_districts' => [], 'ref_group_of_houses' => [],
        'ref_land_plots' => [], 'ref_postal_codes' => [], 'ref_provinces' => [], 'ref_regencies' => [],
        'ref_streets' => [], 'ref_villages' => [], 'report_exports' => [], 'report_layout_defaults' => [],
        'report_layouts' => [], 'role_assignment_data_policy_scopes' => [], 'roles' => [],
        'security_duties' => [], 'security_privileges' => [],
        'security_role_children' => ['parent_role_id', 'child_role_id'], 'sod_conflicts' => [], 'sod_rules' => [],
        'tenant_app_entitlements' => [], 'tenant_deployments' => [], 'tenant_number_sequences' => [],
        'units_of_measure' => [], 'uom_classes' => [], 'uom_conversions' => [], 'uom_external_codes' => [],
        'uom_systems' => [], 'uom_translations' => [], 'vendors' => [], 'workflow_configurations' => [],
        'workflow_history' => [], 'workflow_instances' => [], 'workflow_parameters' => [],
        'workflow_work_items' => [], 'working_time_calendar_days' => [], 'working_time_calendar_lines' => [],
        'working_time_calendars' => [], 'working_time_lines' => [], 'working_time_templates' => [],
    ];

    private const NEW_TABLES = ['change_log_setup_tables', 'change_log_setup_fields', 'change_log_entries'];

    public function up(): void
    {
        Schema::create('change_log_setup_tables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->nullable();
            $table->string('table_name', 100);
            $table->string('table_caption', 150)->nullable();
            $table->string('log_insertion', 10)->default('none');
            $table->string('log_modification', 10)->default('none');
            $table->string('log_deletion', 10)->default('none');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('change_log_setup_fields', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->nullable();
            $table->string('table_name', 100);
            $table->string('field_name', 100);
            $table->string('field_caption', 150)->nullable();
            $table->boolean('log_insertion')->default(false);
            $table->boolean('log_modification')->default(false);
            $table->boolean('log_deletion')->default(false);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('change_log_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('tenant_id');
            $table->timestampTz('changed_at')->useCurrent();
            $table->string('table_name', 100);
            $table->string('record_id', 191)->nullable();
            $table->string('field_name', 100);
            $table->string('change_type', 12);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            // Pelakunya `created_by_user_id`, diisi trigger kolom jejak dari sesi yang sama.
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            // Kunci sekunder BC (tabel + record) yang membuat riwayat satu record cepat dibaca.
            $table->index(['tenant_id', 'table_name', 'record_id', 'id']);
        });

        foreach (['change_log_setup_tables' => 'tenant_id, table_name', 'change_log_setup_fields' => 'tenant_id, table_name, field_name'] as $table => $columns) {
            // Bawaan (tenant kosong) juga unik per tabel dan field.
            DB::statement("CREATE UNIQUE INDEX {$table}_scope_unique ON {$table} ({$columns}) NULLS NOT DISTINCT");
        }
        DB::statement("ALTER TABLE change_log_setup_tables ADD CONSTRAINT change_log_setup_tables_mode_check CHECK (log_insertion IN ('none', 'some', 'all') AND log_modification IN ('none', 'some', 'all') AND log_deletion IN ('none', 'some', 'all'))");
        DB::statement("ALTER TABLE change_log_entries ADD CONSTRAINT change_log_entries_type_check CHECK (change_type IN ('insertion', 'modification', 'deletion'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION coreerp_log_change() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                tenant text;
                source text;
                mode text;
                rec jsonb;
                old_row jsonb;
                new_row jsonb;
                record_key text;
                change_kind text;
                field text;
                i integer;
            BEGIN
                IF COALESCE(current_setting('coreerp.change_log', true), '') = 'off' OR TG_TABLE_NAME = 'change_log_entries' THEN
                    RETURN NULL;
                END IF;

                IF TG_OP = 'DELETE' THEN tenant := OLD.tenant_id::text; ELSE tenant := NEW.tenant_id::text; END IF;
                IF tenant IS NULL THEN
                    RETURN NULL;
                END IF;

                IF TG_TABLE_NAME IN ('change_log_setup_tables', 'change_log_setup_fields', 'roles', 'security_duties',
                        'security_role_children', 'role_assignment_data_policy_scopes', 'automatic_role_assignment_rules', 'sod_rules') THEN
                    mode := 'all';
                ELSE
                    SELECT CASE TG_OP WHEN 'INSERT' THEN log_insertion WHEN 'UPDATE' THEN log_modification ELSE log_deletion END
                      INTO mode FROM change_log_setup_tables WHERE tenant_id = tenant AND table_name = TG_TABLE_NAME;
                    IF FOUND THEN
                        source := tenant;
                    ELSE
                        SELECT CASE TG_OP WHEN 'INSERT' THEN log_insertion WHEN 'UPDATE' THEN log_modification ELSE log_deletion END
                          INTO mode FROM change_log_setup_tables WHERE tenant_id IS NULL AND table_name = TG_TABLE_NAME;
                    END IF;
                END IF;

                IF mode IS NULL OR mode = 'none' THEN
                    RETURN NULL;
                END IF;

                IF TG_OP <> 'INSERT' THEN old_row := to_jsonb(OLD); END IF;
                IF TG_OP <> 'DELETE' THEN new_row := to_jsonb(NEW); END IF;
                rec := COALESCE(new_row, old_row);
                change_kind := CASE TG_OP WHEN 'INSERT' THEN 'insertion' WHEN 'UPDATE' THEN 'modification' ELSE 'deletion' END;

                IF TG_NARGS = 0 THEN
                    record_key := rec ->> 'id';
                ELSE
                    record_key := '';
                    FOR i IN 0 .. TG_NARGS - 1 LOOP
                        record_key := record_key || CASE WHEN i > 0 THEN ',' ELSE '' END || COALESCE(rec ->> TG_ARGV[i], '');
                    END LOOP;
                END IF;

                FOR field IN SELECT jsonb_object_keys(rec) LOOP
                    CONTINUE WHEN field IN ('id', 'tenant_id', 'created_at', 'updated_at', 'created_by_user_id', 'updated_by_user_id');
                    CONTINUE WHEN TG_OP = 'UPDATE' AND (old_row -> field) IS NOT DISTINCT FROM (new_row -> field);
                    CONTINUE WHEN TG_OP = 'INSERT' AND (new_row -> field) = 'null'::jsonb;
                    CONTINUE WHEN TG_OP = 'DELETE' AND (old_row -> field) = 'null'::jsonb;
                    IF mode = 'some' AND NOT EXISTS (
                        SELECT 1 FROM change_log_setup_fields f
                         WHERE f.tenant_id IS NOT DISTINCT FROM source AND f.table_name = TG_TABLE_NAME AND f.field_name = field
                           AND CASE TG_OP WHEN 'INSERT' THEN f.log_insertion WHEN 'UPDATE' THEN f.log_modification ELSE f.log_deletion END
                    ) THEN
                        CONTINUE;
                    END IF;

                    INSERT INTO change_log_entries (tenant_id, table_name, record_id, field_name, change_type, old_value, new_value)
                    VALUES (tenant, TG_TABLE_NAME, record_key, field, change_kind, old_row ->> field, new_row ->> field);
                END LOOP;

                RETURN NULL;
            END
            $$;

            -- Entri log tidak dapat diubah. Penghapusan tetap boleh: itu jalur layanan retensi (area 4).
            CREATE OR REPLACE FUNCTION coreerp_forbid_change_log_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Entri log perubahan tidak dapat diubah.';
            END
            $$;
            SQL);

        DB::statement('CREATE TRIGGER forbid_update BEFORE UPDATE ON change_log_entries FOR EACH ROW EXECUTE FUNCTION coreerp_forbid_change_log_update()');

        foreach ([...self::TABLES, ...array_fill_keys(self::NEW_TABLES, [])] as $table => $keys) {
            $arguments = implode(', ', array_map(fn (string $key): string => "'{$key}'", $keys));
            DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
            DB::statement("CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_log_change({$arguments})");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS log_change ON {$table}");
        }
        foreach (array_reverse(self::NEW_TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS coreerp_log_change()');
        DB::statement('DROP FUNCTION IF EXISTS coreerp_forbid_change_log_update()');
    }
};
