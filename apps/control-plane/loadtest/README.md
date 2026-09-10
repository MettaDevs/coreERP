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

### Gate latensi — LULUS pada 12 concurrent (F7-03, tanpa cache setelan dan rute)

Diambil pada concurrency yang masih tertahan, bukan pada titik jenuh. Diukur **sebelum** F7-10,
jadi tanpa `config:cache` maupun `route:cache`; ukur ulang sesudahnya ada di bawah tabel ini.

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

### Ukur ulang sesudah setelan dan rute di-cache (F7-10) — gate tidak bergeser

Diukur **10 September 2026 pukul 09.12–09.37**, cara dan ambang sama persis dengan tabel di atas:
`PROFILE=latency`, `TENANTS=32`, `DURATION=60s`, `FIXTURE=g1`, `LATENCY_VUS` dinaikkan bertahap.

```powershell
docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=latency -e TENANTS=32 -e LATENCY_VUS=12 `
  -e DURATION=60s -e RUN_ID=lat-cache-12 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/master-data.js
```

Kedua cache dipastikan ada di dalam keempat instance uji beban lebih dulu: container dibuat ulang
di atas `erp-core-app:local` yang baru (`sha256:ecee33ee…`), entrypoint mencetak
`Configuration cached successfully` dan `Routes cached successfully` tanpa satu pun peringatan,
`php artisan about` menjawab Config **CACHED** dan Routes **CACHED** pada keempatnya,
`bootstrap/cache/` memuat `config.php` (123 KB) dan `routes-v7.php` (543 KB), dan jumlah rute
ter-cache tetap 383.

Tiap tingkat dijalankan pada **kedua keadaan** — cache nyala dan cache mati — bergantian, pada
mesin dan database yang sama, karena angka absolut hari ini tidak sebanding dengan F7-03 (lihat
di bawah). Cache dimatikan dengan `config:clear` + `route:clear` pada keempat instance, dan
dinyalakan kembali dengan `config:cache` + `route:cache`.

| Concurrency | Cache | rps | read p95 | read p99 | write p95 | write p99 | Status |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | --- |
| 1 | nyala | 20 | 89 ms | 103 ms | 110 ms | 123 ms | memenuhi SLO |
| 1 | mati | 20 | 92 ms | 118 ms | 110 ms | 132 ms | memenuhi SLO |
| 1 | mati (ulang) | 23 | 69 ms | 78 ms | 81 ms | 99 ms | memenuhi SLO |
| 8 | nyala | 41 | 272 ms | 321 ms | 356 ms | 424 ms | read p95 lewat batas |
| 8 | nyala (ulang) | 55 | 195 ms | 255 ms | 267 ms | 384 ms | memenuhi SLO (batas) |
| 8 | mati | 52 | 190 ms | 234 ms | 252 ms | 320 ms | memenuhi SLO (batas) |
| 8 | mati (ulang) | 52 | 203 ms | 251 ms | 264 ms | 318 ms | read p95 lewat batas |
| 12 | nyala | 35 | 506 ms | 587 ms | 715 ms | 873 ms | jauh di atas batas |
| 12 | nyala (ulang) | 63 | 242 ms | 285 ms | 343 ms | 399 ms | read p95 lewat batas |
| 12 | mati | 45 | 319 ms | 374 ms | 464 ms | 542 ms | keduanya lewat batas |
| 12 | mati (ulang) | 51 | 308 ms | 406 ms | 433 ms | 642 ms | keduanya lewat batas |
| 16 | nyala | 64 | 306 ms | 368 ms | 445 ms | 553 ms | keduanya lewat batas |
| 16 | mati | 51 | 400 ms | 467 ms | 570 ms | 668 ms | keduanya lewat batas |
| 32 | nyala | 67 | 521 ms | 590 ms | 829 ms | 947 ms | jauh di atas batas |
| 32 | mati | 61 | 610 ms | 695 ms | 933 ms | 1.058 ms | jauh di atas batas |

Seluruh 15 run nol pelanggaran kebenaran, nol 5xx aplikasi, nol timeout klien.

**Concurrency tertinggi yang lulus hari ini: 8, dan itu berlaku untuk kedua keadaan.** Pada 12
tidak ada satu pun run yang lulus, dengan maupun tanpa cache. Turunnya ambang dari 12 ke 8
**bukan** akibat cache — ia terjadi sama besar pada arm yang cache-nya mati.

**Pada 8 VU kedua arm mengangkangi ambang 200 ms.** Cache nyala: 272 ms lalu 195 ms. Cache mati:
190 ms lalu 203 ms. Lulus atau gagal di tingkat itu hari ini ditentukan run mana yang dilihat,
bukan oleh cache. Sebaran dalam satu arm mencapai 2,1× (cache nyala pada 12 VU: 506 ms lalu
242 ms), jadi selisih antar-arm di tingkat gate tidak dapat dipisahkan dari derau.

#### Kondisi pengukuran, dan kenapa angka absolutnya tidak sebanding dengan F7-03

| Hal | F7-03 (06.38–06.43) | F7-10 (09.12–09.37) |
| --- | --- | --- |
| Host | Windows 11, 12 vCPU / 16 GB | sama |
| Memori Docker Desktop (WSL2) | 7,6 GB | 7,61 GB |
| RAM host yang masih bebas | tidak dicatat | **0,58 GB dari 15,71 GB** |
| Beban host dari aplikasi lain | tidak dicatat | **~51% CPU**; VS Code ×3, Discord, Chrome, Docker Desktop |
| Stack lain yang menyala | tidak dicatat | stack `erp-dev` (6 container) **idle**, ~0,2% CPU, ~210 MB |
| Ukuran database uji beban | 169 MB atau kurang | 169 MB di awal, **203 MB** di akhir |
| `number_sequence_issues` | < 63.861 | 64.299 di awal, **81.023** di akhir |
| PostgreSQL saat dibebani | 11–65% CPU | **77–150% CPU** |
| pgbouncer saat dibebani | 17–71% CPU | 71–80% CPU |

Dua sebab yang cukup untuk menjelaskan seluruh selisih absolutnya, dan keduanya bukan cache:

1. **Host jauh lebih sibuk.** Run 1 VU tanpa cache hari ini — arm yang keadaannya *identik*
   dengan F7-03 — memberi read p50/p95 = 62/92 ms, sedangkan F7-03 mencatat 47/55 ms pada
   perintah yang sama. Mesinnya sendiri yang 33% lebih lambat.
2. **Skenarionya menumbuhkan datanya sendiri.** Tiap run menulis master baru, jadi run berikutnya
   melist dan menghitung tabel yang lebih besar. Sepanjang sesi ini `aset_m_group_aset` naik dari
   2.770 ke 5.529 baris dan `aset_m_jenis_aset` dari 1.533 ke 2.997 — dua kali lipat sepanjang sesi ini.
   Run latensi F7-03 juga mendahului `gate-sat-3`, jadi ia bekerja pada dataset yang lebih kecil
   lagi.

Karena itu tabel F7-03 **tidak diganti**. Ia tetap angka gate yang tercatat; tabel di atas adalah
ukur ulang pada kondisi yang berbeda, dan nilainya ada pada perbandingan antar-arm di dalamnya,
bukan pada angka absolutnya.

#### Yang cache-nya benar-benar hemat: ongkos bootstrap

Gate di atas tidak dapat memisahkan selisih sekecil beberapa milidetik. `/up` dapat: ia tidak
memakai auth, tidak menyentuh database, dan karena itu tidak menumbuhkan dataset. Diukur dari
dalam jaringan container, arm dibalik urutannya tiap putaran.

Berurutan, satu per satu, 200 request per putaran:

| Putaran | Cache nyala (min / p50) | Cache mati (min / p50) |
| ---: | ---: | ---: |
| 1 | 17,5 / 21,2 ms | 20,9 / 24,8 ms |
| 2 | 17,9 / 23,6 ms | 22,8 / 29,2 ms |
| 3 | 19,5 / 27,1 ms | 24,2 / 31,5 ms |
| 4 | 20,4 / 27,5 ms | 23,0 / 34,9 ms |

Pada concurrency 16, 25 detik per putaran:

| Putaran | Arm pertama | Cache nyala (min / p50 / rps) | Cache mati (min / p50 / rps) |
| ---: | --- | ---: | ---: |
| 1 | nyala | 43,3 / 90,2 / 159 | 48,9 / 93,1 / 160 |
| 2 | mati | 43,1 / 93,9 / 157 | 50,6 / 94,7 / 156 |
| 3 | nyala | 44,1 / 90,4 / 161 | 59,1 / 112,8 / 130 |
| 4 | mati | 48,2 / 89,0 / 167 | 51,3 / 108,2 / 139 |

Cache menang **8 dari 8 putaran** pada `min` dan pada p50, dan urutan arm dibalik tiap putaran
jadi pergeseran mesin tidak dapat menghasilkannya. Besarnya: **3–5 ms per request** saat
berurutan, **8 ms pada `min` dan 11 ms pada p50** saat concurrency 16, dengan throughput rata-rata
~10% lebih tinggi (161 vs 146 rps).

**Tetapi penghematan itu tidak muncul lagi begitu ada database di jalurnya.** Pada 1 VU skenario
terautentikasi, read p50 61,8 ms dengan cache dan 61,8 ms tanpa cache; ulangan arm tanpa cache
malah 51,8 ms. Satu request baca menghabiskan ~60 ms, sebagian besar di database, dan 4 ms tidak
terlihat di atas sebaran arm itu sendiri yang ~10 ms.

Itu memperkuat temuan F7-03, bukan membatalkannya: yang jenuh lebih dulu adalah **CPU PHP di dalam
badan request**, bukan I/O setelan. Cache setelan dan rute tetap benar untuk dipasang — ia
menghapus pekerjaan yang memang sia-sia, dan penghematannya terukur pada jalur yang hanya berisi
bootstrap — tetapi **ia tidak menggeser gate latensi**, dan tidak ada dasar untuk menjanjikan
concurrency yang lebih tinggi karenanya.

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

Satu sebab sempat dicurigai di lapis deployment, dan **sudah diperbaiki pada F7-10** — angka di
atas diukur sebelum perbaikan itu:

> **Dulu `php artisan config:cache` gagal pada Core**, karena `config/scramble.php` memuat objek
> `ApiKeySecurityScheme` yang tidak punya `__set_state()`. Sejak F7-10 skemanya disusun di dalam
> kelasnya sendiri dan berkas setelan hanya memuat nama kelas, jadi keduanya berhasil — dan
> entrypoint image membangunnya saat container naik, untuk ketiga peran.
>
> **Satu klaim yang dulu ditulis di sini salah, dan pantas disebut:** `route:cache` tidak pernah
> gagal karena closure. Diuji pada F7-10, ia memulangkan **383 rute, sama dengan tanpa cache**.
> Kalimat itu ditulis tanpa dijalankan.
>
> **Dan cache-nya tidak menggeser gate.** Diukur bergantian, cache menang 8 dari 8 putaran pada
> endpoint tanpa autentikasi dan tanpa database — 3–5 ms per permintaan berurutan, 8 ms pada min
> dan 11 ms pada p50 di konkurensi 16, throughput ~10% lebih tinggi. Pada skenario sungguhan
> penghematan itu **hilang di belakang kerja database**: pada 1 VU, read p50 61,8 ms dengan cache
> dan 61,8 ms tanpa. Itu menguatkan temuan di atas, bukan membatalkannya — yang jenuh lebih dulu
> memang CPU PHP di dalam badan permintaan, bukan I/O setelan.

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
- Tabel gate latensi F7-03 diukur saat `config:cache` dan `route:cache` mati, jadi ia memuat ongkos
  bootstrap penuh. F7-10 memasang keduanya dan mengukur ulang: ongkos bootstrap memang turun 3–11 ms
  per request, tetapi **gate-nya tidak bergeser**. Angka ukur ulangnya ada di bagian F7-10 di atas,
  beserta alasan kenapa angka absolutnya tidak sebanding dengan F7-03.

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
