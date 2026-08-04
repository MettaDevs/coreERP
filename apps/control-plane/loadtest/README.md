# Load test registrasi Control Plane

Harness ini menguji registrasi tenant dan materialisasi Number Sequence lewat empat instance API, satu load balancer, cache/session bersama, dan PostgreSQL asli.

Stack mempercayai hanya CIDR private Docker sebagai proxy. k6 mengirim IP klien berbeda per registrasi sehingga limiter tetap aktif tanpa membuat seluruh load balancer terlihat sebagai satu pengguna. Deployment nyata wajib mengisi `COREERP_TRUSTED_PROXIES` dengan IP/CIDR load balancer, bukan wildcard.

Harness mematikan pemeriksaan kebocoran password terhadap HIBP agar gate mengukur CoreERP, bukan layanan eksternal. Nilai produksi tetap aktif secara default melalui `COREERP_PASSWORD_BREACH_CHECK=true`; uji integrasi HIBP harus dijalankan terpisah.

## Menjalankan simulasi 100 tenant serentak

PowerShell:

```powershell
cd apps/control-plane/loadtest
$env:APP_MANIFEST_PATH = 'D:\path\ke\app.yaml'
docker compose build api1
docker compose up -d db pgbouncer
docker compose run --rm init
docker compose up -d api1 api2 api3 api4 lb
$env:PROFILE = 'registration'
$env:TENANTS = '100'
$env:RUN_ID = 'reg100'
docker compose run --rm --no-deps k6 run /scripts/registration.js
docker compose exec -T db psql -U core_erp -d core_erp -v run_id=reg100 -f /dev/stdin < verify.sql
```

`registration` membuat tepat satu tenant per VU. Setiap VU mengambil CSRF cookie dari `/register` lalu mengirim registrasi; GET dan POST boleh jatuh ke instance API berbeda sehingga shared session ikut diuji.

## Gate completion penuh

```powershell
$env:PROFILE = 'saturation'
$env:VUS = '1000'
$env:DURATION = '90s'
$env:RUN_ID = 'gate1'
docker compose run --rm --no-deps k6 run /scripts/registration.js
docker compose exec -T db psql -U core_erp -d core_erp -v run_id=gate1 -f /dev/stdin < verify.sql
```

Profil ini dapat membuat banyak tenant permanen. Stack memakai database khusus load test; jangan arahkan ke database dev atau produksi.

Gate latensi dijalankan terpisah agar antrean pada titik jenuh tidak disalahartikan sebagai biaya kode:

```powershell
$env:PROFILE = 'latency'
$env:LATENCY_VUS = '1'
$env:DURATION = '60s'
$env:RUN_ID = 'latency1'
docker compose run --rm --no-deps k6 run /scripts/registration.js
```

Ulangi dengan `LATENCY_VUS=2,4,8,...`; concurrency tertinggi yang masih memenuhi threshold adalah titik ukur latensi. Jangan gabungkan hasil beberapa tingkat concurrency.

## Oracle dan bukti scale-out

`verify.sql` membaca database langsung dan harus menghasilkan nol untuk semua pelanggaran: boundary tenant yang tidak lengkap, assignment role lintas tenant, sequence kurang/ganda, default bukan aktif `0-19999`, atau prefix salah.

Nginx mencatat upstream setiap request. Minimal dua alamat harus memiliki traffic:

```powershell
docker compose exec -T lb sh -c "cut -d' ' -f1 /var/log/nginx/upstream.log | sort | uniq -c"
```

Ambil bukti bottleneck setelah run:

```powershell
docker stats --no-stream
docker compose exec -T db psql -U core_erp -d core_erp -c "select state, count(*) from pg_stat_activity group by state order by state;"
```

Hapus seluruh data load test:

```powershell
docker compose down -v
```
