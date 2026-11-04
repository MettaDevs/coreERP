import copy
import importlib.util
import json
import pathlib
import shutil
import tempfile
import unittest
from unittest import mock

SPEC = importlib.util.spec_from_file_location('build_control_schema', pathlib.Path(__file__).with_name('build-control-schema.py'))
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


class ControlSchemaTests(unittest.TestCase):
    def fixture(self):
        catalog = {
            'server_version': '16.14',
            'tables': [
                {'name': 'central', 'kind': 'r', 'columns': [
                    {'name': 'id', 'type': 'bigint'}, {'name': 'erp_field', 'type': 'text'},
                ], 'constraints': [], 'indexes': [], 'triggers': []},
                {'name': 'business', 'kind': 'r', 'columns': [{'name': 'id', 'type': 'bigint'}],
                 'constraints': [], 'indexes': [], 'triggers': []},
            ],
            'routines': [], 'sequences': [], 'types': [],
        }
        plan = {
            'source': {'schema': 'fixture', 'catalog_sha256': BUILDER.digest(BUILDER.normalize_catalog(catalog, 'fixture'))},
            'tables': {
                'central': {'action': 'keep', 'columns': {'id': 'keep', 'erp_field': 'exclude'},
                            'constraints': {}, 'indexes': {}, 'triggers': {}},
                'business': {'action': 'exclude', 'columns': {'id': 'exclude'},
                             'constraints': {}, 'indexes': {}, 'triggers': {}},
            },
            'routines': {}, 'sequences': {}, 'types': [],
        }
        return catalog, plan

    def test_classified_source_is_accepted(self):
        catalog, plan = self.fixture()
        BUILDER.validate_plan(catalog, plan)

    def test_new_column_in_excluded_table_requires_a_decision(self):
        catalog, plan = self.fixture()
        catalog['tables'][1]['columns'].append({'name': 'new_field', 'type': 'text'})
        with self.assertRaisesRegex(ValueError, 'business columns'):
            BUILDER.validate_plan(catalog, plan)

    def test_new_constraint_requires_a_decision(self):
        catalog, plan = self.fixture()
        catalog['tables'][0]['constraints'].append({'name': 'new_check'})
        with self.assertRaisesRegex(ValueError, 'central constraints'):
            BUILDER.validate_plan(catalog, plan)

    def test_new_table_requires_a_decision(self):
        catalog, plan = self.fixture()
        catalog['tables'].append({'name': 'unclassified'})
        with self.assertRaisesRegex(ValueError, 'Unclassified or missing tables'):
            BUILDER.validate_plan(catalog, plan)

    def test_new_index_and_trigger_require_decisions(self):
        for kind in ('indexes', 'triggers'):
            with self.subTest(kind=kind):
                catalog, plan = self.fixture()
                catalog['tables'][0][kind].append({'name': 'unclassified'})
                with self.assertRaisesRegex(ValueError, 'central ' + kind):
                    BUILDER.validate_plan(catalog, plan)

    def test_excluded_table_cannot_keep_a_column(self):
        catalog, plan = self.fixture()
        plan['tables']['business']['columns']['id'] = 'keep'
        with self.assertRaisesRegex(ValueError, 'Excluded table retains objects'):
            BUILDER.validate_plan(catalog, plan)

    def test_changed_column_type_requires_source_review(self):
        catalog, plan = self.fixture()
        catalog['tables'][0]['columns'][0]['type'] = 'uuid'
        with self.assertRaisesRegex(ValueError, 'Source schema changed'):
            BUILDER.validate_plan(catalog, plan)

    def test_foreign_key_cannot_escape_to_business(self):
        catalog, plan = self.fixture()
        catalog['tables'][0]['constraints'].append({
            'name': 'central_business_fk', 'type': 'f', 'columns': ['id'],
            'reference_schema': 'fixture', 'reference_table': 'business', 'reference_columns': ['id'],
        })
        plan['tables']['central']['constraints']['central_business_fk'] = 'keep'
        with self.assertRaisesRegex(ValueError, 'Foreign key escapes control partition'):
            BUILDER.validate_plan(catalog, plan)

    def test_index_predicate_cannot_retain_excluded_column(self):
        catalog, plan = self.fixture()
        catalog['tables'][0]['indexes'].append({
            'name': 'central_active', 'columns': ['id', 'erp_field'], 'constraint': None,
        })
        plan['tables']['central']['indexes']['central_active'] = 'keep'
        with self.assertRaisesRegex(ValueError, 'Index retains excluded columns'):
            BUILDER.validate_plan(catalog, plan)

    def test_new_routine_requires_a_decision(self):
        catalog, plan = self.fixture()
        catalog['routines'].append({'name': 'new_function()'})
        with self.assertRaisesRegex(ValueError, 'Unclassified or missing routines'):
            BUILDER.validate_plan(catalog, plan)

    def test_new_sequence_requires_a_decision(self):
        catalog, plan = self.fixture()
        catalog['sequences'].append({'name': 'unclassified_seq'})
        with self.assertRaisesRegex(ValueError, 'Unclassified or missing sequences'):
            BUILDER.validate_plan(catalog, plan)

    def test_retained_sequence_cannot_belong_to_an_excluded_column(self):
        catalog, plan = self.fixture()
        catalog['sequences'].append({
            'name': 'business_id_seq', 'owner_table': 'business', 'owner_column': 'id',
        })
        plan['sequences']['business_id_seq'] = 'keep'
        with self.assertRaisesRegex(ValueError, 'Sequence owner escapes control partition'):
            BUILDER.validate_plan(catalog, plan)

    def test_custom_type_requires_generator_support(self):
        catalog, plan = self.fixture()
        catalog['types'].append({'name': 'unclassified_type'})
        with self.assertRaisesRegex(ValueError, 'Custom types require'):
            BUILDER.validate_plan(catalog, plan)

    def test_trigger_cannot_call_an_excluded_routine(self):
        catalog, plan = self.fixture()
        catalog['tables'][0]['triggers'].append({
            'name': 'audit', 'routine': 'erp_only()', 'routine_schema': 'fixture',
        })
        plan['tables']['central']['triggers']['audit'] = 'keep'
        plan['routines']['erp_only()'] = {'action': 'exclude'}
        catalog['routines'].append({'name': 'erp_only()'})
        with self.assertRaisesRegex(ValueError, 'Trigger escapes control partition'):
            BUILDER.validate_plan(catalog, plan)

    def test_routine_replacement_cannot_leave_source_directory(self):
        catalog, plan = self.fixture()
        catalog['routines'].append({'name': 'audit()'})
        plan['routines']['audit()'] = {'action': 'replace', 'source': '../outside.sql'}
        with self.assertRaisesRegex(ValueError, 'Invalid routine replacement source'):
            BUILDER.validate_plan(catalog, plan)

    def test_partition_uses_only_explicit_restrict_operations(self):
        catalog, plan = self.fixture()
        sql = BUILDER.partition_sql(catalog, plan, 'scratch')
        self.assertIn('ALTER TABLE "scratch"."central" DROP COLUMN "erp_field" RESTRICT;', sql)
        self.assertIn('DROP TABLE "scratch"."business" RESTRICT;', sql)
        self.assertNotIn('CASCADE', sql)
        self.assertNotIn('"fixture".', sql)

    def test_missing_retained_object_is_detected(self):
        catalog, plan = self.fixture()
        target = copy.deepcopy(catalog)
        target['tables'] = [target['tables'][0]]
        target['tables'][0]['columns'] = []
        with self.assertRaisesRegex(ValueError, 'lost selected objects'):
            BUILDER.validate_partition(catalog, plan, target)

    def test_dump_random_tokens_and_known_version_noise_are_normalized(self):
        first = "-- Dumped by pg_dump version 16.14\n\\restrict abc123\nCREATE SCHEMA fixture;\nCREATE TABLE fixture.central (id bigint);\n\\unrestrict abc123\n"
        second = first.replace('abc123', 'XYZ789').replace('16.14', '16.15')
        sql = BUILDER.normalize_dump(first, 'fixture')
        self.assertEqual(sql, BUILDER.normalize_dump(second, 'fixture'))
        self.assertIn('CREATE TABLE __CONTROL_SCHEMA__.central (id bigint);', sql)
        self.assertNotIn('CREATE SCHEMA', sql)

    def test_invalid_target_is_rejected_before_any_command(self):
        with mock.patch.object(BUILDER.Fixture, 'run') as run:
            with self.assertRaisesRegex(ValueError, 'Only the named CPDB-02'):
                BUILDER.Fixture('irrelevant', 'fixture', 'core_erp', BUILDER.SCRATCH_DATABASE)
            run.assert_not_called()
        with self.assertRaisesRegex(ValueError, 'Invalid PostgreSQL identifier'):
            BUILDER.identifier('public; DROP DATABASE example')

    def test_unowned_or_stopped_container_is_rejected_before_database_access(self):
        cases = (
            {'com.docker.compose.project': 'unrelated', 'coreerp.test-purpose': 'cpdb-02'},
            {'com.docker.compose.project': 'coreerp-cpdb02', 'coreerp.test-purpose': 'unrelated'},
            {'com.docker.compose.project': 'coreerp-cpdb02', 'coreerp.test-purpose': 'cpdb-02'},
        )
        for index, labels in enumerate(cases):
            with self.subTest(labels=labels):
                inspect = [{'Config': {'Labels': labels}, 'State': {'Running': index != 2}}]
                with mock.patch.object(BUILDER.Fixture, 'run', return_value=json.dumps(inspect)) as run:
                    with self.assertRaisesRegex(ValueError, 'not the owned, running CPDB-02 fixture'):
                        BUILDER.Fixture('fixture', 'fixture', BUILDER.SOURCE_DATABASE, BUILDER.SCRATCH_DATABASE)
                    self.assertEqual(run.call_count, 1)
                    self.assertEqual(run.call_args.args[0], ['docker', 'inspect', 'fixture'])

    def test_source_identity_is_verified_with_a_read_only_connection(self):
        inspect = [{'Config': {'Labels': {
            'com.docker.compose.project': 'coreerp-cpdb02', 'coreerp.test-purpose': 'cpdb-02',
        }}, 'State': {'Running': True}}]
        for identity in ([BUILDER.SOURCE_DATABASE, 'other_role'], ['other_database', 'fixture']):
            with self.subTest(identity=identity):
                with mock.patch.object(BUILDER.Fixture, 'run', side_effect=[json.dumps(inspect), json.dumps(identity)]) as run:
                    with self.assertRaisesRegex(ValueError, 'Actual database or role'):
                        BUILDER.Fixture('fixture', 'fixture', BUILDER.SOURCE_DATABASE, BUILDER.SCRATCH_DATABASE)
                    self.assertIn('PGOPTIONS=-c default_transaction_read_only=on', run.call_args.args[0])

    def test_source_writes_and_unlisted_databases_are_rejected_before_execution(self):
        client = object.__new__(BUILDER.Fixture)
        client.source_database = BUILDER.SOURCE_DATABASE
        client.scratch_database = BUILDER.SCRATCH_DATABASE
        with mock.patch.object(BUILDER.Fixture, 'run') as run:
            with self.assertRaisesRegex(ValueError, 'source fixture is read-only'):
                client.execute(BUILDER.SOURCE_DATABASE, 'SELECT 1;')
            with self.assertRaisesRegex(ValueError, 'outside the fixture allowlist'):
                client.execute('unrelated_database', 'SELECT 1;', read_only=True)
            run.assert_not_called()

    def test_existing_scratch_requires_explicit_rebuild_and_matching_owner(self):
        client = object.__new__(BUILDER.Fixture)
        client.username = 'fixture'
        client.scratch_database = BUILDER.SCRATCH_DATABASE
        for rebuild, owner in ((False, 'fixture'), (True, 'other_role')):
            with self.subTest(rebuild=rebuild, owner=owner):
                with mock.patch.object(client, 'execute', return_value=owner) as execute:
                    with self.assertRaisesRegex(ValueError, 'Scratch database exists'):
                        client.rebuild(rebuild)
                    self.assertEqual(execute.call_count, 1)
                    self.assertTrue(execute.call_args.kwargs['read_only'])

    def test_committed_baseline_and_foundation_partition_match_metadata(self):
        plan = json.loads((BUILDER.DIRECTORY / 'partition-plan.json').read_text())
        BUILDER.check_files(plan)
        self.assertEqual({column for column, action in plan['tables']['apps']['columns'].items() if action == 'keep'},
                         {'id', 'name', 'status', 'description', 'created_at', 'updated_at'})
        self.assertEqual(plan['tables']['tenant_creation_requests']['action'], 'exclude')
        self.assertEqual(plan['tables']['product_customers']['columns']['user_id'], 'exclude')
        kept = {name for name, rule in plan['tables'].items() if rule['action'] == 'keep'}
        for table in ('roles', 'core_module_migrations', 'core_module_installations', 'ref_countries', 'app_dependencies'):
            self.assertNotIn(table, kept)

    def test_changed_baseline_or_routine_source_requires_regeneration(self):
        original = BUILDER.DIRECTORY
        plan = json.loads((original / 'partition-plan.json').read_text())
        for filename, key in (('baseline.sql', 'baseline_sha256'),
                              ('routines/coreerp_log_change.sql', 'routine_sources_sha256')):
            with self.subTest(filename=filename), tempfile.TemporaryDirectory() as temporary:
                directory = pathlib.Path(temporary) / 'control-plane'
                shutil.copytree(original, directory)
                path = directory / filename
                path.write_text(path.read_text() + '\n-- Unreviewed change.\n')
                with mock.patch.object(BUILDER, 'DIRECTORY', directory):
                    with self.assertRaisesRegex(ValueError, 'Stale baseline metadata: ' + key):
                        BUILDER.check_files(plan)

    def test_changed_source_migrations_require_fixture_review(self):
        plan = json.loads((BUILDER.DIRECTORY / 'partition-plan.json').read_text())
        with mock.patch.object(BUILDER, 'migration_source', return_value={}):
            with self.assertRaisesRegex(ValueError, 'Source migration files changed'):
                BUILDER.check_files(plan)

    def test_foundation_columns_cannot_expand_into_erp_fields(self):
        plan = json.loads((BUILDER.DIRECTORY / 'partition-plan.json').read_text())
        for name in ('legal_entities', 'operating_units'):
            with self.subTest(table=name):
                changed = copy.deepcopy(plan)
                changed['tables'][name]['columns']['unclassified_business_field'] = 'keep'
                with self.assertRaisesRegex(ValueError, 'Foundation field partition drift: ' + name):
                    BUILDER.validate_foundation(changed)

    def test_control_log_routine_has_no_erp_security_dependency(self):
        sql = (BUILDER.DIRECTORY / 'routines/coreerp_log_change.sql').read_text()
        for name in ('roles', 'role_assignments', 'security_duties', 'security_privileges', 'fiscal_calendars'):
            self.assertNotIn(name, sql)
        self.assertIn("tenant := rec ->> 'tenant_id'", sql)
        self.assertIn('INSERT INTO change_log_entries', sql)

    def test_fixed_manifest_reuses_original_pf01_file_exactly_once(self):
        manifest = (BUILDER.DIRECTORY / 'manifest.php').read_text()
        self.assertEqual(manifest.count('2026_11_02_160000_add_canonical_tenant_creation.php'), 1)
        self.assertIn("__DIR__.'/../migrations/", manifest)
        self.assertNotIn('glob(', manifest)


if __name__ == '__main__':
    unittest.main()
