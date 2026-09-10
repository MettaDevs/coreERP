# Uji beban CoreERP

Satu stack untuk seluruh runtime. Sampai F7-03 ada dua: yang ini untuk Core, dan satu lagi di
dalam folder module Management Aset dengan image, database, dan **tiruan Core** sendiri. Bentuk
itu benar ketika module masih app berkontainer yang memanggil Core lewat HTTP. Ia tidak benar
lagi — dan yang paling penting, tiruannya sudah tidak mewakili apa pun: nomor sekarang
diterbitkan proses yang sama lewat kontrak `PenerbitNomor`.

```text
k6 ──► nginx (least_conn) ──► api1..api4  (image erp-core-app:local, Core + seluruh module)
                                   │
                                   └── pgbouncer (session pooling) ──► PostgreSQL 17
```

Empat instance bukan hiasan. Itu satu-satunya cara membuktikan runtime tidak menyimpan counter,
identitas tenant, atau cache izin di memori satu proses. Sesi disimpan di tabel `sessions`, jadi
satu sesi yang dilayani empat instance sekaligus adalah keadaan yang gagal kalau ada identitas
yang menempel pada satu proses.

Aturan gate-nya ada di [load dan concurrency testing](../../../docs/dev/20-load-and-concurrency-testing.md).

## Yang dibuang saat penggabungan

| Dibuang | Kenapa |
| --- | --- |
| `modules/apperp/management-aset/loadtest/stub-core/` | Number Sequence Core tiruan lewat HTTP. Di dalam satu runtime, nomor diterbitkan proses yang sama; tidak ada yang tersisa untuk ditiru. Oracle nomornya pindah ke tabel `number_sequence_issues`. |
| `modules/.../loadtest/docker-compose.yml`, `nginx.conf`, `apache-loadtest.conf` | Stack kedua dengan database dan load balancer sendiri. Sekarang hanya ada satu. |
| `modules/.../loadtest/mint-tenants.mjs` | Pencetak token konteks HS256. Rute module berada di belakang `['web', 'auth']`; identitasnya sesi Core, bukan bearer token. |
| `k6/tenants.json` | Berkas fixture berisi token. Tenant sekarang disiapkan lewat alur pendaftaran usaha yang sungguhan di dalam `setup()`. |

Yang **tidak** dipindahkan: skenario k6 dan `verify.sql` milik module tetap tinggal di
`modules/apperp/management-aset/loadtest/`, karena permukaan yang diuji memang milik module.
Keduanya berjalan di atas stack ini dan mengimpor `k6/lib.js` yang sama.

## Menjalankan

Semua perintah dijalankan dari folder ini. k6 tidak perlu terpasang di host.

```powershell
cd apps/control-plane/loadtest

# 1. Database dan pooler.
docker compose up -d --wait db pgbouncer

# 2. Migrasi, seed, dan pendaftaran katalog module. Urutannya sama dengan start.ps1 erp-dev.
#    Image `erp-core-app:local` dipakai apa adanya; bangun ulang lewat `start.ps1 -Build`
#    di erp-dev, atau `docker compose build api1` di sini.
docker compose run --rm --no-deps init

# 3. Empat instance di belakang nginx (port 18083).
docker compose up -d api1 api2 api3 api4 lb
```

Skenario dijalankan sebagai container k6 di jaringan stack. Dua mount: skrip Core dan skrip
module, sehingga `import ... from '../lib.js'` menunjuk berkas yang sama.

```powershell
$core = "$PWD\k6"
$aset = "$PWD\..\..\..\modules\apperp\management-aset\loadtest\k6"

# Skenario penjenuhan — 1000 VU, 128 tenant, 90 detik.
docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=128 -e VUS=1000 -e DURATION=90s `
  -e RUN_ID=gate-sat-3 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/master-data.js

# Skenario perlombaan tautan — 32 VU dipusatkan pada 4 tenant, 90 detik.
docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=link-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-race-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/maintenance.js

# Oracle SQL, dua-duanya pada database yang sama.
docker compose exec -T db psql -U core_erp -d core_erp -f - < verify.sql
docker compose exec -T db psql -U core_erp -d core_erp -f - < ..\..\..\modules\apperp\management-aset\loadtest\verify.sql
```

`FIXTURE` menamai kumpulan tenant. Menjalankan ulang dengan `FIXTURE` yang sama memakai kembali
tenant yang sudah ada (pendaftaran dijawab 422 lalu skrip langsung login), sehingga run kedua
tidak membayar ongkos penyiapan lagi.

Alur laporan lintas app punya skenarionya sendiri dan berjalan pada stack `erp-dev`, bukan di
sini: `k6/reporting-e2e.js`, lihat bagian paling bawah.

Stack dapat dihentikan tanpa kehilangan apa pun; data uji beban ada di volume `db-data`.

```powershell
docker compose stop                     # berhenti, data tetap
docker compose start                    # nyalakan lagi dengan data yang sama
docker compose down -v                  # hapus seluruh data uji beban
```

## Oracle, dan bukti bahwa ia bisa merah

Uji beban yang oracle-nya tidak pernah bisa merah selalu hijau, dan hijau semacam itu tidak
berarti apa-apa. Ketiga oracle di bawah karena itu pernah dijalankan dalam keadaan sengaja
dilanggar, dan angkanya dicatat.

| Oracle | Apa yang diperiksa | Dibuktikan merah dengan |
| --- | --- | --- |
| `verify.sql` (Core) | batas tenant, materialisasi sequence, terbitan nomor menembus tenant | pemeriksaan `default sequence` sempat memerah 903 saat ekspektasinya masih salah; skrip berhenti dengan exit 3 |
| `verify.sql` (module) | kode/`creation_key` ganda, anak lintas tenant, prefix reference, tiap kode terikat ke satu baris terbitan | `SELFTEST` SQL: satu baris `aset_m_group_aset` disuntik dengan kode `PBRA00001` → dua pemeriksaan naik ke 1, exit 3; baris dihapus, exit kembali 0 |
| Probe di dalam k6 | baca lintas tenant, tulis induk lintas tenant, eskalasi hak antar master | `SELFTEST=1` pada `master-data.js` → 42 dari 42 probe lintas tenant dan 21 dari 21 probe eskalasi tercatat sebagai pelanggaran, exit 99 |
| `link_merged_sets` | penggantian kaitan yang saling menyela | `SELFTEST=1` pada `maintenance.js` → 841 dari 841 pembacaan balik tertangkap, exit 99 |

`SELFTEST=1` merusak **permintaan**, bukan produknya: probe lintas tenant diarahkan ke record
milik sendiri, probe eskalasi memakai sesi yang memang berhak, dan penulis balapan mengirim
gabungan kedua himpunan. Yang dibuktikan karenanya adalah pendeteksinya hidup — bukan bahwa
produknya cacat.

Perintah persisnya:

```powershell
docker run ... -e SELFTEST=1 -e RUN_ID=selftest-master -e TENANTS=8 -e VUS=16 -e DURATION=25s ... /scripts/aset/master-data.js
docker run ... -e SELFTEST=1 -e RUN_ID=selftest-race -e VUS=32 -e DURATION=20s ... /scripts/aset/maintenance.js

docker compose exec -T db psql -U core_erp -d core_erp -c "insert into aset_m_group_aset (id, tenant_id, creation_key, kode, nama, aktif, created_at, updated_at) select 'ZZINJECTWRONGPREFIX000001', tenant_id, 'inject-wrong-prefix', 'PBRA00001', 'Suntikan prefix milik reference lain', true, now(), now() from aset_m_group_aset limit 1;"
docker compose exec -T db psql -U core_erp -d core_erp -f - < ..\..\..\modules\apperp\management-aset\loadtest\verify.sql   # exit 3
docker compose exec -T db psql -U core_erp -d core_erp -c "delete from aset_m_group_aset where id like 'ZZINJECT%';"
```

Satu hal yang ditemukan saat menyiapkan suntikan itu: **kunci asing komposit menolak induk
lintas tenant di lapis database.** Percobaan menyisipkan `aset_m_model_aset` yang menunjuk
pabrikan milik tenant lain ditolak `m_model_aset_tenant_id_pabrikan_aset_id_foreign` sebelum
sempat tersimpan. Pemeriksaan "anak menunjuk induk tenant lain" pada `verify.sql` karena itu
tidak dapat dibuat merah lewat suntikan langsung — yang menahannya bukan kodenya, melainkan
skema.

## Hasil terukur

Diukur **10 September 2026** pada Windows 11, 12 vCPU / 16 GB, Docker Desktop (WSL2) dengan
7,6 GB memori. Empat instance Apache prefork, `MaxRequestWorkers=32` per instance. Angka
throughput dan latensi terikat perangkat keras ini; angka kebenaran tidak.

### Gate kebenaran — LULUS

Skenario penjenuhan, `RUN_ID=gate-sat-3`: 1000 VU, 128 tenant, 90 detik, empat instance.
Diulang sebagai `gate-sat-2` dengan hasil kebenaran yang sama (0 dan 0).

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` (seluruh probe di dalam run) | 0 |
| `server_errors` (5xx dari aplikasi) | 0 |
| Probe baca/tulis lintas tenant | 225 percobaan, semua ditolak |
| Probe eskalasi hak antar master | 73 percobaan, semua 403 |
| Balapan idempotency yang terbukti replay | 162 |
| Iterasi / request | 7.452 / 10.661 |
| `verify.sql` Core — 12 pemeriksaan | semua 0 |
| `verify.sql` module — 26 pemeriksaan | semua 0 |
| Nomor terbit / nomor unik (seluruh rangkaian run) | 63.861 / 63.861 |

Skenario perlombaan tautan, `RUN_ID=gate-race-1`: 32 VU dipusatkan pada 4 tenant dan 4 job type,
90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `link_merged_sets` | 0 dari 3.920 pembacaan balik |
| `correctness_violations` | 0 |
| `server_errors` | 0 |
| Permintaan gagal | 0 |

### Bukti scale-out

Log nginx sesudah seluruh rangkaian run:

```text
9377 172.21.0.4:80
9376 172.21.0.5:80
9330 172.21.0.6:80
9324 172.21.0.7:80
```

Keempat instance menerima beban yang sama rata; tidak ada satu pun yang menjadi pemilik state.

### Gate latensi — LULUS pada 12 concurrent

Diambil pada concurrency yang masih tertahan, bukan pada titik jenuh.

| Concurrency | rps | read p95 | read p99 | write p95 | write p99 | Status |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 1 | 26 | 55 ms | 69 ms | 67 ms | 83 ms | memenuhi SLO |
| 8 | 73 | 131 ms | 163 ms | 167 ms | 233 ms | memenuhi SLO |
| 12 | 76 | 196 ms | 239 ms | 277 ms | 338 ms | memenuhi SLO (batas) |
| 16 | 78 | 253 ms | 289 ms | 358 ms | 444 ms | read p95 lewat batas 200 ms |
| 32 | 79 | 462 ms | 530 ms | 715 ms | 827 ms | jauh di atas batas |

Throughput mentok di ~75-79 rps sejak 8 VU: menambah concurrency setelah itu hanya menambah
antrean, bukan hasil.

Biaya request tunggal tanpa beban (1 VU):

| Jalur | Anggaran | Terukur |
| --- | --- | --- |
| Tanpa auth, tanpa database (`/up`) | < 25 ms | 24-46 ms dari host lewat port yang dipublish |
| Terautentikasi, baca (list/show master) | < 50 ms | 47 ms median |
| Tulis terautentikasi termasuk penerbitan nomor | < 120 ms | 55 ms median |

Angka `/up` diukur dari Windows host lewat port forward Docker Desktop, jadi ia memuat ongkos
yang tidak dimiliki jalur di dalam jaringan container. Ia disebut apa adanya, bukan dibulatkan
ke bawah.

### Perilaku pada 1000 VU

| Ukuran | Nilai |
| ---: | --- |
| rps | 65 |
| Timeout klien (k6, batas 60 detik) | 1.092 dari 10.661 request |
| 504/502 dari nginx | 0 |
| 5xx dari aplikasi | 0 |
| Pelanggaran kebenaran | 0 |

1000 VU melampaui kapasitas mesin ini; ia tidak melampaui kebenaran runtime. Tidak ada satu pun
5xx aplikasi — yang terjadi adalah klien menyerah menunggu antrean, dan itu dicatat sebagai
kapasitas, bukan sebagai cacat.

### Bottleneck: CPU PHP, bukan PostgreSQL

Diambil dengan `docker stats --no-stream` selama beban penuh:

| Container | CPU |
| --- | ---: |
| api1..api4 | 165-330% masing-masing (total ~11 core dari 12) |
| pgbouncer | 17-71% |
| PostgreSQL | 11-65% |

`pg_stat_activity` selama beban penuh: **39 backend** untuk 1000 VU. PgBouncer dengan session
pooling menahan jumlah koneksi server, jadi badai fork PostgreSQL yang menjadi bottleneck app
lama tidak terjadi di sini. Yang jenuh lebih dulu sekarang adalah CPU PHP.

Satu sebab yang dapat diperbaiki di lapis deployment, dan bukan di kode module:

> **`php artisan config:cache` gagal pada Core.** `config/scramble.php` memuat objek
> `ApiKeySecurityScheme` yang tidak punya `__set_state()`, sehingga config tidak dapat
> diserialisasi sama sekali (`value at "scramble.security_strategy.1.scheme" is
> non-serializable`). `php artisan route:cache` juga gagal karena `routes/web.php` masih memuat
> closure. Akibatnya setiap request membayar bootstrap Laravel penuh — pada stack ini, pada
> stack pengembangan, dan pada image yang dikirim ke pelanggan. Angka latensi di atas diukur
> dalam keadaan itu.

## Batas kejujuran hasil ini

- 1000 VU pada satu laptop mengukur antrean, bukan kapasitas produksi. Gate kebenaran tetap sah
  karena tidak bergantung pada perangkat keras; gate latensi harus diulang pada deployment yang
  representatif sebelum dipakai sebagai janji SLO.
- Satu sesi dipakai banyak VU. Itu disengaja (limiter login berlaku per email + IP, dan seribu
  VU yang masing-masing login akan mengunci dirinya sendiri), dan justru memperkuat pembuktian
  scale-out. Yang **tidak** diuji karenanya: pembuatan sesi serentak dalam jumlah besar.
- Dua skenario module belum dipindahkan ke runtime baru dan **belum diukur ulang**:
  `k6/work-order.js` dan `k6/depreciation.js` pada folder module. Keduanya berhenti dengan galat
  bila dijalankan, bukan hijau diam-diam. Permukaan work order dan penyusutan karena itu
  berstatus belum terverifikasi di bawah beban pada runtime ini.
- UI tidak disentuh uji beban ini.
- `config:cache` mati (lihat di atas), jadi angka latensi memuat ongkos bootstrap yang seharusnya
  hilang di produksi.

## Skenario Core lain di folder ini

| Berkas | Apa yang diuji | Status |
| --- | --- | --- |
| `k6/master-data.js` (module) | penjenuhan master + transaksi Management Aset | dijalankan F7-03 |
| `k6/maintenance.js` (module) | perlombaan penggantian kaitan | dijalankan F7-03 |
| `k6/registration.js` | registrasi tenant dan materialisasi Number Sequence | belum diukur ulang pada bentuk gabungan |
| `k6/reporting-e2e.js` | alur ekspor laporan lintas app pada stack `erp-dev` | tidak berubah |
| `k6/smoke.js` | pemeriksaan cepat bahwa penyiapan tenant dan rute module hidup | alat bantu, bukan gate |

### Uji end-to-end laporan pada stack `erp-dev`

`k6/reporting-e2e.js` berjalan pada stack pengembangan yang sudah hidup, bukan pada stack ini:
daftar tenant baru, login owner, katalog laporan, minta ekspor Excel dan PDF, tunggu worker Core,
unduh, lalu periksa riwayat hanya berisi milik tenant itu.

```powershell
docker run --rm -i -v "$PWD\k6:/scripts" -v "$PWD\results:/results" `
  -e BASE_URL=http://host.docker.internal:8000 -e VUS=3 `
  grafana/k6:0.55.0 run /scripts/reporting-e2e.js
```

Lebih dari lima VU sekaligus menabrak limiter registrasi tenant pada stack lokal
(`COREERP_REGISTRATION_RATE_LIMIT`, default 5 per menit per IP).
