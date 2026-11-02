-- CPDB-01: metadata dan agregat saja. Tidak menampilkan query, payload, atau nilai secret.
WITH relations AS (
    SELECT c.oid, n.nspname AS schema, c.relname AS name, c.relkind,
           pg_get_userbyid(c.relowner) AS owner,
           CASE WHEN c.relkind IN ('r', 'p', 'm') THEN pg_total_relation_size(c.oid) ELSE 0 END AS bytes,
           c.reltuples::bigint AS estimated_rows
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%'
      AND n.nspname NOT LIKE 'pg_temp_%' AND c.relkind IN ('r', 'p', 'S', 'v', 'm', 'f')
), columns AS (
    SELECT r.schema, r.name AS relation, a.attname AS name,
           format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS not_null,
           a.attidentity AS identity, a.attgenerated AS generated
    FROM relations r JOIN pg_attribute a ON a.attrelid = r.oid
    WHERE a.attnum > 0 AND NOT a.attisdropped
), foreign_keys AS (
    SELECT n.nspname AS schema, c.conname AS name, s.relname AS source,
           tn.nspname AS target_schema, t.relname AS target,
           ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(num, ord)
                 JOIN pg_attribute a ON a.attrelid = s.oid AND a.attnum = k.num ORDER BY k.ord) AS columns,
           ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY k(num, ord)
                 JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.num ORDER BY k.ord) AS target_columns,
           c.confdeltype AS on_delete, c.convalidated AS validated
    FROM pg_constraint c JOIN pg_class s ON s.oid = c.conrelid
    JOIN pg_namespace n ON n.oid = s.relnamespace JOIN pg_class t ON t.oid = c.confrelid
    JOIN pg_namespace tn ON tn.oid = t.relnamespace
    WHERE c.contype = 'f' AND c.conrelid IN (SELECT oid FROM relations)
), routines AS (
    SELECT p.oid, n.nspname AS schema, p.proname AS name,
           pg_get_function_identity_arguments(p.oid) AS arguments,
           p.prokind AS kind, p.prosecdef AS security_definer, l.lanname AS language,
           md5(p.prosrc) AS body_md5,
           (SELECT e.extname FROM pg_depend d JOIN pg_extension e ON e.oid = d.refobjid
            WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e' LIMIT 1) AS extension,
           ARRAY(SELECT DISTINCT r.schema || '.' || r.name FROM relations r
                 WHERE p.prosrc ~ ('\m' || r.name || '\M') ORDER BY 1) AS lexical_relations
    FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace JOIN pg_language l ON l.oid = p.prolang
    WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_%'
), triggers AS (
    SELECT r.schema, t.tgname AS name, r.name AS relation, t.tgisinternal AS internal,
           t.tgenabled AS enabled, pn.nspname || '.' || p.proname AS function
    FROM pg_trigger t JOIN relations r ON r.oid = t.tgrelid
    JOIN pg_proc p ON p.oid = t.tgfoid JOIN pg_namespace pn ON pn.oid = p.pronamespace
), view_dependencies AS (
    SELECT DISTINCT v.schema, v.name AS view, n.nspname AS target_schema, c.relname AS target
    FROM relations v JOIN pg_rewrite rw ON rw.ev_class = v.oid
    JOIN pg_depend d ON d.classid = 'pg_rewrite'::regclass AND d.objid = rw.oid
    JOIN pg_class c ON d.refclassid = 'pg_class'::regclass AND c.oid = d.refobjid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE v.relkind IN ('v', 'm') AND c.oid <> v.oid
), sequence_dependencies AS (
    SELECT r.schema, r.name AS sequence, n.nspname AS target_schema, c.relname AS relation, a.attname AS column
    FROM relations r JOIN pg_depend d ON d.classid = 'pg_class'::regclass AND d.objid = r.oid
    JOIN pg_class c ON c.oid = d.refobjid JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = d.refobjsubid
    WHERE r.relkind = 'S' AND d.deptype IN ('a', 'i')
), indexes AS (
    SELECT r.schema, c.relname AS name, r.name AS relation, i.indisunique AS unique,
           i.indisprimary AS primary, i.indisvalid AS valid,
           i.indpred IS NOT NULL AS partial, md5(pg_get_expr(i.indpred,i.indrelid)) AS predicate_md5,
           ARRAY(SELECT coalesce(a.attname,'<expression>') FROM unnest(i.indkey) WITH ORDINALITY k(num,ord)
                 LEFT JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=k.num ORDER BY k.ord) AS columns
    FROM pg_index i JOIN relations r ON r.oid=i.indrelid JOIN pg_class c ON c.oid=i.indexrelid
), activity AS (
    SELECT datname AS database, usename AS role, application_name, backend_type, state,
           CASE WHEN state = 'active' THEN lower(substring(ltrim(query) FROM '^([a-zA-Z]+)')) ELSE NULL END AS active_statement,
           count(*) AS connections
    FROM pg_stat_activity GROUP BY 1,2,3,4,5,6
), roles AS (
    SELECT rolname AS name, rolsuper AS superuser, rolcreatedb AS create_database,
           rolcreaterole AS create_role, rolcanlogin AS login, rolbypassrls AS bypass_rls
    FROM pg_roles WHERE rolname = current_user
)
SELECT json_build_object(
    'captured_at', clock_timestamp(),
    'database', current_database(), 'role', current_user,
    'server_version', current_setting('server_version'), 'search_path', current_setting('search_path'),
    'transaction_read_only', current_setting('transaction_read_only'),
    'database_bytes', pg_database_size(current_database()),
    'settings', (SELECT json_object_agg(name, setting) FROM pg_settings WHERE name IN
        ('max_connections','shared_buffers','work_mem','wal_level','archive_mode','archive_timeout','max_wal_size')),
    'extensions', (SELECT json_agg(json_build_object('name', extname, 'version', extversion) ORDER BY extname) FROM pg_extension),
    'relations', (SELECT coalesce(json_agg(row_to_json(x) ORDER BY schema, name), '[]') FROM (SELECT schema,name,relkind,owner,bytes,estimated_rows FROM relations) x),
    'columns', (SELECT coalesce(json_agg(columns ORDER BY schema, relation, name), '[]') FROM columns),
    'foreign_keys', (SELECT coalesce(json_agg(foreign_keys ORDER BY schema, source, name), '[]') FROM foreign_keys),
    'routines', (SELECT coalesce(json_agg(x ORDER BY schema, name, arguments), '[]') FROM (SELECT schema,name,arguments,kind,security_definer,language,body_md5,extension,lexical_relations FROM routines) x),
    'triggers', (SELECT coalesce(json_agg(triggers ORDER BY schema, relation, name), '[]') FROM triggers),
    'view_dependencies', (SELECT coalesce(json_agg(view_dependencies ORDER BY schema, view, target), '[]') FROM view_dependencies),
    'sequence_dependencies', (SELECT coalesce(json_agg(sequence_dependencies ORDER BY schema, sequence), '[]') FROM sequence_dependencies),
    'indexes', (SELECT coalesce(json_agg(indexes ORDER BY schema, relation, name), '[]') FROM indexes),
    'activity', (SELECT coalesce(json_agg(activity ORDER BY database, role, application_name, state), '[]') FROM activity),
    'roles', (SELECT json_agg(roles) FROM roles),
    'migrations', (SELECT json_agg(m ORDER BY migration) FROM (SELECT migration, batch FROM public.migrations) m),
    'module_history', (SELECT json_agg(m ORDER BY module_id) FROM
        (SELECT module_id, count(*) AS migration_count, max(migration) AS latest_migration FROM public.core_module_migrations GROUP BY module_id) m),
    'module_migrations', (SELECT json_agg(m ORDER BY module_id,migration) FROM
        (SELECT module_id,migration,batch FROM public.core_module_migrations) m),
    'organization_address_aggregates', (SELECT json_build_object(
        'organization_party_links', (SELECT count(*) FROM public.organization_parties),
        'linked_parties', (SELECT count(DISTINCT party_id) FROM public.organization_parties),
        'organization_parties_with_vendor_role', (SELECT count(DISTINCT op.party_id) FROM public.organization_parties op JOIN public.vendors v ON v.party_id=op.party_id AND v.tenant_id=op.tenant_id),
        'linked_locations', (SELECT count(DISTINCT pl.location_id) FROM public.organization_parties op JOIN public.party_locations pl ON pl.party_id=op.party_id AND pl.tenant_id=op.tenant_id),
        'locations_also_linked_to_other_parties', (SELECT count(DISTINCT pl.location_id) FROM public.organization_parties op JOIN public.party_locations pl ON pl.party_id=op.party_id AND pl.tenant_id=op.tenant_id JOIN public.party_locations other ON other.location_id=pl.location_id AND other.tenant_id=pl.tenant_id AND other.party_id<>pl.party_id)
    )),
    'identity_link_aggregates', (SELECT json_build_object(
        'active_product_customers', (SELECT count(*) FROM public.product_customers WHERE deleted_at IS NULL),
        'customers_with_exact_subject_link', (SELECT count(*) FROM public.product_customers c WHERE c.deleted_at IS NULL
            AND EXISTS (SELECT 1 FROM public.external_identities e WHERE e.deleted_at IS NULL AND e.issuer=c.issuer AND e.subject=c.subject)),
        'subjects_with_multiple_user_bindings', (SELECT count(*) FROM
            (SELECT issuer,subject FROM public.external_identities WHERE deleted_at IS NULL GROUP BY issuer,subject HAVING count(DISTINCT user_id)>1) conflicts),
        'active_resources_without_customer_binding', (SELECT count(*) FROM public.managed_product_tenants WHERE deleted_at IS NULL AND owner_customer_id IS NULL)
    )),
    'reference_row_scopes', (SELECT json_agg(x ORDER BY relation) FROM (
        SELECT 'ref_provinces' AS relation,count(*) AS rows,count(tenant_id) AS tenant_scoped_rows FROM public.ref_provinces UNION ALL
        SELECT 'ref_regencies',count(*),count(tenant_id) FROM public.ref_regencies UNION ALL
        SELECT 'ref_districts',count(*),count(tenant_id) FROM public.ref_districts UNION ALL
        SELECT 'ref_villages',count(*),count(tenant_id) FROM public.ref_villages UNION ALL
        SELECT 'ref_postal_codes',count(*),count(tenant_id) FROM public.ref_postal_codes UNION ALL
        SELECT 'ref_streets',count(*),count(tenant_id) FROM public.ref_streets UNION ALL
        SELECT 'ref_buildings',count(*),count(tenant_id) FROM public.ref_buildings UNION ALL
        SELECT 'ref_group_of_houses',count(*),count(tenant_id) FROM public.ref_group_of_houses UNION ALL
        SELECT 'ref_land_plots',count(*),count(tenant_id) FROM public.ref_land_plots
    ) x),
    'environment_placements', (SELECT json_agg(e ORDER BY kind, hosting, database_name) FROM
        (SELECT kind, hosting, database_name, status, count(*) AS count FROM public.environments GROUP BY 1,2,3,4) e),
    'default_privileges', (SELECT coalesce(json_agg(json_build_object('owner',pg_get_userbyid(defaclrole),'schema',n.nspname,'type',defaclobjtype,'acl',defaclacl::text)), '[]')
        FROM pg_default_acl d LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace),
    'database_acl', (SELECT datacl::text FROM pg_database WHERE datname = current_database()),
    'public_database_privileges', (SELECT json_agg(a.privilege_type ORDER BY a.privilege_type)
        FROM pg_database d CROSS JOIN LATERAL aclexplode(coalesce(d.datacl,acldefault('d',d.datdba))) a
        WHERE d.datname=current_database() AND a.grantee=0),
    'schema_acl', (SELECT json_agg(json_build_object('schema',nspname,'acl',nspacl::text)) FROM pg_namespace
        WHERE nspname NOT IN ('pg_catalog','information_schema') AND nspname NOT LIKE 'pg_%')
);
