"""Bangun/periksa inventaris CPDB-01 tanpa memuat Laravel atau mengakses database.

Pemindaian source bersifat lexical. Ia mencatat kandidat, bukan membuktikan SQL dinamis
atau transaksi sudah aman. Kontrak dan daftar blocker berada pada laporan CPDB-01.
"""

import argparse
import collections
import hashlib
import json
import pathlib
import re
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
DIRECTORY = ROOT / 'docs/todo/control-plane-database/inventory'


def compact_json(value, depth=0):
    """Satu record per baris agar metadata besar tetap dapat direview."""
    indent = '  ' * depth
    if isinstance(value, dict) and any(isinstance(v,dict) or isinstance(v,list) and any(isinstance(x,dict) for x in v) for v in value.values()):
        return '{\n' + ',\n'.join(
            '  ' * (depth + 1) + json.dumps(k) + ': ' + compact_json(v, depth + 1)
            for k, v in sorted(value.items())
        ) + '\n' + indent + '}'
    if isinstance(value, list):
        return '[\n' + ',\n'.join('  ' * (depth + 1) + compact_json(v,depth+1)
                                  for v in value) + '\n' + indent + ']'
    return json.dumps(value, ensure_ascii=False, sort_keys=True)


def read_json(name):
    return json.loads((DIRECTORY / name).read_text(encoding='utf-8'))


def source_files(revision):
    # Baca blob pada SHA baseline, bukan file kerja yang mungkin telah berubah.
    result = subprocess.check_output(['git','ls-tree','-r','--name-only',revision,'--',
                                      'apps/core','apps/control-plane','modules'],cwd=ROOT,text=True)
    paths = [p for p in result.splitlines() if p.endswith('.php') or re.match(r'^modules/[^/]+/[^/]+/app\.yaml$',p)]
    payload = ''.join(revision+':'+p+'\n' for p in paths).encode()
    data = subprocess.run(['git','cat-file','--batch'],cwd=ROOT,input=payload,capture_output=True,check=True).stdout
    cursor = 0
    files = []
    for path in paths:
        end = data.index(b'\n',cursor)
        header = data[cursor:end].split()
        if len(header)!=3 or header[1]!=b'blob':
            raise ValueError('Baseline blob unavailable: '+path)
        size = int(header[2])
        files.append((path,data[end+1:end+1+size].decode('utf-8-sig')))
        cursor = end+size+2
    return files


def without_comments(text):
    # Tokenizer kecil menjaga string SQL/PHP agar // pada string bukan dianggap komentar.
    pattern = r"('(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\")|(/\*[\s\S]*?\*/|//[^\n]*|\#[^\n]*)"
    return re.sub(pattern, lambda m: m[1] if m[1] else '\n' * m[0].count('\n'), text)


def snake(name):
    return re.sub(r'(?<!^)(?=[A-Z])', '_', name).lower()


def without_down(text):
    match = re.search(r'public\s+function\s+down\s*\([^)]*\)[^{]*\{', text)
    if not match:
        return text
    depth = 1
    for token in re.finditer(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|[{}]",text[match.end():]):
        if token[0] == '{':
            depth += 1
        elif token[0] == '}':
            depth -= 1
        if depth == 0:
            end = match.end() + token.end()
            return text[:match.start()] + '\n' * text[match.start():end].count('\n') + text[end:]
    raise ValueError('Unclosed down()')


def build():
    rules = read_json('ownership-rules.json')
    runtime = read_json('runtime-dev1.json')
    primary = runtime['catalog']
    catalogs = [primary, *runtime.get('additional_databases', [])]
    catalog = {key:[dict(row,database=c['database']) for c in catalogs for row in c[key]]
               for key in ('relations','columns','foreign_keys','routines','triggers','sequence_dependencies','view_dependencies','indexes')}
    catalog['migrations'] = primary['migrations']
    if any(r['schema'] != 'public' for r in catalog['relations']):
        raise ValueError('Schema baru memerlukan kontrak ownership eksplisit')
    ownership = {}
    for disposition in ('move', 'retain', 'projection'):
        for domain, tables in rules[disposition].items():
            for table in tables.split():
                if table in ownership:
                    raise ValueError('Duplicate ownership: ' + table)
                ownership[table] = {'disposition': disposition, 'domain': domain}

    files = source_files(rules['source_sha'])
    module_prefixes = {}
    module_ids = {}
    for path,text in files:
        if not path.endswith('/app.yaml'):
            continue
        match = re.search(r'^table_prefix:\s*([a-z_][a-z_0-9]*)',text,re.M)
        if match:
            module_prefixes[match[1]] = pathlib.PurePosixPath(path).parent.as_posix()
        identity = re.search(r'^id:\s*([a-z][a-z0-9_-]*)',text,re.M)
        if identity:
            module_ids[pathlib.PurePosixPath(path).parent.as_posix()] = identity[1]
    for relation in catalog['relations']:
        for prefix,module in module_prefixes.items():
            if relation['name'].startswith(prefix) and relation['relkind'] != 'S':
                ownership.setdefault(relation['name'],{'disposition':'retain','domain':module})
    migrations = []
    table_sources = collections.defaultdict(list)
    migration_texts = {}
    for path, original in files:
        if not (path.startswith('apps/core/database/migrations/') or re.match(r'^modules/[^/]+/[^/]+/database/migrations/',path)):
            continue
        text = without_comments(original)
        up = without_down(text)
        tables = set(re.findall(r'''(?:Schema::|->)(?:create|table|rename|dropIfExists|drop|hasTable)\(\s*['"]([a-z_][a-z_0-9]*)''', up))
        tables.update(re.findall(r'''DB::table\(\s*['"]([a-z_][a-z_0-9]*)''', up))
        # Target rename kedua dan SQL mentah. Tidak membaca down() sebagai penghapusan aktif.
        sql_tables = re.findall(r'''\b(?:RENAME\s+TO|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE|UPDATE|INSERT\s+INTO|DELETE\s+FROM|ON)\s+(?:"?public"?\.)?"?([a-z_][a-z_0-9]*)"?''', up, re.I)
        tables.update(t for t in sql_tables if t in ownership or any(t.startswith(p) for p in module_prefixes))
        for old, new in re.findall(r'''(?:Schema::|->)rename\(\s*['"]([^'"]+)['"]\s*,\s*['"]([^'"]+)''', up):
            tables.update((old, new))
        module = path.split('/database/migrations/')[0] if path.startswith('modules/') else None
        if module:
            for table in tables:
                if table not in ownership:
                    ownership[table] = {'disposition':'retain','domain':module}
        for table in tables:
            table_sources[table].append(path)
        migration_texts[path] = up
        migrations.append({'path':path,'name':pathlib.PurePosixPath(path).stem,
                           'module':module,'tables':sorted(tables),
                           'sha256':hashlib.sha256(original.encode()).hexdigest()})

    for r in catalog['relations']:
        if r['relkind'] in ('r', 'p', 'v', 'm', 'f') and r['name'] not in ownership:
            raise ValueError('Unclassified runtime relation: ' + r['name'])
    for name in table_sources:
        if name not in ownership:
            raise ValueError('Unclassified source table: ' + name)

    missing_migrations = []
    for m in migrations:
        up = migration_texts[m['path']]
        # Data migrations sering tidak memakai Schema. Hanya token identifier yang dikenal.
        literals = set(re.findall(r'''['"]([a-z_][a-z_0-9]*)['"]''', up))
        literals.update(re.findall(r'''\bpublic\.([a-z_][a-z_0-9]*)''',up))
        m['tables'] = sorted(set(m['tables']) | (literals & ownership.keys()))
        decision = rules['migration_overrides'].get(m['name'])
        if m['module']:
            decision = ['retain', m['module']]
        elif not decision:
            dispositions = {ownership[t]['disposition'] for t in m['tables']}
            if not dispositions:
                missing_migrations.append(m['name'])
                continue
            decision = [next(iter(dispositions)) if len(dispositions) == 1 else 'projection',
                        'table-owner' if len(dispositions) == 1 else 'mixed-history-partition']
        m['disposition'], m['reason'] = decision
        m['runtime_databases'] = [c['database'] for c in catalogs if (
            any(r['migration']==m['name'] and r['module_id']==module_ids.get(m['module']) for r in c.get('module_migrations',[]) or [])
            if m['module'] else any(r['migration']==m['name'] for r in c['migrations']))]
        m['runtime_applied'] = primary['database'] in m['runtime_databases']
    if missing_migrations:
        raise ValueError('Migrations need manual classification: ' + ', '.join(missing_migrations))
    contracts = read_json('contracts.json')

    # Mapping model ke tabel: eksplisit dahulu, lalu bentuk default Laravel yang cocok registry.
    models = {}
    markers = []
    for path, original in files:
        if '/Models/' not in path or '/tests/' in path:
            continue
        text = without_comments(original)
        namespace = re.search(r'namespace\s+([^;]+);', text)
        cls = re.search(r'\bclass\s+(\w+)', text)
        if not namespace or not cls:
            continue
        explicit = re.search(r'''\$table\s*=\s*['"]([^'"]+)''', text)
        base = snake(cls[1])
        candidates = [base, base + 's', base[:-1] + 'ies' if base.endswith('y') else base + 'es']
        table = explicit[1] if explicit else next((c for c in candidates if c in ownership), None)
        if table and table in ownership:
            fqcn = namespace[1] + '\\' + cls[1]
            models[fqcn] = table
            if re.search(r'\buse\s+OwnedByControlPlane\b', text):
                markers.append({'model':fqcn,'path':path,'table':table,'target':ownership[table]})

    references = []
    for path, original in files:
        if '/tests/' in path or '/database/' in path or '/contracts/' in path:
            continue
        text = without_comments(original)
        imports = {alias or fqcn.rsplit('\\',1)[-1]:models[fqcn]
                   for fqcn,alias in re.findall(r'\buse\s+([\w\\]+)(?:\s+as\s+(\w+))?\s*;',text)
                   if fqcn in models}
        tables = set()
        sites = []
        for line_number, line in enumerate(text.splitlines(), 1):
            matched = set(re.findall(r'''['"]([a-z_][a-z_0-9]*)(?:\s+as\s+\w+)?['"]''', line)) & ownership.keys()
            matched |= {table for name,table in imports.items() if re.search(r'\b'+name+r'(?:::|\s+\$|::class)',line)}
            if matched:
                tables |= matched
                sites.append({'line':line_number,'tables':sorted(matched)})
        operations = []
        for kind, pattern in {
            'join':r'->(?:join|leftJoin|rightJoin|joinSub|leftJoinSub)\(',
            'subquery':r'->(?:selectSub|fromSub)\(',
            'subquery-relation':r'->(?:whereHas|orWhereHas|whereDoesntHave|has)\(',
            'eager-loading':r'->(?:with|load|loadMissing)\(',
            'transaction':r'(?:DB::transaction|->transaction|DB::afterCommit)\(',
            'write-candidate':r'(?:::|->)(?:create|update|insert|save|delete|upsert|updateOrCreate|updateOrInsert|firstOrCreate)\(',
            'actor-lookup':r'(?:actor|createdBy|updatedBy|initiator|completedBy|requestedBy|performedBy)',
            'connection':r'(?:DB::connection|EnvironmentConnection|runWithin|controlPlane|useForRequest|\$connection)',
        }.items():
            lines = [text.count('\n',0,m.start())+1 for m in re.finditer(pattern,text)]
            if lines:
                operations.append({'kind':kind,'lines':sorted(set(lines))})
        if tables or any(o['kind'] in ('transaction','connection') for o in operations):
            refs = sorted(tables)
            destinations = {ownership[t]['disposition'] for t in refs}
            replacements = set()
            if any(t in refs for t in ('users','tenant_memberships','tenants')):
                replacements.add('X01')
            for fragment, ids in {
                '/Tenant/Actions/RegisterBusiness.php':['X02','X07'],
                '/Tenant/Actions/RedeemInvitation.php':['X03','X07'],
                '/Tenant/Models/InvitationCode.php':['X03'],
                '/Identity/Http/Controllers/SsoLoginController.php':['X03','X06'],
                '/Organization/':['X04'], '/AddressBook/':['X04'],
                '/Integration/Support/IntegrationClientAccounts.php':['X09'],
                '/ChangeLog/Support/AuditActor.php':['X06','X07','X08'],
                '/ChangeLog/Support/ChangeLogSwitch.php':['X07'],
                '/Attachments/Support/CoreAttachmentStorage.php':['X11'],
                '/Console/Commands/SetCloudflareToken.php':['X08'],
                '/Console/Commands/SetRegistryRobot.php':['X08'],
                '/Http/Controllers/MasterLinkController.php':['X11'],
                '/Analytics/':['X12'],
            }.items():
                if fragment in path:
                    replacements.update(ids)
            if any(t in refs for t in ('apps','app_dependencies','core_module_installations','permissions')):
                replacements.add('X05')
            if any(o['kind']=='actor-lookup' for o in operations):
                replacements.add('X06')
            if any(ownership[t]['domain']=='per-database-infrastructure' for t in refs):
                replacements.add('X07')
            if any(o['kind']=='connection' for o in operations) or '/ControlPlane/' in path or '/Environments/' in path:
                replacements.add('X08')
            if any(ownership[t]['domain'] in ('reference-release','tenant-address-reference-partition') for t in refs):
                replacements.add('X10')
            if len(destinations)>1 or 'projection' in destinations:
                replacements.add('X11')
            references.append({'path':path,'tables':refs,'sites':sites,'operations':operations,
                               'cross_boundary_candidate':len(destinations)>1 or 'projection' in destinations,
                               'replacement_contracts':sorted(replacements),
                               'dynamic_trace_required':not refs and bool(operations),
                               'evidence':'source-only-lexical'})
    if any(code not in contracts['coupling_replacements'] for ref in references for code in ref['replacement_contracts']):
        raise ValueError('Source replacement contract is missing')

    runtime_tables = {r['name']:r for r in primary['relations'] if r['relkind'] != 'S'}
    tables = []
    for name, decision in sorted(ownership.items()):
        tables.append({'table':name,**decision,'source_migrations':sorted(set(table_sources[name])),
                       'runtime_present':name in runtime_tables,
                       'bytes':runtime_tables.get(name,{}).get('bytes'),
                       'runtime_databases':[c['database'] for c in catalogs if any(r['name']==name and r['relkind']!='S' for r in c['relations'])],
                       'models':[k for k,v in sorted(models.items()) if v==name],
                       'writer_candidates':[r['path'] for r in references if name in r['tables'] and any(o['kind']=='write-candidate' for o in r['operations'])],
                       'replacement_contract':decision['domain']})
    objects = []
    for r in catalog['relations']:
        if r['relkind'] == 'S':
            parent = next((d['relation'] for d in catalog['sequence_dependencies'] if d['sequence']==r['name'] and d['schema']==r['schema'] and d['database']==r['database']),None)
            if not parent:
                raise ValueError('Unowned sequence: '+r['name'])
            objects.append({'kind':'sequence','database':r['database'],'schema':r['schema'],'name':r['name'],'parent':parent,**ownership[parent]})
        else:
            objects.append({'kind':r['relkind'],'database':r['database'],'schema':r['schema'],'name':r['name'],**ownership[r['name']]})
    for fk in catalog['foreign_keys']:
        a,b = ownership[fk['source']],ownership[fk['target']]
        cross = a['disposition'] != b['disposition'] or 'projection' in (a['disposition'],b['disposition'])
        objects.append({'kind':'foreign-key','database':fk['database'],'schema':fk['schema'],'name':fk['name'],
                        'source':fk['source'],'target':fk['target'],'columns':fk['columns'],
                        'disposition':'projection' if cross else a['disposition'],
                        'replacement':'local-projection-fk-or-local-partition' if cross else 'same-owner-fk',
                        'cross_boundary_candidate':cross})
    for f in catalog['routines']:
        if f['extension']:
            decision = ['retain','extension-owned']
        else:
            decision = rules['routine_rules'].get(f['name'])
        if not decision:
            raise ValueError('Unclassified routine: '+f['name'])
        objects.append({'kind':'routine','database':f['database'],'schema':f['schema'],'name':f['name'],
                        'disposition':decision[0],'domain':decision[1],
                        'lexical_relations':f['lexical_relations'],'body_md5':f['body_md5']})
    for t in catalog['triggers']:
        objects.append({'kind':'trigger','database':t['database'],'schema':t['schema'],'name':t['name'],'parent':t['relation'],
                        'function':t['function'],'internal':t['internal'],**ownership[t['relation']]})
    for i in catalog['indexes']:
        objects.append({'kind':'index','database':i['database'],'schema':i['schema'],'name':i['name'],
                        'parent':i['relation'],'columns':i['columns'],'partial':i['partial'],**ownership[i['relation']]})
    for v in catalog['view_dependencies']:
        objects.append({'kind':'view-dependency','database':v['database'],'schema':v['schema'],
                        'name':v['view'],'target':v['target'],'disposition':'projection',
                        'replacement':'local-projection-or-owner-query'})
    fields = []
    partition_tables = {'organizations','legal_entities','operating_units','organization_parties',
                        'parties','locations','postal_addresses','electronic_addresses','party_locations','party_location_purposes'}
    generic_party = set('id tenant_id name search_name type status organization_number duns_number tax_number number_of_employees language created_at updated_at created_by_user_id updated_by_user_id deleted_at version'.split())
    for c in primary['columns']:
        if c['relation'] not in partition_tables:
            continue
        authority = 'organization-foundation'
        context = 'all-organization-rows'
        if c['relation']=='legal_entities' and c['name'] in ('company_code','fiscal_calendar_id'):
            authority = 'erp-company-extension'
        elif c['relation']=='parties':
            context = 'organization-linked-subset; all-other-party-rows-retain-ERP'
            if c['name'] not in generic_party:
                authority = 'erp-party-extension'
        elif c['relation'] not in ('organizations','legal_entities','operating_units','organization_parties'):
            context = 'organization-reachable-address-subset; other-rows-retain-ERP'
        fields.append({'table':c['relation'],'field':c['name'],'source_type':c['type'],
                       'authority':authority,'row_partition':context,
                       'rule':'separate-domain-revision; preserve-source-checkpoint' if c['name']=='version' else 'preserve-id-and-provenance',
                       'evidence':'runtime-column + target-design; X04'})
    baseline_sha = rules['source_sha']
    summary = {'format_version':1,'source_sha':baseline_sha,'runtime_sha':runtime['checkout_sha'],
               'source_inputs_sha256':hashlib.sha256(''.join(path+'\0'+hashlib.sha256(content.encode()).hexdigest()+'\n' for path,content in files).encode()).hexdigest(),
               'catalog_snapshot_sha256':hashlib.sha256((DIRECTORY/'runtime-dev1.json').read_text(encoding='utf-8').encode('utf-8')).hexdigest(),
               'table_decisions':dict(collections.Counter(t['disposition'] for t in tables)),
               'runtime_objects':dict(collections.Counter(o['kind'] for o in objects)),
               'source_migrations':len(migrations),'source_reference_files':len(references),
               'cross_boundary_source_candidates':sum(r['cross_boundary_candidate'] for r in references),
               'dynamic_source_files':sum(r['dynamic_trace_required'] for r in references),
               'cross_boundary_fk_candidates':sum(o.get('cross_boundary_candidate',False) for o in objects),
               'unclassified_objects':0,
               'limits':['Source references are lexical candidates, not proof of executed queries.',
                         'Dynamic SQL and framework-generated relations need targeted rehearsal.',
                         'Target classification is not proof of migration, copy, privilege isolation or projection delivery.']}
    return {'manifest.json':{'summary':summary,'tables':tables,'objects':objects,'markers':markers},
            'migrations.json':migrations,'source-references.json':references,'field-partitions.json':fields}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check',action='store_true')
    parser.add_argument('--check-staged',action='store_true')
    args = parser.parse_args()
    if args.check_staged:
        allowed = set(read_json('staging-allowlist.json')['paths'])
        staged = set(subprocess.check_output(['git','diff','--cached','--name-only'],cwd=ROOT,text=True).splitlines())
        if not staged or staged - allowed:
            raise SystemExit('Staging is empty or outside allowlist: '+json.dumps({'extra':sorted(staged-allowed)}))
        print('Staged paths are within CPDB-01 allowlist: '+str(len(staged)))
        return
    for name, value in build().items():
        content = compact_json(value) + '\n'
        target = DIRECTORY / name
        if args.check:
            if not target.is_file() or target.read_text(encoding='utf-8') != content:
                raise SystemExit('Inventory differs: '+str(target))
        else:
            target.write_text(content,encoding='utf-8',newline='\n')
    print('CPDB inventory: coverage complete, no unclassified objects; '+('checked' if args.check else 'generated'))


if __name__ == '__main__':
    main()
