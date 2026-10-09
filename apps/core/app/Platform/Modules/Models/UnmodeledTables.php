<?php

declare(strict_types=1);

namespace App\Platform\Modules\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassificationRegistry;

/**
 * Klasifikasi data tabel tenant milik Core yang tidak punya model Eloquent (K-19). Tabel yang punya
 * model diklasifikasi di modelnya sendiri. Kolom jejak `created_by_user_id` dan `updated_by_user_id`
 * sudah diklasifikasi platform lewat `AuditColumns::COLUMN_CLASSIFICATION`.
 */
final class UnmodeledTables implements DataClassificationRegistry
{
    public static function tables(): array
    {
        return [
            // Jejak akses: isi payload bisa memuat email undangan dan subject SSO.
            'access_audit_events' => ['default' => DataClass::SystemMetadata, 'columns' => [
                'membership_id' => DataClass::EndUserPseudonymousIdentifiers,
                'payload' => DataClass::EndUserIdentifiableInformation,
            ]],
            'automatic_role_assignment_rules' => ['default' => DataClass::CustomerContent],
            // Nilai lama dan baru menyalin kolom tabel apa pun yang dicatat, termasuk nama dan email.
            'change_log_entries' => ['default' => DataClass::CustomerContent, 'columns' => [
                'old_value' => DataClass::EndUserIdentifiableInformation,
                'new_value' => DataClass::EndUserIdentifiableInformation,
            ]],
            'change_log_setup_fields' => ['default' => DataClass::CustomerContent],
            'change_log_setup_tables' => ['default' => DataClass::CustomerContent],
            // Isi event menyalin record sumbernya, misalnya pekerja HR beserta nama dan emailnya.
            'outbox_events' => ['default' => DataClass::CustomerContent, 'columns' => [
                'payload' => DataClass::EndUserIdentifiableInformation,
            ]],
            // Kop cetak entitas legal: nama, alamat di baris induk dan kaki, nomor pajak dan registrasi.
            'print_identities' => ['default' => DataClass::CustomerContent, 'columns' => [
                'display_name' => DataClass::OrganizationIdentifiableInformation,
                'parent_lines' => DataClass::OrganizationIdentifiableInformation,
                'tax_id' => DataClass::OrganizationIdentifiableInformation,
                'registration_id' => DataClass::OrganizationIdentifiableInformation,
                'footer_text' => DataClass::OrganizationIdentifiableInformation,
            ]],
            'report_exports' => ['default' => DataClass::CustomerContent, 'columns' => [
                'membership_id' => DataClass::EndUserPseudonymousIdentifiers,
                'user_id' => DataClass::EndUserPseudonymousIdentifiers,
                'failure_message' => DataClass::SystemMetadata,
            ]],
            'report_layout_defaults' => ['default' => DataClass::CustomerContent],
            'report_layouts' => ['default' => DataClass::CustomerContent, 'columns' => [
                'name' => DataClass::CustomerContent,
            ]],
            'retention_policy_log_entries' => ['default' => DataClass::SystemMetadata],
            'retention_policy_setups' => ['default' => DataClass::CustomerContent],
            'security_role_children' => ['default' => DataClass::CustomerContent],
            // Catatan mitigasi menjelaskan kenapa orang tertentu memegang duty yang bertentangan.
            'sod_conflicts' => ['default' => DataClass::CustomerContent, 'columns' => [
                'membership_id' => DataClass::EndUserPseudonymousIdentifiers,
                'approved_by_membership_id' => DataClass::EndUserPseudonymousIdentifiers,
                'mitigation_note' => DataClass::EndUserIdentifiableInformation,
            ]],
            'sod_rules' => ['default' => DataClass::CustomerContent],
            'tenant_deployments' => ['default' => DataClass::SystemMetadata],
            'uom_classes' => ['default' => DataClass::CustomerContent, 'columns' => [
                'name' => DataClass::CustomerContent,
            ]],
            'uom_conversions' => ['default' => DataClass::CustomerContent],
            'uom_external_codes' => ['default' => DataClass::CustomerContent],
            'uom_systems' => ['default' => DataClass::CustomerContent, 'columns' => [
                'name' => DataClass::CustomerContent,
            ]],
            'uom_translations' => ['default' => DataClass::CustomerContent, 'columns' => [
                'name' => DataClass::CustomerContent,
            ]],
            'workflow_configurations' => ['default' => DataClass::CustomerContent, 'columns' => [
                'name' => DataClass::CustomerContent,
            ]],
            'workflow_history' => ['default' => DataClass::CustomerContent, 'columns' => [
                'actor_membership_id' => DataClass::EndUserPseudonymousIdentifiers,
            ]],
            'workflow_instances' => ['default' => DataClass::CustomerContent, 'columns' => [
                'initiator_membership_id' => DataClass::EndUserPseudonymousIdentifiers,
            ]],
            'workflow_parameters' => ['default' => DataClass::CustomerContent],
            'workflow_work_items' => ['default' => DataClass::CustomerContent, 'columns' => [
                'assigned_membership_id' => DataClass::EndUserPseudonymousIdentifiers,
                'review_url' => DataClass::SystemMetadata,
                'email_notified_at' => DataClass::SystemMetadata,
                'email_next_attempt_at' => DataClass::SystemMetadata,
                'email_attempts' => DataClass::SystemMetadata,
                'email_last_error' => DataClass::SystemMetadata,
            ]],
        ];
    }
}
