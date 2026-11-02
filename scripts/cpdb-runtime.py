"""Ambil snapshot CPDB dev1 melalui stdin SSH. Hanya metadata dan SELECT read-only.

Tidak boot Laravel, tidak membuat berkas remote, dan tidak mengeluarkan credential.
Koneksi MCP lokal tidak dapat memilih PostgreSQL di dev1, sehingga collector memakai
psql di container database yang sudah terdaftar pada konfigurasi runtime dev1.
"""

import argparse
import json
import pathlib
import runpy
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]

REMOTE = r'''
import datetime, hashlib, json, pathlib, subprocess

def run(args, text=None):
    result = subprocess.run(args, input=text, text=True, capture_output=True)
    if result.returncode:
        # stderr eksternal dapat berisi connection string. Jangan meneruskannya.
        raise RuntimeError('Read-only command failed: ' + args[0] + ', exit=' + str(result.returncode))
    return result.stdout.strip()

env_file = pathlib.Path('/etc/coreerp/saas.env')
assert env_file.is_file(), 'Konfigurasi runtime dev1 tidak ditemukan'
allowed = {'DB_DATABASE','DB_USERNAME','DB_HOST','DB_PORT','DB_CONNECTION','COREERP_CONTROL_CONNECTION'}
env = {}
for line in env_file.read_text().splitlines():
    if '=' in line and not line.startswith('#'):
        key, value = line.split('=',1)
        if key in allowed:
            env[key] = value.strip().strip('"').strip("'")

names = ['coreerp-saas-core-app-1','coreerp-saas-core-console-1',
         'coreerp-saas-core-worker-1','coreerp-saas-core-scheduler-1']
containers = []
keys = []
loader = r"""
$path='bootstrap/cache/config.php';
$cached=is_file($path);
if ($cached) { $c=require $path; }
else {
    require 'vendor/autoload.php';
    $app=require 'bootstrap/app.php';
    (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables)->bootstrap($app);
    (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
    $c=$app['config']->all();
}
"""
php = loader + r"""
$name=$c['database']['default'];
$db=$c['database']['connections'][$name];
$out=['config_cached'=>$cached,'default'=>$name,'database'=>array_intersect_key($db,array_flip(['driver','host','port','database','username','search_path'])),
'control_connection'=>$c['coreerp']['control_connection']??null,
'session'=>array_intersect_key($c['session']??[],array_flip(['driver','connection','store'])),
'cache_default'=>$c['cache']['default']??null,
'cache_database'=>array_intersect_key($c['cache']['stores']['database']??[],array_flip(['connection','table','lock_connection','lock_table'])),
'queue_default'=>$c['queue']['default']??null,
'queue_database'=>array_intersect_key($c['queue']['connections']['database']??[],array_flip(['connection','table','queue','retry_after','after_commit'])),
'queue_failed'=>array_intersect_key($c['queue']['failed']??[],array_flip(['driver','database','table'])),
'queue_batching'=>array_intersect_key($c['queue']['batching']??[],array_flip(['database','table'])),
'password_reset_connection'=>$c['auth']['passwords']['users']['connection']??null,
'app_key_present'=>!empty($c['app']['key']),
'previous_key_count'=>count($c['app']['previous_keys']??[])];
$out['key_files']=[];
foreach(['release_public_key_path','license_private_key_path','license_public_key_path'] as $key) {
    $file=$c['sites'][$key]??null;
    $out['key_files'][$key]=['configured'=>is_string($file)&&$file!=='','readable'=>is_string($file)&&is_readable($file)];
}
$out['source_file_hashes']=[];
foreach(['app/Platform/Environment/Support/EnvironmentConnection.php','app/Platform/ControlPlane/OwnedByControlPlane.php',
'app/Platform/Tenant/Actions/RegisterBusiness.php','app/Platform/Tenant/Actions/RedeemInvitation.php',
'app/Platform/Modules/Support/ModuleMigrator.php','app/Platform/Access/Support/AccessGuards.php',
'app/Environments/InstalledModules.php','config/database.php'] as $file) {
    if(is_file($file)) {$out['source_file_hashes'][$file]=hash_file('sha256',$file);}
}
// Hash hanya dibandingkan di memori collector dan tidak diterbitkan.
$out['_app_key_digest']=hash('sha256',$c['app']['key']??'');
echo json_encode($out,JSON_THROW_ON_ERROR);
"""
for name in names:
    inspect = json.loads(run(['docker','inspect',name]))[0]
    config = json.loads(run(['docker','exec',name,'php','-r',php]))
    keys.append(config.pop('_app_key_digest',None))
    containers.append({'name':name,'image':inspect['Config']['Image'],
        'image_id':inspect['Image'],'started_at':inspect['State']['StartedAt'],
        'running':inspect['State']['Running'],'configuration':config,
        'image_labels':{k:v for k,v in (inspect['Config'].get('Labels') or {}).items()
                        if k in ('org.opencontainers.image.revision','org.opencontainers.image.version','org.opencontainers.image.source')}})

business = containers[0]['configuration']
assert business['config_cached'], 'Periksa config efektif tanpa bootstrap sebelum melanjutkan'
database = business['database']['database']
role = business['database']['username']
assert env.get('DB_DATABASE') == database and env.get('DB_USERNAME') == role, 'Config cache berbeda dari env yang diperiksa'
assert database == 'core_erp', 'Target berbeda dari baseline CPDB yang disetujui'

sql = SQL_TEXT
assert sql.lstrip().startswith('-- CPDB-01:')
catalog = json.loads(run(['docker','exec','-i','-e',
    'PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=30000',
    'coreerp-saas-core-db-1','psql','-X','-A','-t','-v','ON_ERROR_STOP=1',
    '-U',role,'-d',database],sql))
assert catalog['database'] == database and catalog['transaction_read_only'] == 'on'

placement_sql="""SELECT coalesce(json_agg(x ORDER BY database_name),'[]') FROM (
SELECT DISTINCT e.database_name FROM public.environments e JOIN pg_database d ON d.datname=e.database_name
WHERE e.hosting='provider' AND e.status='active' AND e.database_name IS NOT NULL) x"""
placements=json.loads(run(['docker','exec','-i','-e',
    'PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=30000',
    'coreerp-saas-core-db-1','psql','-X','-A','-t','-v','ON_ERROR_STOP=1','-U',role,'-d',database],placement_sql))
additional=[]
for placement in placements:
    name=placement['database_name']
    assert __import__('re').fullmatch('[a-z][a-z0-9_]{0,62}',name)
    snapshot=json.loads(run(['docker','exec','-i','-e',
        'PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=30000',
        'coreerp-saas-core-db-1','psql','-X','-A','-t','-v','ON_ERROR_STOP=1','-U',role,'-d',name],sql))
    assert snapshot['database']==name and snapshot['transaction_read_only']=='on'
    additional.append(snapshot)

# current_database() melalui PDO tiap runtime, memakai config cached tanpa boot aplikasi.
probe = loader + r"""
$d=$c['database']['connections'][$c['database']['default']];
putenv('PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=30000');
$pdo=new PDO('pgsql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['database'],$d['username'],$d['password'],
[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$s=$pdo->query("SELECT current_database() AS database,current_user AS role,current_setting('transaction_read_only') AS read_only");
echo json_encode($s->fetch(PDO::FETCH_ASSOC));
"""
for c in containers:
    c['pdo_probe']=json.loads(run(['docker','exec',c['name'],'php','-r',probe]))

latency = loader + r"""
putenv('PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=30000');
$d=$c['database']['connections'][$c['database']['default']];
$pdo=new PDO('pgsql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['database'],$d['username'],$d['password'],
[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$sql="SELECT count(*) AS visible_rows FROM environment_members m JOIN environments e ON e.id=m.environment_id
JOIN tenant_memberships tm ON tm.tenant_id=e.tenant_id AND tm.user_id=m.user_id
WHERE m.status='active' AND tm.status='active' AND e.status='active'";
for($i=0;$i<3;$i++) {$pdo->query($sql)->fetchColumn();}
$batches=[];$errors=0;$counts=[];
for($b=0;$b<5;$b++) {
    $samples=[];
    for($i=0;$i<30;$i++) {
        $start=hrtime(true);
        try {$count=(int)$pdo->query($sql)->fetchColumn();$samples[]=(hrtime(true)-$start)/1e6;$counts[$count]=true;}
        catch(Throwable $e){$errors++;}
    }
    $batches[]=$samples;
}
echo json_encode(['query'=>$sql,'scope'=>'Single reused PDO connection, query+fetch, serial warm reads; excludes HTTP, auth, connection setup and SSH',
'warmup_queries'=>3,'batches_ms'=>$batches,'errors'=>$errors,'aggregate_result_variants'=>array_keys($counts)]);
"""
baseline=json.loads(run(['docker','exec',names[0],'php','-r',latency]))

result={'format_version':1,'evidence':'runtime-verified','host_alias':'dev1',
    'configuration_source':'/etc/coreerp/saas.env; cached config or Laravel config-only loader, without provider boot',
    'checkout_sha':run(['git','-C','/opt/coreerp','rev-parse','HEAD']),
    'captured_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'containers':containers,'app_keys_equal':bool(keys[0]) and len(set(keys))==1,
    'host_cpu_count':__import__('os').cpu_count(),
    'host_loadavg':pathlib.Path('/proc/loadavg').read_text().split()[:3],
    'latency_baseline':baseline,'catalog':catalog,'additional_databases':additional}
print(json.dumps(result,sort_keys=True))
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=pathlib.Path, required=True)
    args = parser.parse_args()
    ssh_config = pathlib.Path.home() / '.ssh/config'
    if not ssh_config.is_file() or 'Host dev1' not in ssh_config.read_text(encoding='utf-8'):
        parser.error('Baca/perbaiki alias dev1 pada konfigurasi SSH yang sah')
    sql = (ROOT / 'scripts/cpdb-runtime.sql').read_text(encoding='utf-8')
    remote = REMOTE.replace('SQL_TEXT', repr(sql))
    result = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'dev1', 'python3', '-'],
                            input=remote, text=True, encoding='utf-8', capture_output=True)
    if result.returncode:
        raise SystemExit('Collector dev1 gagal. Tidak ada output disimpan. Periksa prasyarat read-only.')
    data = json.loads(result.stdout)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    formatter = runpy.run_path(str(ROOT / 'scripts/cpdb-inventory.py'))['compact_json']
    args.output.write_text(formatter(data) + '\n', encoding='utf-8', newline='\n')
    print(json.dumps({'output':str(args.output),'database':data['catalog']['database'],
                      'relations':len(data['catalog']['relations']),'read_only':data['catalog']['transaction_read_only']}))


if __name__ == '__main__':
    main()
