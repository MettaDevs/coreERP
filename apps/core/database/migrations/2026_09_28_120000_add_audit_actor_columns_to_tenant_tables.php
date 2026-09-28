<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom jejak pembuat dan pengubah terakhir di setiap tabel tenant milik Core (K-01, area 1 TODO
 * analisa gap BC fase 1), beserta fungsi trigger yang mengisinya dari `coreerp.user_id`.
 *
 * Migration ini tidak memakai `AuditColumns`: admin.erp ikut menjalankan migration Core, dan kelas
 * Core tidak ada di sana. Nama kolom, trigger, dan fungsinya harus sama dengan konstanta di kelas itu.
 *
 * Tabel sisi pusat (model ber-`OwnedByControlPlane`) tidak ikut: pemiliknya admin.erp, dan sebagian
 * sudah punya `created_by` sendiri. Tabel module ditangani migration module masing-masing, karena
 * Core tidak menyentuh tabel module.
 *
 * Aturan trigger: saat INSERT, pelaku sesi mengisi keduanya; nilai yang ditulis kode hanya dipakai
 * bila sesi tidak punya pelaku (seeder, impor). Saat UPDATE, pembuat tidak dapat diubah, dan pengubah
 * selalu pelaku sesi — kosong berarti diubah sistem, bukan pengguna terakhir yang kebetulan tercatat.
 *
 * @kompatibel-mundur `vendors.created_by_user_id` dan `integration_clients.created_by_user_id` berubah
 * dari string ke bigint. Rilis sebelumnya menulis `(string) $user->id`, teks angka yang diterima
 * PostgreSQL untuk kolom bigint, dan tidak ada kode rilis sebelumnya yang membaca kolom itu.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'access_audit_events', 'app_service_credentials', 'automatic_role_assignment_rules',
        'core_module_installations', 'currency_precisions', 'electronic_addresses',
        'finance_posting_deliveries', 'finance_posting_events', 'finance_posting_lines',
        'finance_posting_settings', 'finance_postings', 'finance_reference_account_imports',
        'finance_reference_accounts', 'finance_settlement_modes', 'fiscal_calendars', 'integration_clients',
        'invitation_codes', 'legal_entities', 'operating_units', 'organization_hierarchies',
        'organization_parties', 'organizations', 'outbox_events', 'parties', 'party_locations',
        'party_role_registrations', 'postal_addresses', 'print_identities', 'ref_buildings', 'ref_districts',
        'ref_group_of_houses', 'ref_land_plots', 'ref_postal_codes', 'ref_provinces', 'ref_regencies',
        'ref_streets', 'ref_villages', 'report_exports', 'report_layout_defaults', 'report_layouts',
        'role_assignment_data_policy_scopes', 'roles', 'security_duties', 'security_privileges',
        'security_role_children', 'sod_conflicts', 'sod_rules', 'tenant_app_entitlements',
        'tenant_deployments', 'tenant_number_sequences', 'units_of_measure', 'uom_classes',
        'uom_conversions', 'uom_external_codes', 'uom_systems', 'uom_translations', 'vendors',
        'workflow_configurations', 'workflow_history', 'workflow_instances', 'workflow_parameters',
        'workflow_work_items', 'working_time_calendar_days', 'working_time_calendar_lines',
        'working_time_calendars', 'working_time_lines', 'working_time_templates',
    ];

    /** Kolom pembuat yang sudah ada sebagai teks sebelum K-01. */
    private const TEXT_CREATOR_COLUMNS = ['integration_clients', 'vendors'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION coreerp_stamp_audit_actor() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                actor bigint := NULLIF(current_setting('coreerp.user_id', true), '')::bigint;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    NEW.created_by_user_id := COALESCE(actor, NEW.created_by_user_id);
                    NEW.updated_by_user_id := COALESCE(actor, NEW.updated_by_user_id, NEW.created_by_user_id);
                ELSE
                    NEW.created_by_user_id := OLD.created_by_user_id;
                    NEW.updated_by_user_id := actor;
                END IF;
                RETURN NEW;
            END
            $$
            SQL);

        foreach (self::TEXT_CREATOR_COLUMNS as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN created_by_user_id TYPE bigint"
                ." USING NULLIF(created_by_user_id, '')::bigint");
        }

        foreach (self::TABLES as $table) {
            $hasCreator = Schema::hasColumn($table, 'created_by_user_id');

            Schema::table($table, function (Blueprint $blueprint) use ($hasCreator): void {
                if (! $hasCreator) {
                    $blueprint->unsignedBigInteger('created_by_user_id')->nullable();
                }
                $blueprint->unsignedBigInteger('updated_by_user_id')->nullable();
            });

            DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP TRIGGER IF EXISTS stamp_audit_actor ON {$table}");

            $keepCreator = $table === 'report_layouts' || in_array($table, self::TEXT_CREATOR_COLUMNS, true);
            Schema::table($table, function (Blueprint $blueprint) use ($keepCreator): void {
                $blueprint->dropColumn($keepCreator
                    ? ['updated_by_user_id']
                    : ['created_by_user_id', 'updated_by_user_id']);
            });
        }

        foreach (self::TEXT_CREATOR_COLUMNS as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN created_by_user_id TYPE varchar(64)");
        }

        DB::statement('DROP FUNCTION IF EXISTS coreerp_stamp_audit_actor()');
    }
};
