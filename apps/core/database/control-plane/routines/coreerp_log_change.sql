CREATE OR REPLACE FUNCTION __CONTROL_SCHEMA__.coreerp_log_change()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
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

    IF TG_OP = 'DELETE' THEN old_row := to_jsonb(OLD); ELSE new_row := to_jsonb(NEW); END IF;
    rec := COALESCE(new_row, old_row);
    tenant := rec ->> 'tenant_id';
    IF tenant IS NULL THEN
        RETURN NULL;
    END IF;

    IF TG_TABLE_NAME IN ('change_log_setup_tables', 'change_log_setup_fields') THEN
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
        CONTINUE WHEN field IN ('id', 'tenant_id', 'created_at', 'updated_at', 'created_by_user_id', 'updated_by_user_id', 'version');
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
$function$;
