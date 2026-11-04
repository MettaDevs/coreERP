-- Versi konteks keamanan untuk cache konteks per proses (optimized.md, butir 1 dan 2).
-- Trigger statement-level menaikkan versi pada setiap perubahan tabel yang membentuk konteks request,
-- termasuk perubahan langsung lewat SQL, sehingga pencabutan berlaku pada request berikutnya.
create table if not exists security_context_versions (
    id smallint primary key check (id = 1),
    version bigint not null
);

insert into security_context_versions (id, version) values (1, 1) on conflict (id) do nothing;

create or replace function bump_security_context_version() returns trigger language plpgsql as $$
begin
    update security_context_versions set version = version + 1 where id = 1;
    return null;
end
$$;

do $$
declare
    source_table text;
    user_columns text;
begin
    foreach source_table in array array[
        'tenants', 'tenant_memberships', 'role_assignments', 'role_assignment_data_policy_scopes',
        'organization_hierarchy_closures', 'organizations', 'roles', 'security_role_children',
        'security_role_duties', 'security_duty_privileges', 'security_privilege_permissions', 'permissions'
    ] loop
        execute format('drop trigger if exists bump_security_context_version on %I', source_table);
        execute format('create trigger bump_security_context_version after insert or update or delete or truncate on %I '
                       'for each statement execute function bump_security_context_version()', source_table);
    end loop;
    -- Login menulis last_login_at dan updated_at pada setiap login; kolom itu tidak membentuk konteks, jadi tidak
    -- boleh mengosongkan cache semua user. Perubahan kolom lain (status, password wajib diganti, nama) tetap menaikkan versi.
    select string_agg(quote_ident(column_name), ', ' order by ordinal_position) into user_columns
    from information_schema.columns
    where table_schema = current_schema() and table_name = 'users'
      and column_name not in ('last_login_at', 'updated_at', 'remember_token');
    drop trigger if exists bump_security_context_version on users;
    drop trigger if exists bump_security_context_version_update on users;
    create trigger bump_security_context_version after insert or delete or truncate on users
        for each statement execute function bump_security_context_version();
    execute format('create trigger bump_security_context_version_update after update of %s on users '
                   'for each statement execute function bump_security_context_version()', user_columns);
end
$$;
