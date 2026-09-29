<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versi baris di setiap tabel tenant milik Core (K-03, area 3 TODO analisa gap BC fase 1), padanan
 * `SystemRowVersion` BC, beserta fungsi trigger yang menaikkannya pada setiap UPDATE.
 *
 * Trigger, bukan kode aplikasi, dengan alasan yang sama seperti kolom jejak (K-02): update lewat query
 * builder, job latar, dan perintah artisan tidak melewati helper mana pun. Versi yang hanya naik bila
 * penulisnya ingat justru membuat form menimpa perubahan job tanpa ketahuan. Nilai `version` yang
 * ditulis kode diabaikan; `OLD.version + 1` selalu menang. Kode dokumen aset yang menulis
 * `version + 1` sendiri tetap menghasilkan angka yang sama.
 *
 * Migration ini tidak memakai `AuditColumns`: admin.erp ikut menjalankan migration Core, dan kelas Core
 * tidak ada di sana. Nama kolom, fungsi, dan trigger harus sama dengan konstanta di kelas itu.
 *
 * Daftar tabelnya sama dengan tabel yang membawa kolom jejak (area 1), ditambah tabel yang lahir sesudahnya.
 * Tabel sisi pusat tidak ikut, kecuali `tenant_memberships`: keanggotaan diubah dari layar pengguna
 * Core, jadi ia butuh pengaman yang sama. `core_module_installations` juga tidak ikut: kolom `version`
 * di sana nomor rilis module yang terpasang, dan tidak ada form yang mengubahnya. Tabel module ditangani
 * migration module masing-masing.
 *
 * Log perubahan (gap 6) melewati kolom `version`, seperti `updated_at`: kenaikannya bukan perubahan
 * yang dibuat orang.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'access_audit_events', 'app_service_credentials', 'automatic_role_assignment_rules', 'change_log_entries',
        'change_log_setup_fields', 'change_log_setup_tables', 'currency_precisions',
        'electronic_addresses', 'finance_posting_deliveries', 'finance_posting_events', 'finance_posting_lines',
        'finance_posting_settings', 'finance_postings', 'finance_reference_account_imports',
        'finance_reference_accounts', 'finance_settlement_modes', 'fiscal_calendars', 'integration_clients',
        'invitation_codes', 'legal_entities', 'locations', 'operating_units', 'organization_hierarchies',
        'organization_parties', 'organizations', 'outbox_events', 'parties', 'party_location_purposes',
        'party_locations', 'party_role_registrations', 'postal_addresses', 'print_identities', 'ref_buildings',
        'ref_districts', 'ref_group_of_houses', 'ref_land_plots', 'ref_postal_codes', 'ref_provinces',
        'ref_regencies', 'ref_streets', 'ref_villages', 'report_exports', 'report_layout_defaults',
        'report_layouts', 'role_assignment_data_policy_scopes', 'roles', 'security_duties', 'security_privileges',
        'security_role_children', 'sod_conflicts', 'sod_rules', 'tenant_app_entitlements', 'tenant_deployments',
        'tenant_memberships', 'tenant_number_sequences', 'units_of_measure', 'uom_classes', 'uom_conversions',
        'uom_external_codes', 'uom_systems', 'uom_translations', 'vendors', 'workflow_configurations',
        'workflow_history', 'workflow_instances', 'workflow_parameters', 'workflow_work_items',
        'working_time_calendar_days', 'working_time_calendar_lines', 'working_time_calendars',
        'working_time_lines', 'working_time_templates',
    ];

    /** Kolom yang dilewati log perubahan, sebelum dan sesudah migration ini. */
    private const LOG_SKIP_BEFORE = "CONTINUE WHEN field IN ('id', 'tenant_id', 'created_at', 'updated_at', 'created_by_user_id', 'updated_by_user_id');";

    private const LOG_SKIP_AFTER = "CONTINUE WHEN field IN ('id', 'tenant_id', 'created_at', 'updated_at', 'created_by_user_id', 'updated_by_user_id', 'version');";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION coreerp_bump_row_version() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.version := OLD.version + 1;
                RETURN NEW;
            END
            $$
            SQL);

        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'version')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedInteger('version')->default(1));
            }

            DB::statement("CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()");
        }

        $this->replaceInLogFunction(self::LOG_SKIP_BEFORE, self::LOG_SKIP_AFTER);
    }

    public function down(): void
    {
        $this->replaceInLogFunction(self::LOG_SKIP_AFTER, self::LOG_SKIP_BEFORE);

        foreach (self::TABLES as $table) {
            DB::statement("DROP TRIGGER IF EXISTS bump_row_version ON {$table}");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('version'));
        }

        DB::statement('DROP FUNCTION IF EXISTS coreerp_bump_row_version()');
    }

    /**
     * Mengganti satu baris di badan `coreerp_log_change` tanpa menyalin ulang seluruh fungsinya. Baris
     * yang dicari wajib ada tepat satu kali; bila fungsinya sudah berubah bentuk, migration ini gagal
     * alih-alih diam-diam tidak mengubah apa pun.
     */
    private function replaceInLogFunction(string $search, string $replace): void
    {
        $definition = DB::selectOne("select pg_get_functiondef('coreerp_log_change'::regproc) as definition")->definition;

        if (substr_count($definition, $search) !== 1) {
            throw new RuntimeException('Badan coreerp_log_change tidak memuat baris pengecualian kolom yang diharapkan.');
        }

        DB::unprepared(str_replace($search, $replace, $definition));
    }
};
