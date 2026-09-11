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
cd apps/core/loadtest

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

# Skenario work order — siklus dokumen, transisi terlarang, idempotency, batas tenant.
docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=64 -e VUS=256 -e DURATION=90s `
  -e RUN_ID=gate-wo-sat-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/work-order.js

# Skenario perlombaan transisi — 32 VU dipusatkan pada 4 tenant.
docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=transition-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-wo-race-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/work-order.js

# Skenario penyusutan — proposal dan finalisasi yang diulang, saldo yang tidak boleh bergeser.
docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=64 -e VUS=256 -e DURATION=90s `
  -e RUN_ID=gate-dep-sat-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/depreciation.js

# Skenario perlombaan finalisasi — 32 VU memfinalkan periode yang sama.
docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=finalize-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-dep-race-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/depreciation.js

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
| Probe work order di dalam k6 | baca dan transisi lintas tenant, eskalasi hak, transisi terlarang, idempotency | `SELFTEST=1` pada `work-order.js` → lihat tabel rincian di bawah, exit 99 |
| `transition_double_wins` | dua transisi dari versi yang sama sama-sama menang | `SELFTEST=1` pada `work-order.js` → 29 dari 29 balapan tercatat sebagai dua pemenang, exit 99 |
| Probe penyusutan di dalam k6 | proposal dan finalisasi yang diulang, saldo buku, batas tenant, eskalasi hak | `SELFTEST=1` pada `depreciation.js` → lihat tabel rincian di bawah, exit 99 |
| `verify.sql` (module) — transisi status | baris status log di luar grafik `WorkOrderStatus` | satu baris `draft` → `ditutup` disuntik → pemeriksaan naik ke 1, exit 3; baris dihapus, exit kembali 0 |
| `verify.sql` (module) — saldo buku | akumulasi buku aset versus jumlah periode final miliknya | satu periode final bernilai 1 disuntik → pemeriksaan naik ke 1, exit 3; baris dihapus, exit kembali 0 |

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

Dua pemeriksaan `verify.sql` yang ditambahkan bersama kedua skenario di atas dibuktikan merah
dengan cara yang sama:

```powershell
docker compose exec -T db psql -U core_erp -d core_erp -c "insert into aset_tr_pemeliharaan_aset_status_log (id, tenant_id, pemeliharaan_aset_id, dari_status, ke_status, oleh_user_id, created_at) select 'ZZINJECTBADTRANSITION00001', tenant_id, id, 'draft', 'ditutup', 'inject', now() from aset_tr_pemeliharaan_aset limit 1;"
docker compose exec -T db psql -U core_erp -d core_erp -c "insert into aset_tr_penyusutan_aset (id, tenant_id, asset_book_id, legal_entity_id, period_starts_on, period_ends_on, amount, status, created_at, updated_at) select 'ZZINJECTEXTRAFINALPERIOD01', b.tenant_id, b.id, a.legal_entity_id, '2099-01-01', '2099-01-31', 1, 'final', now(), now() from aset_tr_buku_aset b join aset_tr_penerimaan_aset a on a.id = b.asset_id and a.tenant_id = b.tenant_id limit 1;"
docker compose exec -T db psql -U core_erp -d core_erp -f - < ..\..\..\modules\apperp\management-aset\loadtest\verify.sql   # exit 3, dua pemeriksaan bernilai 1
docker compose exec -T db psql -U core_erp -d core_erp -c "delete from aset_tr_pemeliharaan_aset_status_log where id like 'ZZINJECT%';"
docker compose exec -T db psql -U core_erp -d core_erp -c "delete from aset_tr_penyusutan_aset where id like 'ZZINJECT%';"
```

### Rincian pembuktian merah kedua skenario yang dipindahkan pada F7-03 sisa

`SELFTEST=1 TENANTS=8 VUS=16 DURATION=15s FIXTURE=st-wo` pada `work-order.js`, exit 99:

| Oracle | Permintaan yang dirusak | Pelanggaran tercatat |
| --- | --- | --- |
| `work_order_cross_tenant_read` | probe baca diarahkan ke dokumen milik sendiri | 17 dari 17 probe |
| `work_order_cross_tenant_transition` | transisi diarahkan ke dokumen draf milik sendiri | 17 dari 17 probe |
| `transisi_terlarang_diterima` | `draft` → `dijadwalkan` dikirim di tempat `draft` → `selesai` | 31 dari 31 probe |
| `work_order_idempotency_produced_two_records` | dua permintaan identik dikirim dengan kunci berbeda | 28 |
| `work_order_permission_escalation` | probe memakai sesi yang memang berhak penuh | 5 dari 5 probe |
| `transition_double_wins` | dua dokumen berbeda digerakkan, bukan satu dokumen dua kali | 29 dari 29 balapan |

Totalnya 98 pelanggaran pada 249 iterasi, dan `transition_conflicts` turun ke **0**: tidak ada satu
pun balapan yang menghasilkan pihak yang kalah, karena kedua dokumennya memang berbeda.

`SELFTEST=1 TENANTS=8 VUS=16 DURATION=25s FIXTURE=st-dep` pada `depreciation.js`, exit 99:

| Oracle | Permintaan yang dirusak | Pelanggaran tercatat |
| --- | --- | --- |
| `proposal_menghasilkan_periode_lain` | proposal diminta untuk periode di luar masa manfaat | 280 |
| `finalisasi_menerbitkan_posting_lain` | finalisasi diarahkan ke periode itu | 211 |
| `finalisasi_serentak_dua_posting` | dua periode berbeda difinalkan, bukan satu periode dua kali | 182 |
| `saldo_penyusutan_bergeser` | sebuah `reversal` dikirim sebelum saldo dibaca | 156 |
| `daftar_penyusutan_memuat_buku_tenant_lain` | yang dicari di daftar adalah buku milik sendiri | 106 |
| `finalisasi_lintas_tenant_diterima` | finalisasi diarahkan ke periode milik sendiri | 78 |
| `penyusutan_permission_escalation` | probe memakai sesi yang memang berhak penuh | 36 |

**Yang tidak dapat dibuktikan merah, dan disebut apa adanya.** Pemeriksaan prefix nomor di dalam
k6 (`wrong_sequence_prefix_on_work_order`, `wrong_sequence_prefix_in_list`), pemeriksaan
`work_order_written_to_other_tenant`, dan `status_tidak_berpindah` tidak punya bentuk permintaan
yang dapat merusaknya: nomor diterbitkan server, tenant diambil dari sesi, dan status yang
dipulangkan adalah status yang baru saja ditulis. Membuatnya merah menuntut mengubah
pembandingnya, dan itu membuktikan pembanding yang diubah, bukan pembanding yang dipakai.
Ketiganya karena itu **tidak** dihitung sebagai oracle yang terbukti; prefix nomor tetap
terjaga dari sisi database lewat dua pemeriksaan `verify.sql` yang memang sudah terbukti merah.

Satu lagi: `proposal_nilai_berubah` tidak ikut memerah pada run di atas karena pembanding id
memerah lebih dulu dan jalurnya berhenti di sana. Ia sebaris dengan pembanding id yang sudah
terbukti, tetapi belum pernah dilihat gagal sendiri.

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

### Gate kebenaran work order dan penyusutan — LULUS (F7-03 sisa)

Kedua skenario ini baru dapat diukur setelah dipindahkan ke penyiapan tenant lewat pendaftaran
usaha dan sesi Core. Diukur **10 September 2026**, mesin dan stack yang sama dengan tabel di atas,
pada `FIXTURE=g1`.

`RUN_ID=gate-wo-sat-2`: 256 VU, 64 tenant, 90 detik, empat instance.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` | 0 |
| `transition_double_wins` | 0 |
| `server_errors` (5xx aplikasi) | 0 |
| Siklus penuh draf → dijadwalkan → dikerjakan → selesai | 555 |
| Balapan transisi | 196 balapan, semuanya tepat satu pemenang |
| Probe transisi terlarang | 201 percobaan, semua 422 |
| Probe baca dan transisi lintas tenant | 134 percobaan, semua ditolak |
| Probe eskalasi hak | 58 percobaan, semua 403 |
| Balapan idempotency yang terbukti replay | 221 |
| Iterasi / request | 1.892 / 6.451 |
| Timeout klien (batas 60 detik) | 104 — kapasitas, bukan cacat |

`RUN_ID=gate-wo-race-1`: 32 VU dipusatkan pada 4 tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `transition_double_wins` | 0 dari 1.489 balapan |
| Balapan dengan tepat satu pemenang | 1.489 dari 1.489 |
| `correctness_violations` | 0 |
| `server_errors` | 0 |
| Permintaan gagal | 0 |

Yang kalah dalam balapan transisi punya dua bentuk, dan keduanya penolakan yang benar: **409**
bila versinya sudah basah saat kunci di dalam transaksi diperiksa, atau **422** bila dokumennya
sudah berpindah sebelum pembacaan sekilas di luar transaksi sempat membacanya. Yang digate adalah
"tepat satu menang", bukan bentuk penolakan yang kebetulan muncul.

`RUN_ID=gate-dep-sat-1`: 256 VU, 64 tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` | 0 |
| `server_errors` | 0 |
| Proposal yang diulang | 1.195, semuanya memulangkan periode dan nilai yang sama |
| Finalisasi yang diulang | 1.001, semuanya memulangkan posting yang sama |
| Balapan finalisasi | 676, semuanya satu posting |
| Pembacaan saldo | 593, semuanya akumulasi 1.000 dan nilai buku 0 |
| Probe finalisasi lintas tenant | 308 percobaan, semua 404 |
| Probe eskalasi hak | 119 percobaan, semua 403 |
| Iterasi / request | 4.372 / 6.446 |
| Timeout klien | 80 — kapasitas, bukan cacat |

`RUN_ID=gate-dep-race-1`: 32 VU memfinalkan periode yang sama, 4 tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| Balapan finalisasi dengan satu posting | 2.379 dari 2.379 |
| `correctness_violations` | 0 |
| `server_errors` | 0 |
| Permintaan gagal | 0 |

`verify.sql` module sesudah seluruh rangkaian ini: **28 pemeriksaan, semuanya 0** — termasuk dua
yang baru (`transisi status work order di luar grafik`, `akumulasi buku aset tidak sama dengan
jumlah periode final`) dan `posting export penyusutan ganda`, yang setelah 3.055 balapan
finalisasi tetap nol. `verify.sql` Core: 12 pemeriksaan, semuanya 0, 95.576 nomor terbit tanpa
satu pun ganda.

### Gate latensi work order dan penyusutan (F7-03 sisa)

`PROFILE=latency`, `TENANTS=32`, `DURATION=60s`, `FIXTURE=g1`, ambang sama dengan skenario lain
(read p95 < 200 ms, p99 < 500 ms; write p95 < 400 ms, p99 < 900 ms).

| Skenario | Concurrency | rps | read p95 | read p99 | write p95 | write p99 | Status |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| work-order | 1 | 17 | 104 ms | 181 ms | 128 ms | 190 ms | memenuhi SLO |
| work-order | 8 | 54 | 181 ms | 218 ms | 251 ms | 287 ms | memenuhi SLO (batas) |
| work-order | 12 | 58 | 238 ms | 272 ms | 346 ms | 416 ms | **read p95 lewat batas 200 ms** |
| penyusutan | 1 | 28 | 62 ms | 79 ms | 68 ms | 88 ms | memenuhi SLO |
| penyusutan | 8 | 65 | 170 ms | 203 ms | 182 ms | 221 ms | memenuhi SLO |
| penyusutan | 12 | 64 | 271 ms | 321 ms | 302 ms | 362 ms | **read p95 lewat batas 200 ms** |

Seluruh enam run nol pelanggaran kebenaran, nol 5xx aplikasi, nol timeout klien. Kedua run pada
concurrency 12 berhenti dengan exit 99 karena `op_read` melewati ambangnya; gate-nya **tidak**
dilonggarkan. Batas yang lulus hari ini karena itu 8 untuk kedua permukaan — angka yang sama
dengan yang tercatat pada ukur ulang F7-10 di atas, dan batas itu memang milik mesin ini, bukan
milik kedua skenario.

### Bukti scale-out

Log nginx sesudah seluruh rangkaian run:

```text
9377 172.21.0.4:80
9376 172.21.0.5:80
9330 172.21.0.6:80
9324 172.21.0.7:80
```

Keempat instance menerima beban yang sama rata; tidak ada satu pun yang menjadi pemilik state.

Diulang pada 40.000 request terakhir setelah rangkaian run work order dan penyusutan:

```text
10020 172.21.0.6:80
10007 172.21.0.4:80
 9994 172.21.0.5:80
 9979 172.21.0.7:80
```

Satu sesi dipakai empat instance sekaligus, dan tidak ada satu pun 5xx aplikasi dari keempatnya:
identitas, tenant, dan izin tidak menempel pada memori satu proses.

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
- `k6/work-order.js` dan `k6/depreciation.js` pada folder module sudah dipindahkan dan diukur;
  angkanya ada di bagian "Gate kebenaran work order dan penyusutan" di atas. Yang masih belum
  diukur pada permukaan itu: tutup bulan massal (`penyusutan/proposal-massal`), aset dengan
  beberapa buku penyusutan sekaligus, pembalikan di bawah beban, serta pengisian checklist
  work order — skenario work order menyimpan hasil pelaksanaan tetapi tidak menyalin template
  checklist maupun mengisi barisnya.
- Skenario work order menumbuhkan datanya sendiri dengan cepat: tiap iterasi siklus, balapan
  transisi, dan probe transisi terlarang membuat satu dokumen baru. Angka latensinya karena itu
  tidak sebanding antar-sesi; angka kebenarannya tetap sebanding.
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
| `k6/work-order.js` (module) | siklus work order, transisi terlarang, perlombaan transisi | dijalankan F7-03 sisa |
| `k6/depreciation.js` (module) | proposal dan finalisasi yang diulang, saldo buku, perlombaan finalisasi | dijalankan F7-03 sisa |
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
