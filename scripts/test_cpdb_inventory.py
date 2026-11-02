"""Regresi offline collector/inventory. Tidak boot aplikasi atau membuka database."""

import copy
import importlib.util
import pathlib
import re
import subprocess
import tempfile
import unittest
from unittest import mock

SPEC = importlib.util.spec_from_file_location('cpdb_inventory', pathlib.Path(__file__).with_name('cpdb-inventory.py'))
INVENTORY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INVENTORY)


class InventoryTests(unittest.TestCase):
    def test_down_is_excluded_without_losing_up_helpers(self):
        source = '''public function up(): void { $this->install(); }
public function down(): void { if (true) { Schema::drop('users'); } }
private function install(): void { Schema::table('users', fn ($t) => $t); }'''
        result = INVENTORY.without_down(source)
        self.assertNotIn("Schema::drop('users')", result)
        self.assertIn("Schema::table('users'", result)

    def test_comments_do_not_invent_or_remove_table_references(self):
        source = '''// Schema::create('wrong');
Schema::create('users'); $url='https://host/path'; /* Schema::drop('users'); */'''
        result = INVENTORY.without_comments(source)
        self.assertNotIn("'wrong'", result)
        self.assertIn("Schema::create('users')", result)
        self.assertIn('https://host/path', result)

    def fixture(self):
        catalog = {k:[] for k in ('relations','columns','foreign_keys','routines','triggers','sequence_dependencies','view_dependencies','indexes','migrations')}
        catalog['database'] = 'fixture'
        return {'source_sha':'baseline','move':{'control':'users'},'retain':{},'projection':{},'routine_rules':{},'migration_overrides':{}}, {'catalog':catalog,'additional_databases':[]}

    def assert_build_rejects(self, rules, runtime, message):
        def read(name):
            return rules if name=='ownership-rules.json' else runtime
        with mock.patch.object(INVENTORY,'read_json',side_effect=read), mock.patch.object(INVENTORY,'source_files',return_value=[]):
            with self.assertRaisesRegex(ValueError,message):
                INVENTORY.build()

    def test_unknown_primary_table_is_rejected(self):
        rules,runtime = self.fixture()
        runtime['catalog']['relations'] = [{'name':'unclassified','relkind':'r','schema':'public'}]
        self.assert_build_rejects(rules,runtime,'Unclassified runtime relation: unclassified')

    def test_unknown_sandbox_table_is_rejected(self):
        rules,runtime = self.fixture()
        extra = copy.deepcopy(runtime['catalog'])
        extra['database'] = 'sandbox'
        extra['relations'] = [{'name':'unclassified_sandbox','relkind':'r','schema':'public'}]
        runtime['additional_databases'] = [extra]
        self.assert_build_rejects(rules,runtime,'Unclassified runtime relation: unclassified_sandbox')

    def test_unknown_routine_is_rejected(self):
        rules,runtime = self.fixture()
        runtime['catalog']['routines'] = [{'name':'new_function','schema':'public','extension':None}]
        self.assert_build_rejects(rules,runtime,'Unclassified routine: new_function')

    def test_unexpected_schema_requires_explicit_classification(self):
        rules,runtime = self.fixture()
        runtime['catalog']['relations'] = [{'name':'users','relkind':'r','schema':'another_schema'}]
        self.assert_build_rejects(rules,runtime,'Schema baru')

    def test_source_is_read_from_revision_after_worktree_changes(self):
        with tempfile.TemporaryDirectory(prefix='cpdb-source-') as directory:
            root = pathlib.Path(directory).resolve()
            self.assertIn('cpdb-source-',root.name)
            subprocess.run(['git','init','-q',str(root)],check=True)
            path = root/'apps/core/Example.php'
            path.parent.mkdir(parents=True)
            path.write_text('<?php // baseline',encoding='utf-8')
            subprocess.run(['git','-C',str(root),'add','apps/core/Example.php'],check=True)
            tree = subprocess.check_output(['git','-C',str(root),'write-tree'],text=True).strip()
            path.write_text('<?php // unrelated working edit',encoding='utf-8')
            with mock.patch.object(INVENTORY,'ROOT',root):
                self.assertEqual(INVENTORY.source_files(tree),[('apps/core/Example.php','<?php // baseline')])

    def test_catalog_query_contains_no_mutation_statement(self):
        sql = pathlib.Path(__file__).with_name('cpdb-runtime.sql').read_text(encoding='utf-8')
        sql = re.sub(r'--[^\n]*','',sql)
        sql = re.sub(r"'(?:''|[^'])*'", "''", sql)
        self.assertRegex(sql.lstrip(),r'^WITH\b')
        self.assertNotRegex(sql,re.compile(r'\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|COPY|CALL|GRANT|REVOKE)\b',re.I))
        self.assertEqual(sql.count(';'),1)


if __name__ == '__main__':
    unittest.main()
