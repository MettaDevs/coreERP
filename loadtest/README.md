# Load test Management Aset

Test feature menjalankan satu request pada satu proses terhadap SQLite. Ia tidak dapat melihat koneksi habis, nomor ganda, batas tenant yang bocor hanya saat request saling menyela, atau idempotency key yang berlomba dengan dirinya sendiri. Direktori ini menutup celah itu.

Gate-nya ada di `.claude/skills/coreerp-architecture/SKILL.md` bagian **Module completion gate: concurrency and load**.

## Bentuk stack

```text
k6 (1000 VU, 128 tenant)
      │
      ▼
   nginx  ── least_conn, X-Upstream membuktikan penyebaran
      │
      ├── api1 ┐
      ├── api2 ├─ 4 server instance, Apache prefork, config+route cache
      ├── api3 │
      └── api4 ┘
             │
             ├── PostgreSQL 17   (satu database bersama)
             └── core-stub       (Number Sequence Core tiruan + oracle nomor)
```

Empat instance bukan hiasan. Itu satu-satunya cara membuktikan modul tidak menyimpan counter, identitas tenant, atau cache permission di memori satu proses API. `core-stub` mencatat setiap nomor yang diterbitkan, sehingga nomor ganda tidak mungkin lolos tanpa ketahuan.

## Menjalankan

```bash
cd loadtest
docker compose build api1 core-stub
docker compose up -d db core-stub pgbouncer
docker compose run --rm --no-deps api1 sh /coreerp/migrate.sh
DB_TARGET_HOST=db DB_TARGET_PORT=5432 DB_PERSISTENT=true docker compose up -d api1 api2 api3 api4 lb
```

```bash
node mint-tenants.mjs
```

Token dari command ini sudah memuat permission `penyusutan.read`,
`penyusutan.create`, `penyusutan.finalize`, dan `penyusutan.correct` untuk
skenario berikut. Fixture default berisi 128 tenant.

```bash
docker run --rm --network aset-loadtest_default --ulimit nofile=65536:65536 -v "$PWD/k6:/scripts:ro" -v "$PWD/results:/results" -e BASE_URL=http://lb -e PROFILE=saturation -e VUS=1000 -e DURATION=120s -e RUN_ID=run1 grafana/k6:0.55.0 run /scripts/master-data.js
```

Skenario penyusutan menyiapkan tiga periode per tenant dan menguji retry
proposal/finalisasi pada 1000 VU:

```bash
docker run --rm --network aset-loadtest_default --ulimit nofile=65536:65536 -v "$PWD/k6:/scripts:ro" -v "$PWD/results:/results" -e BASE_URL=http://lb -e PROFILE=saturation -e VUS=1000 -e DURATION=90s -e RUN_ID=dep-run1 grafana/k6:0.55.0 run /scripts/depreciation.js
```

```bash
docker compose exec -T db psql -U aset -d app_erp_management_aset -f - < verify.sql
```

```bash
docker compose down -v
```

Pada Git Bash Windows, awali perintah `docker` yang memuat path container dengan `MSYS_NO_PATHCONV=1`.

## Dua profil, dua pertanyaan berbeda

| Profil | Menjawab | Yang digate |
| --- | --- | --- |
| `PROFILE=saturation VUS=1000` | Apakah modul tetap **benar** saat jenuh? | Kebenaran: 0 pelanggaran, 0 error aplikasi |
| `PROFILE=latency` | Berapa concurrency yang masih memenuhi SLO? | p95/p99 per jenis operasi |

Latensi pada beban jenuh mengukur kedalaman antrean, bukan biaya kode. Karena itu gate latensi diambil pada concurrency yang masih tertahan, bukan pada titik jenuh.

## Oracle kebenaran

Tiga sumber terpisah, tidak ada yang memakai kode yang sedang diuji sebagai hakim:

1. **`verify.sql`** — langsung ke database: kode ganda per tenant, `creation_key` ganda, anak yang menunjuk induk tenant lain, anak yatim, prefix kode yang tertukar antar reference, `tenant_id` bukan ULID.
2. **`core-stub /__stats`** — jumlah nomor terbit vs jumlah nomor unik. Selisih apa pun berarti satu nomor diterbitkan dua kali.
3. **Probe di dalam k6** — token tenant A membaca record tenant B (harus 404), menulis anak di bawah induk tenant B (harus 422), dan tenant yang hanya punya `group-aset.read` membuka `model-aset` (harus 403). Semuanya berjalan **selama** beban penuh, bukan sesudahnya.

Untuk depresiasi, oracle juga memeriksa nilai penyusutan negatif, NBV di bawah
residual atau nol, finalisasi periode ganda, dan saldo round-off yang tidak
mendarat di residual.

## Hasil terukur

Diukur 2026-07-26 pada Windows 11, 12 vCPU / 16 GB, Docker Desktop (WSL2) dengan 12 vCPU / 7,6 GB. Angka throughput terikat perangkat keras ini; angka kebenaran tidak.

### Gate kebenaran — LULUS pada 1000 VU

| Pemeriksaan | Hasil |
| --- | --- |
| Kode ganda dalam satu tenant | 0 |
| `creation_key` ganda dalam satu tenant | 0 |
| Anak menunjuk induk tenant lain | 0 |
| Anak dengan induk tidak ada | 0 |
| Prefix kode tidak sesuai reference master | 0 |
| `tenant_id` kosong atau bukan ULID | 0 |
| Nomor ganda dari Core (4.342 terbit / 4.342 unik) | 0 |
| Probe baca lintas tenant | 556 percobaan, semua 404 |
| Probe eskalasi hak antar master | 303 percobaan, semua 403 |
| Error 5xx dari aplikasi | 0 |

4.342 record tersebar pada 128 tenant dan kedelapan master.

### Gate latensi — LULUS pada 16 concurrent

| Concurrency | rps | read p95 | read p99 | write p95 | write p99 | Status |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 16 | 177 | 149 ms | 204 ms | 194 ms | 259 ms | memenuhi SLO |
| 32 | 176 | 343 ms | 451 ms | 405 ms | 532 ms | p95 read/write lewat batas |
| 128 | 188 | 926 ms | 1.259 ms | 992 ms | 1.332 ms | jauh di atas batas |

Throughput mentok di ~180 rps sejak 16 VU: menambah concurrency setelah itu hanya menambah antrean, bukan hasil. Biaya request tunggal tanpa beban tetap sehat — health 10 ms, list satu tabel 27 ms, list dengan join induk 35 ms — jadi yang habis adalah kapasitas mesin, bukan efisiensi kode modul.

### Perilaku pada 1000 VU

| Konfigurasi | rps | gagal | timeout klien | 5xx | pelanggaran |
| --- | ---: | ---: | ---: | ---: | ---: |
| PgBouncer session pooling, 80 worker | 95 | 13% | 1.447 | 101 | 0 |
| Koneksi persisten, 192 worker | 138 | 5% | 656 | 48 | 0 |

Seluruh 5xx adalah **504 dari nginx** (`upstream timed out`), bukan 500 dari aplikasi. Log API tidak memuat satu pun `SQLSTATE` atau fatal PHP. 1000 VU melampaui kapasitas mesin ini; ia tidak melampaui kebenaran modul.

## Yang ditemukan load test dan tidak ditemukan test feature

**Penanganan koneksi database menjadi bottleneck jauh sebelum kode modul.** Laravel membuka lalu menutup satu koneksi PostgreSQL per request, dan PostgreSQL fork satu proses per koneksi. Terukur: **36.809 session untuk 282.000 transaksi**, dan PostgreSQL membakar **5,5 core** hanya untuk fork.

| Konfigurasi | CPU PostgreSQL | Catatan |
| --- | ---: | --- |
| Koneksi baru tiap request | ~530% | fork per request |
| PgBouncer session pooling | ~27% | PgBouncer sendiri jadi bottleneck: single-threaded, ~100% satu core, scram-sha-256 dihitung ulang tiap koneksi klien |
| Koneksi persisten (`DB_PERSISTENT=true`) | ~410% | murni kerja query; throughput tertinggi |

Karena itu `config/database.php` kini punya `DB_PERSISTENT`, default mati. Nyalakan pada deployment dengan worker proses tetap, dan jaga `MaxRequestWorkers × jumlah instance` tetap di bawah `max_connections`.

Temuan lain: route `context` dulunya closure, yang diam-diam mematikan `php artisan route:cache`. Sekarang `ContextController`.

## Catatan jujur soal batas test ini

- 1000 VU pada satu laptop mengukur antrean, bukan kapasitas produksi. Gate kebenaran tetap sah karena tidak bergantung pada perangkat keras; gate latensi harus diulang pada deployment yang representatif sebelum dipakai sebagai janji SLO.
- `core-stub` bukan Control Plane. Ia menegakkan kontrak issue + idempotency dan mencatat nomor, tetapi tidak menguji continuous sequence, reservation, atau failover Core.
- Token konteks di-mint dengan TTL panjang supaya tidak kedaluwarsa di tengah run. Masa hidup token diuji di test feature, bukan di sini.
- UI tidak disentuh load test ini.
- `depreciation.js` menguji proposal/finalisasi yang sama secara idempotent setelah
  setup deterministik; urutan perhitungan dan saldo awal divalidasi saat setup,
  sedangkan race saat beban penuh memvalidasi saldo tidak berubah.
