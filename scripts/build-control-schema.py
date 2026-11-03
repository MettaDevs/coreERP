"""Bangun baseline DDL control dari fixture Core PostgreSQL yang dipin.

Hanya database scratch yang boleh ditulis. Source dibaca sebagai schema dan metadata
migration; isi tabel tidak disalin. Plan ownership, bukan nama migration, memilih DDL.
"""

import argparse
import hashlib
import json
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
DIRECTORY = ROOT / 'apps/core/database/control-plane'
PLACEHOLDER = '__CONTROL_SCHEMA__'
SOURCE_DATABASE = 'core_erp_cpdb02_test_source'
SCRATCH_DATABASE = 'core_erp_cpdb02_test_schema_build'
REPLAY_SCHEMA = 'control_baseline_replay'
GENERATOR_VERSION = 1


def digest(value):
    if not isinstance(value, str):
        value = json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)
    return hashlib.sha256(value.encode()).hexdigest()


def identifier(value):
    if not isinstance(value, str) or not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,62}', value):
        raise ValueError('Invalid PostgreSQL identifier')
    return '"' + value + '"'


def normalize_catalog(catalog, schema):
    if isinstance(catalog, dict):
        return {k: normalize_catalog(v, schema) for k, v in catalog.items()}
    if isinstance(catalog, list):
        return [normalize_catalog(v, schema) for v in catalog]
    if isinstance(catalog, str):
        if catalog == schema:
            return PLACEHOLDER
        return catalog.replace(schema + '.', PLACEHOLDER + '.')
    return catalog


def split_names(objects, decisions, label):
    actual = {item['name'] for item in objects}
    expected = set(decisions)
    if actual != expected:
        raise ValueError(f'Unclassified or missing {label}: {sorted(actual ^ expected)}')
    for name, action in decisions.items():
        if action not in ('keep', 'exclude'):
            raise ValueError(f'Invalid {label} decision: {name}')


def validate_plan(catalog, plan):
    tables = {table['name']: table for table in catalog['tables']}
    if set(tables) != set(plan['tables']):
        raise ValueError('Unclassified or missing tables: ' + str(sorted(set(tables) ^ set(plan['tables']))))
    for name, table in tables.items():
        rule = plan['tables'][name]
        if table['kind'] != 'r' or rule['action'] not in ('keep', 'exclude'):
            raise ValueError('Unsupported relation or action: ' + name)
        split_names(table['columns'], rule['columns'], name + ' columns')
        for kind in ('constraints', 'indexes', 'triggers'):
            split_names(table[kind], rule[kind], name + ' ' + kind)
        if rule['action'] == 'exclude' and any(
            action == 'keep' for kind in ('columns', 'constraints', 'indexes', 'triggers')
            for action in rule[kind].values()
        ):
            raise ValueError('Excluded table retains objects: ' + name)
        kept = {column for column, action in rule['columns'].items() if action == 'keep'}
        for constraint in table['constraints']:
            if rule['constraints'][constraint['name']] != 'keep':
                continue
            if not set(constraint['columns']) <= kept:
                raise ValueError('Constraint retains excluded columns: ' + constraint['name'])
            if constraint['reference_table']:
                target = plan['tables'].get(constraint['reference_table'])
                if (constraint['reference_schema'] != plan['source']['schema'] or not target
                        or target['action'] != 'keep' or any(
                            target['columns'].get(column) != 'keep' for column in constraint['reference_columns'])):
                    raise ValueError('Foreign key escapes control partition: ' + constraint['name'])
        for index in table['indexes']:
            if rule['indexes'][index['name']] == 'keep':
                if not set(index['columns']) <= kept:
                    raise ValueError('Index retains excluded columns: ' + index['name'])
                if index['constraint'] and rule['constraints'][index['constraint']] != 'keep':
                    raise ValueError('Index retains excluded constraint: ' + index['name'])
        for trigger in table['triggers']:
            if rule['triggers'][trigger['name']] == 'keep':
                routine = plan['routines'].get(trigger['routine'])
                if trigger['routine_schema'] != plan['source']['schema'] or not routine or routine['action'] == 'exclude':
                    raise ValueError('Trigger escapes control partition: ' + trigger['name'])
    if set(plan['routines']) != {routine['name'] for routine in catalog['routines']}:
        raise ValueError('Unclassified or missing routines')
    for name, rule in plan['routines'].items():
        if rule['action'] not in ('keep', 'exclude', 'replace'):
            raise ValueError('Invalid routine decision: ' + name)
        if rule['action'] == 'replace':
            replacement = pathlib.PurePosixPath(rule['source'])
            if replacement.parts[:1] != ('routines',) or '..' in replacement.parts or replacement.suffix != '.sql':
                raise ValueError('Invalid routine replacement source: ' + name)
    split_names(catalog['sequences'], plan['sequences'], 'sequences')
    for sequence in catalog['sequences']:
        owner = plan['tables'].get(sequence['owner_table'])
        if plan['sequences'][sequence['name']] == 'keep' and (
            not owner or owner['columns'].get(sequence['owner_column']) != 'keep'
        ):
            raise ValueError('Sequence owner escapes control partition: ' + sequence['name'])
    if catalog['types'] or plan['types']:
        raise ValueError('Custom types require an explicit generator implementation')
    if digest(normalize_catalog(catalog, plan['source']['schema'])) != plan['source']['catalog_sha256']:
        raise ValueError('Source schema changed; review the ownership plan and source fingerprint')


def validate_foundation(plan):
    partitions = json.loads((ROOT / 'docs/todo/control-plane-database/inventory/field-partitions.json').read_text())
    expected = {}
    for field in partitions:
        expected.setdefault(field['table'], set())
        if field['authority'] == 'organization-foundation':
            expected[field['table']].add(field['field'])
    for name, columns in expected.items():
        actual = {column for column, action in plan['tables'][name]['columns'].items() if action == 'keep'}
        if actual != columns:
            raise ValueError('Foundation field partition drift: ' + name)


def migration_source():
    paths = sorted((ROOT / 'apps/core/database/migrations').glob('*.php'))
    return {path.stem: hashlib.sha256(path.read_bytes()).hexdigest() for path in paths}


def validate_partition(source, plan, target):
    expected_tables = []
    for table in source['tables']:
        rule = plan['tables'][table['name']]
        if rule['action'] == 'keep':
            expected_tables.append({
                **table,
                **{kind: [obj for obj in table[kind] if rule[kind][obj['name']] == 'keep']
                   for kind in ('columns', 'constraints', 'indexes', 'triggers')},
            })
    if expected_tables != target['tables']:
        raise ValueError('Partition changed retained table definitions or lost selected objects')
    expected_sequences = [sequence for sequence in source['sequences'] if plan['sequences'][sequence['name']] == 'keep']
    if expected_sequences != target['sequences']:
        raise ValueError('Partition changed selected sequences')
    actual_routines = {routine['name']: routine for routine in target['routines']}
    if set(actual_routines) != {name for name, rule in plan['routines'].items() if rule['action'] != 'exclude'}:
        raise ValueError('Partition routine set differs from its plan')
    for routine in source['routines']:
        if plan['routines'][routine['name']]['action'] == 'keep' and actual_routines[routine['name']] != routine:
            raise ValueError('Partition changed retained routine: ' + routine['name'])
    if target['types']:
        raise ValueError('Partition contains unclassified types')


def partition_sql(catalog, plan, schema):
    qualified = lambda table: identifier(schema) + '.' + identifier(table)
    statements = ['BEGIN;', 'SET LOCAL search_path TO ' + identifier(schema) + ', pg_catalog;']
    for foreign_keys in (True, False):
        for table in catalog['tables']:
            rule = plan['tables'][table['name']]
            for constraint in table['constraints']:
                if (constraint['type'] == 'f') == foreign_keys and rule['constraints'][constraint['name']] == 'exclude':
                    statements.append('ALTER TABLE ' + qualified(table['name']) + ' DROP CONSTRAINT '
                                      + identifier(constraint['name']) + ' RESTRICT;')
    for table in catalog['tables']:
        rule = plan['tables'][table['name']]
        for index in table['indexes']:
            if rule['indexes'][index['name']] == 'exclude' and not index['constraint']:
                statements.append('DROP INDEX ' + qualified(index['name']) + ' RESTRICT;')
        for trigger in table['triggers']:
            if rule['triggers'][trigger['name']] == 'exclude':
                statements.append('DROP TRIGGER ' + identifier(trigger['name']) + ' ON '
                                  + qualified(table['name']) + ' RESTRICT;')
    for table in catalog['tables']:
        rule = plan['tables'][table['name']]
        if rule['action'] == 'exclude':
            statements.append('DROP TABLE ' + qualified(table['name']) + ' RESTRICT;')
        else:
            for column, action in rule['columns'].items():
                if action == 'exclude':
                    statements.append('ALTER TABLE ' + qualified(table['name']) + ' DROP COLUMN '
                                      + identifier(column) + ' RESTRICT;')
    for name, rule in plan['routines'].items():
        if rule['action'] == 'exclude':
            if not re.fullmatch(r'[a-z_][a-z0-9_]*\(\)', name):
                raise ValueError('Unsupported routine signature: ' + name)
            statements.append('DROP FUNCTION ' + qualified(name[:-2]) + '() RESTRICT;')
        elif rule['action'] == 'replace':
            statements.append((DIRECTORY / rule['source']).read_text().replace(PLACEHOLDER, identifier(schema)))
    statements.append('COMMIT;')
    return '\n'.join(statements) + '\n'


def normalize_dump(sql, schema):
    identifier(schema)
    prologue = {
        'SET statement_timeout = 0;', 'SET lock_timeout = 0;',
        'SET idle_in_transaction_session_timeout = 0;', "SET client_encoding = 'UTF8';",
        'SET standard_conforming_strings = on;', 'SET check_function_bodies = false;',
        'SET xmloption = content;', 'SET client_min_messages = warning;', 'SET row_security = off;',
        "SET default_tablespace = '';", 'SET default_table_access_method = heap;',
        "SELECT pg_catalog.set_config('search_path', '', false);", 'CREATE SCHEMA ' + schema + ';',
    }
    lines = []
    for line in sql.splitlines():
        if line in prologue or line.startswith(('-- Dumped from database version ', '-- Dumped by pg_dump version ')):
            continue
        if re.fullmatch(r'\\(?:un)?restrict [A-Za-z0-9]+', line):
            continue
        lines.append(line.replace(schema + '.', PLACEHOLDER + '.'))
    return '-- Generated by scripts/build-control-schema.py. Do not edit.\n' + '\n'.join(lines).strip() + '\n'


class Fixture:
    def __init__(self, container, username, source_database, scratch_database):
        identifier(username)
        if source_database != SOURCE_DATABASE or scratch_database != SCRATCH_DATABASE:
            raise ValueError('Only the named CPDB-02 source and schema-build scratch fixture are allowed')
        inspect = json.loads(self.run(['docker', 'inspect', container]))[0]
        labels = inspect['Config'].get('Labels') or {}
        if (labels.get('com.docker.compose.project') != 'coreerp-cpdb02'
                or labels.get('coreerp.test-purpose') != 'cpdb-02' or not inspect['State']['Running']):
            raise ValueError('Container is not the owned, running CPDB-02 fixture')
        self.container = container
        self.username = username
        self.source_database = source_database
        self.scratch_database = scratch_database
        self.assert_identity(source_database)

    @staticmethod
    def run(args, sql=None):
        result = subprocess.run(args, input=sql, text=True, encoding='utf-8', capture_output=True)
        if result.returncode:
            raise RuntimeError(f'Fixture command failed ({args[0]}, exit {result.returncode}): {result.stderr.strip()}')
        return result.stdout

    def execute(self, database, sql, read_only=False, variables=None):
        if database not in (self.source_database, self.scratch_database, 'postgres'):
            raise ValueError('Database is outside the fixture allowlist')
        if database == self.source_database and not read_only:
            raise ValueError('The source fixture is read-only')
        args = ['docker', 'exec', '-i']
        if read_only:
            args += ['-e', 'PGOPTIONS=-c default_transaction_read_only=on']
        args += [self.container, 'psql', '-XAt', '-v', 'ON_ERROR_STOP=1', '-U', self.username, '-d', database]
        for name, value in (variables or {}).items():
            args += ['-v', name + '=' + value]
        return self.run(args, sql).strip()

    def assert_identity(self, database):
        identity = json.loads(self.execute(database,
            "SELECT json_build_array(current_database(), current_user);", read_only=True))
        if identity != [database, self.username]:
            raise ValueError('Actual database or role does not match the fixture target')

    def catalog(self, database, schema):
        return json.loads(self.execute(database, (DIRECTORY / 'catalog.sql').read_text(),
                                       read_only=True, variables={'schema': schema}))

    def dump(self, database, schema):
        return self.run(['docker', 'exec', '-e', 'PGOPTIONS=-c default_transaction_read_only=on', self.container,
                         'pg_dump', '--schema-only', '--no-owner', '--no-privileges', '--schema', schema,
                         '-U', self.username, '-d', database])

    def rebuild(self, rebuild_scratch):
        existing = self.execute('postgres', "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '"
                                + self.scratch_database + "';", read_only=True)
        if existing:
            if not rebuild_scratch or existing != self.username:
                raise ValueError('Scratch database exists; verify its owner and pass --rebuild-scratch')
            self.assert_identity(self.scratch_database)
            self.execute('postgres', 'DROP DATABASE ' + identifier(self.scratch_database) + ';')
        self.execute('postgres', 'CREATE DATABASE ' + identifier(self.scratch_database) + ' TEMPLATE template0;')
        self.assert_identity(self.scratch_database)


def metadata(plan, sql, catalog):
    return {
        'generator_version': GENERATOR_VERSION,
        'source': plan['source'],
        'plan_sha256': digest(plan),
        'routine_sources_sha256': {rule['source']: digest((DIRECTORY / rule['source']).read_text())
                                   for rule in plan['routines'].values() if rule['action'] == 'replace'},
        'baseline_sha256': digest(sql),
        'catalog_sha256': digest(catalog),
        'objects': {kind: len(catalog[kind]) for kind in ('tables', 'routines', 'sequences', 'types')},
        'rows_imported': 0,
    }


def check_files(plan):
    current = json.loads((DIRECTORY / 'baseline.metadata.json').read_text())
    sql = (DIRECTORY / 'baseline.sql').read_text()
    expected = metadata(plan, sql, {'tables': [], 'routines': [], 'sequences': [], 'types': []})
    for key in ('generator_version', 'source', 'plan_sha256', 'routine_sources_sha256', 'baseline_sha256', 'rows_imported'):
        if current[key] != expected[key]:
            raise ValueError('Stale baseline metadata: ' + key)
    if plan['source']['migration_sources_sha256'] != digest(migration_source()):
        raise ValueError('Source migration files changed; regenerate and review the baseline')
    validate_foundation(plan)


def build(args):
    plan = json.loads((DIRECTORY / 'partition-plan.json').read_text())
    validate_foundation(plan)
    if plan['source']['migration_sources_sha256'] != digest(migration_source()):
        raise ValueError('Source migration files changed; review the pinned fixture first')
    if args.offline:
        check_files(plan)
        print('Control baseline metadata and source fingerprints match.')
        return
    fixture = Fixture(args.container, args.username, args.source_database, args.scratch_database)
    schema = plan['source']['schema']
    catalog = fixture.catalog(fixture.source_database, schema)
    validate_plan(catalog, plan)
    applied = json.loads(fixture.execute(fixture.source_database,
        'SELECT json_agg(migration ORDER BY migration) FROM ' + identifier(schema) + '.migrations;', read_only=True))
    if applied != sorted(migration_source()):
        raise ValueError('Source fixture migration history does not match the pinned source')
    fixture.rebuild(args.rebuild_scratch)
    fixture.execute(fixture.scratch_database, fixture.dump(fixture.source_database, schema))
    staged = fixture.catalog(fixture.scratch_database, schema)
    fixture.execute(fixture.scratch_database, partition_sql(catalog, plan, schema))
    partitioned = fixture.catalog(fixture.scratch_database, schema)
    validate_partition(staged, plan, partitioned)
    sql = normalize_dump(fixture.dump(fixture.scratch_database, schema), schema)
    fixture.execute(fixture.scratch_database, 'CREATE SCHEMA ' + identifier(REPLAY_SCHEMA) + ';\n'
                    + sql.replace(PLACEHOLDER, identifier(REPLAY_SCHEMA)))
    replayed = normalize_catalog(fixture.catalog(fixture.scratch_database, REPLAY_SCHEMA), REPLAY_SCHEMA)
    target = normalize_catalog(partitioned, schema)
    if target != replayed:
        raise ValueError('Baseline replay differs from the partitioned PostgreSQL catalog')
    result = metadata(plan, sql, target)
    files = {'baseline.sql': sql, 'baseline.metadata.json': json.dumps(result, indent=2, ensure_ascii=False) + '\n'}
    for name, contents in files.items():
        path = DIRECTORY / name
        if args.write:
            path.write_text(contents, encoding='utf-8', newline='\n')
        elif not path.is_file() or path.read_text() != contents:
            raise ValueError('Generated baseline differs: ' + name)
    print('Control baseline ' + ('written' if args.write else 'verified') + '. Partition and replay catalogs match. '
          + str(result['objects']))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container', default='coreerp-cpdb02-database-1')
    parser.add_argument('--username', default='cpdb02_fixture')
    parser.add_argument('--source-database', default=SOURCE_DATABASE)
    parser.add_argument('--scratch-database', default=SCRATCH_DATABASE)
    parser.add_argument('--rebuild-scratch', action='store_true')
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--write', action='store_true', help='Write reviewed generated artifacts')
    modes.add_argument('--offline', action='store_true', help='Check committed source and output fingerprints without Docker')
    args = parser.parse_args()
    try:
        build(args)
    except (ValueError, RuntimeError, KeyError, OSError) as error:
        print(str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
