<?php

declare(strict_types=1);

namespace App\Platform\ChangeLog\Support;

/**
 * Tabel yang selalu dicatat log perubahan, apa pun setelan tenant (`IsAlwaysLoggedTable` di BC): keamanan,
 * peran, dan setelan log itu sendiri. Daftar ini harus sama dengan daftar di fungsi SQL `coreerp_log_change`;
 * `RetentionServiceTest` membandingkan keduanya. Retensi memakainya untuk memberi entri tabel-tabel ini masa
 * simpan minimum yang lebih panjang.
 */
final class AlwaysLoggedTables
{
    public const NAMES = [
        'change_log_setup_tables', 'change_log_setup_fields', 'roles', 'role_assignments',
        'security_role_duties', 'security_role_children', 'security_duties', 'security_duty_privileges',
        'security_privileges', 'security_privilege_permissions', 'role_assignment_data_policy_scopes',
        'automatic_role_assignment_rules', 'sod_rules',
    ];
}
