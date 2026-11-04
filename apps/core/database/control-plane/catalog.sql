SELECT jsonb_build_object(
    'server_version', current_setting('server_version'),
    'tables', COALESCE((
        SELECT jsonb_agg(jsonb_build_object(
            'name', c.relname,
            'kind', c.relkind,
            'columns', COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                    'name', a.attname,
                    'type', format_type(a.atttypid, a.atttypmod),
                    'not_null', a.attnotnull,
                    'default', pg_get_expr(d.adbin, d.adrelid),
                    'identity', a.attidentity,
                    'generated', a.attgenerated
                ) ORDER BY a.attnum)
                FROM pg_attribute a
                LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
            ), '[]'::jsonb),
            'constraints', COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                    'name', k.conname,
                    'type', k.contype,
                    'definition', pg_get_constraintdef(k.oid),
                    'columns', COALESCE((SELECT jsonb_agg(a.attname ORDER BY x.position)
                        FROM unnest(k.conkey) WITH ORDINALITY x(number, position)
                        JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = x.number), '[]'::jsonb),
                    'reference_schema', rn.nspname,
                    'reference_table', rc.relname,
                    'reference_columns', COALESCE((SELECT jsonb_agg(a.attname ORDER BY x.position)
                        FROM unnest(k.confkey) WITH ORDINALITY x(number, position)
                        JOIN pg_attribute a ON a.attrelid = k.confrelid AND a.attnum = x.number), '[]'::jsonb)
                ) ORDER BY k.conname)
                FROM pg_constraint k
                LEFT JOIN pg_class rc ON rc.oid = k.confrelid
                LEFT JOIN pg_namespace rn ON rn.oid = rc.relnamespace
                WHERE k.conrelid = c.oid
            ), '[]'::jsonb),
            'indexes', COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                    'name', ic.relname,
                    'definition', pg_get_indexdef(i.indexrelid),
                    'constraint', k.conname,
                    'columns', COALESCE((SELECT jsonb_agg(a.attname ORDER BY a.attname)
                        FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum IN (
                            SELECT unnest(i.indkey)
                            UNION
                            SELECT d.refobjsubid FROM pg_depend d
                            WHERE d.classid = 'pg_class'::regclass AND d.objid = i.indexrelid
                                AND d.refobjid = c.oid AND d.refobjsubid > 0
                        )), '[]'::jsonb)
                ) ORDER BY ic.relname)
                FROM pg_index i JOIN pg_class ic ON ic.oid = i.indexrelid
                LEFT JOIN pg_constraint k ON k.conindid = i.indexrelid AND k.conrelid = c.oid
                WHERE i.indrelid = c.oid
            ), '[]'::jsonb),
            'triggers', COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                    'name', t.tgname,
                    'definition', pg_get_triggerdef(t.oid),
                    'routine', p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')',
                    'routine_schema', pn.nspname
                ) ORDER BY t.tgname)
                FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid
                JOIN pg_namespace pn ON pn.oid = p.pronamespace
                WHERE t.tgrelid = c.oid AND NOT t.tgisinternal
            ), '[]'::jsonb)
        ) ORDER BY c.relname)
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = :'schema' AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
    ), '[]'::jsonb),
    'routines', COALESCE((
        SELECT jsonb_agg(jsonb_build_object(
            'name', p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')',
            'kind', p.prokind,
            'definition', pg_get_functiondef(p.oid)
        ) ORDER BY p.proname, pg_get_function_identity_arguments(p.oid))
        FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = :'schema'
    ), '[]'::jsonb),
    'sequences', COALESCE((
        SELECT jsonb_agg(jsonb_build_object(
            'name', c.relname,
            'type', format_type(s.seqtypid, NULL),
            'start', s.seqstart,
            'increment', s.seqincrement,
            'minimum', s.seqmin,
            'maximum', s.seqmax,
            'cache', s.seqcache,
            'cycle', s.seqcycle,
            'owner_table', tc.relname,
            'owner_column', a.attname
        ) ORDER BY c.relname)
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_sequence s ON s.seqrelid = c.oid
        LEFT JOIN pg_depend d ON d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype IN ('a', 'i')
        LEFT JOIN pg_class tc ON tc.oid = d.refobjid
        LEFT JOIN pg_attribute a ON a.attrelid = tc.oid AND a.attnum = d.refobjsubid
        WHERE n.nspname = :'schema'
    ), '[]'::jsonb),
    'types', COALESCE((
        SELECT jsonb_agg(jsonb_build_object('name', t.typname, 'kind', t.typtype) ORDER BY t.typname)
        FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
        WHERE n.nspname = :'schema' AND t.typtype IN ('d', 'e', 'r', 'm')
    ), '[]'::jsonb)
);
