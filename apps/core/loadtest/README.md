# Uji beban CoreERP

Satu stack untuk seluruh runtime. Sampai F7-03 ada dua: yang ini untuk Core, dan satu lagi di
dalam folder module Management Aset dengan image, database, dan **tiruan Core** sendiri. Bentuk
itu benar ketika module masih app berkontainer yang memanggil Core lewat HTTP. Ia tidak benar
lagi — dan yang paling penting, tiruannya sudah tidak mewakili apa pun: nomor sekarang
diterbitkan proses yang sama lewat kontrak `NumberSequenceIssuer`.

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

# Skenario perlombaan "Post penyusutan" — FIXTURE wajib baru: periode yang sudah di-post tidak
# dapat dibalapkan lagi.
docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=post-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-dep-post-race-1 -e FIXTURE=pr1 `
  grafana/k6:0.55.0 run /scripts/aset/depreciation.js

# Skenario perlombaan koreksi nilai perolehan — 32 VU mengoreksi aset yang sama pada detik yang sama.
docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=adjust-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-adj-race-1 -e FIXTURE=ar1 `
  grafana/k6:0.55.0 run /scripts/aset/receipt-posting.js

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
| `posting_group_mixed_rows` dan probe posting group | baris posting group campuran A/B, tulis ke group tenant lain, akun tenant lain diterima | `SELFTEST=1` pada `posting-group.js` → 3.701 dari 4.229 pembacaan balik dan 128 probe tercatat, exit 99 |
| `server_errors` pada balapan posting group | pembuatan kedua untuk group dan tanggal yang sama menabrak indeks unik | controller **tanpa** `lockForUpdate` disalin sementara ke keempat container → 66 error 500 dalam 30 detik, semuanya 23505 `aset_m_posting_group_berlaku_unique`, exit 99; container dibuat ulang dari image |
| `verify.sql` (module) — posting group dan kode diketik | akun tenant lain atau yang tidak ada di kolom akun; bentuk kode group aset dan buku penyusutan | satu baris ber-akun tenant lain disuntik dan satu kode group diubah menjadi `pg salah` → kedua pemeriksaan naik ke 1, exit 3; dipulihkan, exit kembali 0 |
| Probe penerimaan di dalam k6 | pratinjau jurnal dan penyelesaian penerimaan tenant lain | `SELFTEST=1` pada `receipt-posting.js` → 48 dari 48 probe pratinjau dan 48 dari 48 probe penyelesaian tercatat, exit 99 |
| `verify.sql` (module) — jurnal perolehan | penerimaan selesai tanpa tepat satu posting, posting tanpa penerimaan selesai, debit posting yang tidak sama dengan register ditambah PPN, jumlah aset yang tidak sama dengan unit barisnya | `posting_id` satu posting digeser → dua pemeriksaan pertama naik ke 1; debit dan kredit satu posting digeser satu sen dan satu baris mengaku satu unit lebih → dua pemeriksaan terakhir naik ke 2 dan 1, exit 3; dipulihkan, exit kembali 0. Debit yang digeser sendirian ditolak `finance_postings_balanced_check` — yang menahannya skema, bukan oracle |
| Probe penerimaan di dalam k6 (area 10) | pratinjau jurnal dan penyelesaian penerimaan tenant lain, dengan separuh draf arena berupa saldo awal | `SELFTEST=1` pada `receipt-posting.js` → 50 dari 50 probe pratinjau dan 50 dari 50 probe penyelesaian tercatat, exit 99 |
| `verify.sql` (module) — jurnal saldo awal | akumulasi yang dikreditkan jurnal saldo awal versus akumulasi awal buku yang di-post di register | akumulasi dan akumulasi awal satu buku yang di-post digeser satu sen bersamaan → pemeriksaan itu naik ke 1 sementara pemeriksaan saldo buku tetap 0, exit 3; dipulihkan, exit kembali 0 |
| `post_serentak_dua_posting` (area 11) | dua "Post penyusutan" berbarengan untuk periode yang sama sama-sama menerbitkan posting | `SELFTEST=1` pada profil `post-race` → 3 dari 9 balapan yang menerbitkan posting tercatat sebagai dua posting, exit 99. Sisanya kalah dari VU lain di tenant yang sama untuk salah satu periodenya: tiap periode hanya dapat di-post sekali, jadi pembuktiannya habis bersama periodenya |
| Probe penyusutan di dalam k6 (area 11) | pratinjau "Post penyusutan" atas entitas legal dan buku tenant lain, ditambah ketujuh probe penyusutan yang sudah ada | `SELFTEST=1` pada profil saturation → 71 dari 71 probe pratinjau post tercatat dan ketujuh pendeteksi lama ikut memerah, 967 pelanggaran seluruhnya, exit 99 |
| `verify.sql` (module) — jurnal penyusutan | periode yang ditandai di-post tanpa posting berjenis benar; total posting penyusutan versus periode yang ditandainya; pembalikan yang tidak mengikuti periode aslinya | satu periode final bernilai 0 bertanda posting yang tidak ada, total satu posting penyusutan digeser satu sen (debit dan kredit bersamaan), dan satu baris pembalik bernilai 0 tanpa jurnal balik atas periode yang sudah di-post disuntik → ketiga pemeriksaan naik tepat ke 1, pemeriksaan lain tetap 0, exit 3; dipulihkan, exit kembali 0 |

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

### Gate kebenaran posting group aset — LULUS (area 8 feed posting finance)

Diukur **23 September 2026** pada mesin yang sama, image `erp-core-app:a8-posting-group` yang
dibangun dari branch area 8, `FIXTURE=pg1` (128 tenant baru). Skenarionya
`modules/apperp/management-aset/loadtest/k6/posting-group.js`.

`RUN_ID=pg-race-1`: 32 VU dipusatkan pada 4 tenant dan 4 group, 90 detik. Setiap detik seluruh VU
satu group menulis tanggal berlaku baru yang sama, dan sebagian VU mengarsipkan tanggal tetap yang
sedang ditulis VU lain.

| Pemeriksaan | Hasil |
| --- | --- |
| `server_errors` (termasuk pembuatan kedua yang menabrak indeks unik) | 0 |
| `posting_group_mixed_rows` | 0 dari 38.625 pembacaan balik |
| `correctness_violations` (probe group dan akun tenant lain) | 0 |
| PUT pertama ke tanggal baru yang didahului VU lain | 653, sementara yang menang 319 — balapannya benar-benar terjadi |
| Arsip yang berebut dengan penulis | 27 |
| Permintaan gagal | 0 |

`RUN_ID=pg-sat-2`: 1000 VU, 128 tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` | 0 |
| `posting_group_mixed_rows` | 0 dari 1.268 pembacaan balik |
| `server_errors` | 0 |
| Iterasi / request | 615 / 6.318 |
| Timeout klien (batas 60 detik) | 1.746 — kapasitas, bukan cacat |

`verify.sql` module: semua pemeriksaan 0, termasuk tiga pemeriksaan posting group dan
pemeriksaan kode diketik. Dua di antaranya ditahan skema, bukan kode, dan dicatat sebagai itu:
suntikan baris ganda ditolak `aset_m_posting_group_berlaku_unique`, dan suntikan group tenant
lain ditolak kunci asing `aset_m_posting_group_tenant_id_group_aset_id_foreign`.

**Batas kejujuran run ini.** Throughput sekitar 35 request per detik, sepertiga dari 10 September:
host sedang menjalankan suite test modul dan language server PHP milik editor. Angka latensinya
karena itu bukan pengukuran gate dan tidak sebanding dengan tabel lain di halaman ini. Sebagai
pembanding biaya satu permintaan tanpa antrean, `RUN_ID=pg-base-1` (1 VU, 20 detik) mengukur baca
p50 114 ms / p95 181 ms dan tulis p50 92 ms / p95 151 ms. Timeout pada saturasi adalah antrean
1000 VU di depan 128 worker, bukan endpoint yang lambat.

`verify.sql` Core pada run yang sama gagal di satu pemeriksaan, `boundary tenant tidak lengkap`
(128 dari 128): pemeriksaan itu masih menuntut baris `tenant_deployments`, yang tidak lagi ditulis
pendaftaran sejak registry environment. Itu oracle yang tertinggal, bukan tenant setengah jadi;
seluruh pemeriksaan Core lainnya 0.

### Gate kebenaran penerimaan dan jurnal perolehan — LULUS (area 9 feed posting finance)

Diukur **24 September 2026** pada mesin yang sama, image `erp-core-app:a9-receipt` yang dibangun
dari branch area 9, `FIXTURE=rp1`. Skenarionya
`modules/apperp/management-aset/loadtest/k6/receipt-posting.js`. Stack-nya project compose
tersendiri (`-p core-loadtest-a9`) dengan volume baru: penerimaan yang diselesaikan sebelum
migration area 9 memang tidak punya posting, dan oracle perolehannya memerah pada volume lama.

Tenant uji beban tidak punya hierarki manajemen, jadi BU tidak dapat diturunkan dan setiap jurnal
perolehan berstatus `held` (`BUSINESS_UNIT_UNRESOLVED`, `ACCOUNT_NOT_MAPPED`). Yang digate tidak
bergantung pada status itu: satu posting per penerimaan selesai, debit yang sama dengan register
ditambah PPN, jumlah aset per unit, dan batas tenant.

`RUN_ID=rcp-race-4`: 32 VU dipusatkan pada 4 tenant, 90 detik. Setiap detik seluruh VU satu tenant
menyelesaikan draf yang sama.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` (probe tenant lain, penerimaan selesai tanpa posting, jumlah aset) | 0 |
| `server_errors` | 0 |
| Penyelesaian yang menang / yang kalah (409 atau 422) | 234 / 1.001 — balapannya benar-benar terjadi |
| `number_sequence_failures` | 18 — lihat temuan di bawah |
| Permintaan gagal | 0 |

`RUN_ID=rcp-sat-1`: 1000 VU, 128 tenant, 90 detik. Setiap iterasi membuat draf, menyelesaikannya,
memeriksa posting dan asetnya, lalu mem-probe dokumen tenant lain.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` | 0 |
| `server_errors` | 0 |
| Penerimaan selesai menurut k6 / menurut database | 657 / 716 — 59 selesai di server sesudah kliennya menyerah, dan masing-masing tetap membawa tepat satu posting |
| `number_sequence_failures` | 0 |
| Timeout klien (batas 60 detik) | 1.667 — kapasitas, bukan cacat; sebanding dengan 1.746 pada run area 8 |

`verify.sql` module pada akhir kedua run: semua pemeriksaan 0. 1.014 posting `asset.acquisition`
untuk 1.014 penerimaan selesai di 115 tenant, 2.028 aset (tepat dua per penerimaan), dan tidak satu
pun nomor penerimaan atau aset terbit dua kali. `verify.sql` Core: 52.171 nomor terbit tanpa satu
pun ganda; satu-satunya yang merah tetap `boundary tenant tidak lengkap` (128 dari 128), oracle yang
masih menuntut `tenant_deployments` (lihat area 8).

Empat pemeriksaan sequence Core sempat merah 4 dari 4 pada run race. Penyebabnya oracle, bukan
tenant: skenario ini yang pertama membuat vendor, dan vendor bernomor dari reference milik Core
sendiri, `core.vendor` — tidak dibeli, dengan bawaan yang ditetapkan Core (`VND-`, 1-999999, scope
entitas legal). Keempat pemeriksaan kini mengecualikan reference ber-`app_id = 'core'`.

**Temuan di luar area 9: deadlock penerbitan nomor.** Membuat 150 draf penerimaan serentak dalam
satu tenant (batch k6, 16 per host) memunculkan `SQLSTATE 40P01` pada `number_sequence_allocations`,
yang modul jawab 422 `number_sequence_failed` dengan pesan SQL mentah. Balapan penyelesaian memicunya
juga: nomor aset diterbitkan sebelum transaksi dokumen, dengan kunci idempoten yang sama untuk setiap
VU yang berlomba. Kebenarannya tidak tersentuh — dokumen tetap draf, dan yang berikutnya menang —
tetapi pengguna menerima galat. Setup skenario ini karena itu membuat draf arena berurutan, dan
kegagalannya dihitung terpisah lewat `number_sequence_failures`. Perbaikannya pekerjaan tersendiri
di Number Sequence Core.

### Gate kebenaran saldo awal aset — LULUS (area 10 feed posting finance)

Diukur **24 September 2026** pada mesin yang sama, image `erp-core-app:a10-opening` yang dibangun
dari branch area 10, `FIXTURE=ob1`, project compose tersendiri (`-p core-loadtest-a10`) dengan volume
baru. Skenarionya tetap `receipt-posting.js`, kini dengan saldo awal: separuh draf arena balapan dan
sepertiga penerimaan beban jenuh bercara `saldo_awal`, dan setiap iterasi ketujuh beban jenuh
mengimpor dua baris saldo awal dari CSV. Setup menyetel cutover `2026-01-01` pada entitas legal
setiap tenant.

Seperti area 9, tenant uji beban tidak punya hierarki manajemen maupun pemetaan akun, jadi setiap
jurnal berstatus `held`. Yang digate tidak bergantung pada status itu: tepat satu posting berjenis
benar per penerimaan selesai (`AST-OPB-` untuk saldo awal), debit yang sama dengan register, akumulasi
yang dikreditkan jurnal saldo awal sama dengan akumulasi awal buku yang di-post, jumlah aset per unit,
dan batas tenant.

`RUN_ID=ob-race-1`: 32 VU dipusatkan pada 4 tenant, 90 detik; draf arena bergantian pembelian dan
saldo awal.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` (probe tenant lain, jenis posting, penerimaan selesai tanpa posting, jumlah aset) | 0 |
| `server_errors` | 0 |
| Penyelesaian yang menang / yang kalah (409 atau 422) | 217 / 855, 125 kemenangan di antaranya saldo awal |
| `number_sequence_failures` | 5 — deadlock penerbitan nomor Core yang sama dengan area 9 |
| Permintaan gagal | 0 |

`RUN_ID=ob-sat-1`: 1000 VU, 128 tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` (termasuk impor yang tidak melahirkan tepat dua draf) | 0 |
| `server_errors` | 0 |
| Penerimaan selesai menurut k6 | 714, 212 di antaranya saldo awal |
| Impor saldo awal diterapkan menurut k6 / menurut database | 107 / 110 — tiga selesai di server sesudah kliennya menyerah, masing-masing tetap dua draf |
| `number_sequence_failures` | 0 |
| Timeout klien (batas 60 detik) | 1.598 — kapasitas, bukan cacat; sebanding dengan 1.667 pada area 9 |

`verify.sql` module pada akhir ketiga run (race, SELFTEST, saturation): 37 pemeriksaan, semuanya 0.
1.020 penerimaan selesai — 644 pembelian dan 376 saldo awal — membawa 644 posting `asset.acquisition`
dan 376 posting `asset.opening_balance`, seluruh posting saldo awal bertanggal cutover; 2.040 aset,
tepat dua per penerimaan. `verify.sql` Core: 51.570 nomor terbit tanpa satu pun ganda; satu-satunya
yang merah tetap `boundary tenant tidak lengkap` (128 dari 128), oracle yang masih menuntut
`tenant_deployments` (lihat area 8).

2.050 nomor aset terbit untuk 2.040 aset. Kesepuluh sisanya tercatat atas kunci draf yang belum
selesai, dan asalnya dilacak lewat log API: delapan dari probe SELFTEST, yang sengaja menyelesaikan
draf milik sendiri dengan `version` 999 — nomor aset terbit sebelum syarat versi diperiksa di dalam
transaksi — dan dua dari penerbitan yang gagal di unit kedua (`number_sequence_failed`). Kunci
penerbitannya deterministik per draf dan unit, jadi penyelesaian yang sah kelak memakai nomor yang
sama, bukan nomor baru. Perilaku ini sudah ada sejak area 9.

### Gate kebenaran posting penyusutan — LULUS (area 11 feed posting finance)

Diukur **24 September 2026** pada mesin yang sama, image `erp-core-app:a11-posting` yang dibangun
dari branch area 11, project compose tersendiri (`-p core-loadtest-a11`) dengan volume baru.
Skenarionya `depreciation.js`, kini dengan "Post penyusutan": beban jenuh mem-post periode final dan
mem-probe pratinjau post atas entitas legal dan buku tenant lain, dan profil baru `post-race`
membalapkan proses post untuk periode yang sama. Setup menyalakan feed posting finance entitas legal
setiap tenant dengan cutover `2026-01-01`.

Seperti area 9 dan 10, tenant uji beban tidak punya hierarki manajemen maupun pemetaan akun, jadi
setiap jurnal penyusutan berstatus `held` (`ACCOUNT_NOT_MAPPED`, `BUSINESS_UNIT_UNRESOLVED`). Yang
digate tidak bergantung pada status itu: tepat satu posting per periode, total posting yang sama
dengan periode yang ditandainya, pembalikan yang mengikuti periode aslinya, dan batas tenant.

`RUN_ID=dp-race-3`: 32 VU dipusatkan pada 4 tenant, 90 detik. Setiap iterasi mengirim dua "Post
penyusutan" berbarengan untuk periode yang sama, sementara VU lain di tenant itu melakukan hal serupa.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` (dua posting dalam satu balapan, isi jawaban post, post yang ditolak) | 0 |
| `server_errors` | 0 |
| Post yang menerbitkan / yang pulang kosong | 12 / 5.888 — tepat satu posting untuk tiap periode dari 4 tenant × 3 periode |
| Iterasi / request | 2.950 / 6.005 |
| Permintaan gagal | 0 |

`RUN_ID=dp-sat-2`: 1000 VU, 128 tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` | 0 |
| `server_errors` | 0 |
| Post yang menerbitkan menurut k6 / menurut database | 222 / 279 — 57 selesai di server sesudah kliennya menyerah, masing-masing tetap satu posting untuk satu periode |
| Post yang pulang kosong | 230 |
| Probe pratinjau post lintas tenant | 262, tidak satu pun melihat periode tenant lain |
| Proposal dan finalisasi yang diulang / balapan finalisasi | 949 dan 677 / 364, semuanya jawaban yang sama |
| Pembacaan saldo | 479, semuanya akumulasi 1.000 dan nilai buku 0 |
| Probe finalisasi lintas tenant / eskalasi hak | 243 / 157, semuanya ditolak |
| Iterasi / request | 5.209 / 9.099 |
| Timeout klien (batas 60 detik) | 1.541 — kapasitas, bukan cacat; sebanding dengan 1.598 pada area 10 |

`verify.sql` module sesudah seluruh rangkaian — run di atas, run pertama di bawah, dan ketiga
SELFTEST-nya: 40 pemeriksaan, semuanya 0. 647 periode bertanda di-post di 275 tenant membawa 644 posting
`asset.depreciation` dan 3 posting `asset.depreciation_reversal` — tiap posting tepat satu periode,
karena fixture-nya satu aset per tenant. Ketiga jurnal balik itu lahir dari pembalikan SELFTEST atas
periode yang sudah di-post, bertanggal periode asalnya (K-29); lima pembalikan lain mendahului post,
tidak menerbitkan apa pun, dan periode aslinya tidak pernah ikut proses post sesudahnya. `verify.sql`
Core: 107.120 nomor terbit tanpa satu pun ganda; satu-satunya yang merah tetap `boundary tenant tidak
lengkap` (292 dari 292), oracle yang masih menuntut `tenant_deployments` (lihat area 8).

Run pertama (`dp-race-2`, `dp-sat-1`) berjalan sebelum setup menyalakan feed. Hasilnya sama bersih —
0 pelanggaran, 0 error 5xx, satu posting per periode — tetapi setiap jurnal tercatat `manual`
(`feed_disabled`), sehingga penerbit tidak pernah menilai pemetaan akun maupun business unit baris
jurnalnya. Yang dihitung sebagai gate karena itu run dengan feed menyala di atas.

Dua pendeteksi finalisasi berganti nama karena finalisasi tidak lagi memulangkan posting ekspor:
`finalisasi_menerbitkan_posting_lain` menjadi `finalisasi_memulangkan_periode_lain`, dan
`finalisasi_serentak_dua_posting` menjadi `finalisasi_serentak_berbeda`. **Yang belum dibuktikan
merah:** `post_isi_salah` (jumlah aset atau total jawaban post yang salah) dan `post_ditolak` (post
sah yang dijawab 4xx). Keduanya tidak punya bentuk permintaan yang dapat merusaknya tanpa mengubah
pembandingnya, sama seperti pemeriksaan prefix pada F7-03; totalnya tetap dijaga dari sisi database
oleh pemeriksaan `verify.sql` yang sudah terbukti merah.

### Gate kebenaran koreksi nilai perolehan — LULUS (area 12 feed posting finance)

Diukur **25 September 2026** pada mesin yang sama, image `erp-core-app:a12-koreksi` yang dibangun
dari branch area 12, project compose tersendiri (`-p core-loadtest-a12`) dengan volume baru.
Skenarionya `receipt-posting.js`, kini dengan koreksi nilai perolehan: profil baru `adjust-race`
membuat seluruh VU satu arena mengoreksi aset yang sama pada detik yang sama, dan profil saturation
mengoreksi sebagian aset yang baru lahir. Separuh aset arena berasal dari saldo awal, jadi kedua akun
lawan — hutang dan penyeimbang saldo awal — ikut dibalapkan.

Koreksi serentak tidak saling menolak; semuanya sah dan dijalankan berurutan di bawah kunci baris
aset. Karena itu balapan ini tidak punya pihak yang kalah untuk dihitung. Cacat yang dicari justru
tidak kelihatan dari jawaban API: koreksi yang menghitung selisih dari nilai lama tetap dijawab 200.
Yang menangkapnya tiga pemeriksaan `verify.sql` baru, yaitu:

- rantai "sebelum" tiap koreksi sama dengan "sesudah" pendahulunya, berpangkal pada nilai di jurnal
  perolehan asal;
- nilai asal ditambah seluruh selisih sama dengan register;
- nomor urut koreksi tanpa lubang.

Pemeriksaan debit jurnal perolehan kini membandingkan dengan nilai asal (register dikurangi selisih
koreksinya), bukan register hari ini.

`RUN_ID=adj-race-1`: 32 VU dipusatkan pada 4 tenant, 12 aset per tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` (nilai yang tidak tersimpan, posting koreksi yang salah nomor atau status) | 0 |
| `server_errors` / koreksi sah yang ditolak | 0 / 0 |
| Koreksi yang menerbitkan jurnal | 2.355, atas 48 aset |
| Iterasi / request | 2.355 / 3.455 |
| Permintaan gagal | 0 |
| Write p50 / p95 | 878 ms / 2.436 ms — 8 VU antre di kunci baris aset yang sama, sesuai rancangannya |

`RUN_ID=adj-sat-1`: 1000 VU, 128 tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` | 0 |
| `server_errors` / koreksi sah yang ditolak | 0 / 0 |
| Penerimaan selesai / saldo awal / impor CSV | 625 / 139 / 105 |
| Koreksi yang menerbitkan jurnal menurut k6 | 143 |
| Iterasi / request | 972 / 8.108 |
| Timeout klien (batas 60 detik) | 1.694 — kapasitas, bukan cacat; sebanding dengan 1.598 pada area 10 dan 1.541 pada area 11 |

`SELFTEST=1` pada `adjust-race` (`adj-race-st-1`, 16 VU, 20 detik) mengarahkan probe lintas tenant ke
aset arena sendiri: 111 dari 111 probe pratinjau koreksi dan 111 dari 111 probe koreksi tercatat
sebagai pelanggaran, exit 99. Ketiga pemeriksaan `verify.sql` baru dibuktikan merah pada database
yang sama, dengan tiga aset berbeda:

| Kerusakan yang disuntik | Pemeriksaan yang memerah |
| --- | --- |
| "Sebelum" koreksi kedua digeser satu sen | rantai koreksi terputus |
| Register satu aset berubah satu sen tanpa jurnal | koreksi tidak sampai ke register; debit posting perolehan |
| Nomor koreksi terakhir melompat lima | nomor urut bolong; rantai koreksi terputus |

Setelah dipulihkan, gate kembali lulus.

`verify.sql` module sesudah seluruh rangkaian (race, SELFTEST, saturation): semua pemeriksaan 0.
3.031 posting `asset.acquisition_adjustment` atas 239 aset; tiap rantai tersambung dari jurnal
perolehannya sampai register, termasuk koreksi yang selesai di server sesudah kliennya menyerah.
Seperti area 9–11, tenant uji beban tidak punya pemetaan akun, jadi setiap jurnal koreksi berstatus
`held`. `verify.sql` Core: 53.048 nomor terbit tanpa satu pun ganda; satu-satunya yang merah tetap
`boundary tenant tidak lengkap` (136 dari 136), oracle yang masih menuntut `tenant_deployments`
(lihat area 8).

### Gate kebenaran log perubahan — LULUS (area 2 analisa gap BC)

Diukur **29 September 2026** pada mesin yang sama, image `erp-core-app:log-perubahan` dari branch
`feat/log-perubahan`, volume baru. Trigger `log_change` terpasang di 129 tabel. Bawaan modul aset mencatat
pembuatan dan perubahan aset, dan tabel peran serta hak akses selalu dicatat, termasuk empat tabel akses
yang tidak punya `tenant_id`.

Skenarionya `receipt-posting.js`, karena setiap penerimaan yang selesai melahirkan aset lewat trigger itu.
`master-data.js` dan `work-order.js` belum dapat dijalankan: keduanya masih membuat aset lewat `POST /aset`
yang dipensiunkan 18 September dan membuat group aset tanpa kode yang kini wajib diketik. Penyesuaiannya
tugas terpisah. Yang sudah diperbaiki di sini adalah `sempitkanTenant` di `lib.js`, yang dipakai semua
skenario: role Owner kini diatur otomatis dan tidak dapat disunting, jadi tenant sempit dibuat lewat role
satu duty, undangan, dan anggota baru yang menukarnya.

`RUN_ID=log-rcp-sat-1`: 1000 VU, 128 tenant, 90 detik.

| Pemeriksaan | Hasil |
| --- | --- |
| `correctness_violations` / `server_errors` | 0 / 0 |
| Penerimaan selesai / saldo awal / impor CSV / koreksi nilai | 718 / 214 / 113 / 211 |
| Iterasi / request | 983 / 8.495 |
| Timeout klien (batas 60 detik) | 1.780 — kapasitas, bukan cacat; sebanding dengan 1.694 pada area 12 |
| `verify.sql` Core | semua 0, 21.484 entri log |
| `verify.sql` module | semua 0 |

Oracle baru, masing-masing dibuktikan merah dengan kerusakan yang disuntik di dalam transaksi lalu
di-rollback:

| Pemeriksaan | Kerusakan yang disuntik | Hasil |
| --- | --- | --- |
| Core: entri log berpelaku bukan anggota tenantnya | entri bertenant B dengan pelaku anggota tenant A | 1 |
| Core: entri log peran atau penugasan peran di tenant lain | entri peran dan entri penugasan dengan tenant lain | 2 |
| Module: riwayat perubahan aset tercatat di tenant lain | entri aset dengan tenant lain | 1 |
| Module: aset tanpa entri log pembuatan | entri pembuatan satu aset dihapus | 1 |

Pemeriksaan `boundary tenant tidak lengkap` yang merah sejak area 8 ikut diperbaiki: ia masih menuntut
`tenant_deployments`, padahal sejak registry environment pendaftaran menulis `environments`. Kini ia
menuntut tepat satu environment produksi yang belum diarsipkan. Pemeriksaan baru itu dibuktikan merah
dengan mengarsipkan satu environment produksi di dalam transaksi (0 menjadi 1).

**Biaya log terhadap latensi.** Profil saturation `receipt-posting.js` pada 16 VU dan 16 tenant, 60
detik, dijalankan bergantian dengan seluruh trigger `log_change` menyala dan dimatikan (`ALTER TABLE ...
DISABLE TRIGGER`):

| Run | Log | rps | write p50 / p95 / p99 | read p50 / p95 | Penerimaan |
| --- | --- | ---: | --- | --- | ---: |
| `lat-on-1` | menyala | 42,8 | 334 / 704 / 1.077 ms | 162 / 313 ms | 566 |
| `lat-off-2` | mati | 51,1 | 323 / 749 / 1.946 ms | 162 / 266 ms | 567 |
| `lat-on-3` | menyala | 52,5 | 312 / 771 / 1.743 ms | 153 / 262 ms | 584 |
| `lat-off-4` | mati | 35,1 | 446 / 1.030 / 2.111 ms | 211 / 392 ms | 434 |

Selisih antara menyala dan mati lebih kecil daripada selisih dua run dengan keadaan yang sama (write p50
323 dan 446 ms pada dua run mati). Biaya log tidak terukur pada concurrency ini di mesin ini; itu bukan
berarti nol. Ini perbandingan, bukan gate latensi: pada 16 VU kedua keadaan sudah melewati batas write
p95 400 ms.

Run dengan trigger mati melahirkan 2.002 aset tanpa entri log, sehingga `verify.sql` module sesudah
pengukuran memerah pada `aset tanpa entri log pembuatan` (2.002). Seluruhnya milik tenant run latensi
dan lahir pada menit ketika trigger dimatikan; pada menit ketika trigger menyala, setiap aset punya
entrinya. Gate di atas diambil sebelum pengukuran.

Volume yang perlu diketahui retensi (area 4): setiap tenant baru menulis sekitar 128 entri saat
didaftarkan, karena duty role Owner dan penugasannya selalu dicatat. Dari 30.432 entri sesudah seluruh
rangkaian, 17.172 berasal dari `security_role_duties`.

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

## Versi baris (area 3 analisa gap BC, 30 September 2026)

Image dari cabang `feat/versi-baris`, stack terpisah (`COMPOSE_PROJECT_NAME=core-loadtest-versi`,
`CORE_IMAGE=erp-core-app:versi-baris`). Seluruh skenario aset membaca versi baris lalu mengirimnya kembali.

| Run | Hasil |
| --- | --- |
| Penjenuhan `receipt-posting.js`, 1000 VU, 128 tenant | 0 pelanggaran, 0 5xx aplikasi; 1.588 timeout klien — kapasitas, sebanding dengan 1.541–1.780 pada run sebelumnya |
| `adjust-race`, 32 VU, 4 tenant | 0 pelanggaran, checks 100%, 315 koreksi terbit; koreksi yang kalah versi dijawab 409 |
| `verify.sql` Core dan aset | semua pemeriksaan 0 |

**Biaya versi baris terhadap latensi.** Profil saturation 16 VU dan 16 tenant, 60 detik, bergantian dengan
trigger `bump_row_version` menyala dan dimatikan:

| Run | Versi | rps | write p50 / p95 / p99 | read p50 / p95 | Penerimaan |
| --- | --- | ---: | --- | --- | ---: |
| `ver-on-1` | menyala | 27,1 | 444 / 1.678 / 2.909 ms | 223 / 655 ms | 349 |
| `ver-off-2` | mati | 39,7 | 374 / 869 / 1.258 ms | 181 / 284 ms | 493 |
| `ver-on-3` | menyala | 39,7 | 387 / 862 / 1.323 ms | 189 / 303 ms | 477 |
| `ver-off-4` | mati | 41,6 | 360 / 796 / 1.146 ms | 173 / 264 ms | 504 |

`ver-on-1` adalah run pertama pada stack yang baru menyala (cache dingin). Di antara run lainnya selisih
menyala dan mati sekitar 3–5%, sebesar selisih dua run dengan keadaan yang sama. Seperti biaya log, biaya
versi tidak terukur di atas selisih antar-run pada mesin ini; itu bukan berarti nol.

## Engine analitik — area 10

Fixture analitik adalah pilihan terpisah karena isinya jauh lebih besar dari skenario lain: sedikitnya 100
tenant, masing-masing dua legal entity, delapan unit, tiga akun, dan jumlah aset acak 5.000–20.000. Database
`core-loadtest` tetap satu PostgreSQL bersama empat instance API di belakang nginx. Jalankan fixture ini hanya
di stack uji yang boleh dihapus.

```powershell
$project = 'core-loadtest-a10'
$env:CORE_IMAGE = 'coreerp-analytics-a10:local'
$env:LOADTEST_DB_PORT = '15553'
$env:LOADTEST_HTTP_PORT = '18093'
$network = "${project}_default"

# Image dan volume milik project ini sendiri; jangan membangun ulang tag runtime dev.
docker compose -p $project build api1
docker compose -p $project up -d --wait db pgbouncer
docker compose -p $project run --rm --no-deps -e LOADTEST_ANALYTICS=1 init
docker compose -p $project up -d api1 api2 api3 api4 lb
```

`ANALYTICS_FIXTURE_ID` mengikat tenant, akun, dan hasil SQL. Gunakan nilai yang sama saat menyiapkan fixture
dan saat menjalankan `verify.sql`. `ANALYTICS_DATASET` serta `ANALYTICS_POLICY_CODE` dapat diganti lewat
environment Compose. Fixture membuat akun Owner, akun dengan scope dua unit, dan akun tanpa grant. Sebagian
aset menaruh `financial_dimension_org_unit_id` pada unit yang berbeda dari `responsible_org_unit_id`, sehingga
oracle menangkap pemakaian kolom policy yang keliru.

Harness Core menyiapkan tenant, pengguna, scope, dan manifest. Baris aset serta master group/jenis dibuat oleh
skrip milik module `modules/apperp/management-aset/loadtest/analytics-fixture.php`; kode aset dan jenis tetap
diterbitkan Number Sequence module. Tiap tenant mendapat 12 group agar query kelompok dan tabel top-10 punya
data yang cukup.

Skenario explore memutar 40 bentuk query pada 300 VU. Dasbor menjalankan 1.000 VU; skenario campur
menjalankan 700 VU analitik dan 300 VU transaksi aset; feed publikasi dibaca 200 VU. Profil `latency`
mengukur satu tingkat concurrency selama 90 detik. Ulangi profil latensi dengan kenaikan VU sampai p95
atau p99 melewati SLO, lalu catat tingkat tertinggi yang masih lulus.

```powershell
$core = "$PWD\k6"
docker run --rm -i --network $network --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=128 -e VUS=300 -e DURATION=90s `
  -e RUN_ID=analytics-sat-a10 -e ANALYTICS_FIXTURE_ID=a10 -e LOADTEST_PASSWORD=Loadtest-Owner-2026! `
  grafana/k6:0.55.0 run --out json=/results/analytics-sat-a10.ndjson /scripts/analytics-explore.js

docker run --rm -i --network $network `
  -v "${core}:/scripts" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=latency -e TENANTS=128 -e LATENCY_VUS=8 -e DURATION=90s `
  -e RUN_ID=analytics-lat-8-a10 -e ANALYTICS_FIXTURE_ID=a10 -e LOADTEST_PASSWORD=Loadtest-Owner-2026! `
  grafana/k6:0.55.0 run --out json=/results/analytics-lat-8-a10.ndjson /scripts/analytics-explore.js

# Dasbor enam bagian; 70% siklus memakai cache, 30% memanggil refresh.
docker run --rm -i --network $network --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=128 -e VUS=1000 -e DURATION=90s `
  -e RUN_ID=analytics-dashboard-a10 -e ANALYTICS_FIXTURE_ID=a10 -e LOADTEST_PASSWORD=Loadtest-Owner-2026! `
  grafana/k6:0.55.0 run --out json=/results/analytics-dashboard-a10.ndjson /scripts/analytics-dashboard.js

# 700 VU query analitik berjalan bersamaan dengan 300 VU yang membaca dan mengoreksi aset.
docker run --rm -i --network $network --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e ANALYTICS_VUS=700 -e ASSET_VUS=300 -e DURATION=90s `
  -e RUN_ID=analytics-mixed-a10 -e ANALYTICS_FIXTURE_ID=a10 -e LOADTEST_PASSWORD=Loadtest-Owner-2026! `
  grafana/k6:0.55.0 run --out json=/results/analytics-mixed-a10.ndjson /scripts/analytics-mixed.js

# 200 VU membaca publikasi JSON berhalaman dan CSV dengan scope analytics.read.
docker run --rm -i --network $network --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=128 -e VUS=200 -e DURATION=90s `
  -e RUN_ID=analytics-external-a10 -e ANALYTICS_FIXTURE_ID=a10 -e LOADTEST_PASSWORD=Loadtest-Owner-2026! `
  grafana/k6:0.55.0 run --out json=/results/analytics-external-a10.ndjson /scripts/analytics-external.js

python .\k6\analytics-observations.py .\results\analytics-*.ndjson `
  --output .\results\analytics-observed.csv
docker compose -p $project exec -T db psql -U core_erp -d core_erp -v run_id=a10 -v analytics=true -f - < verify.sql
```

`analytics-explore.js` memutar 40 bentuk query yang sah; bentuk pertama menjadi sample oracle. `analytics-dashboard.js`
membuka dasbor enam bagian dan memuat ulang semua widget secara bersamaan; 70% siklus memakai cache. `analytics-mixed.js`
menulis `keterangan` melalui PATCH aset, jadi data yang dihitung query tetap sama sementara transaksi memakai database bersama.
Skenario explore, mixed, dan external merekam sample oracle. `analytics-observations.py` mengambil sample metric per baris dari output JSON k6;
`verify.sql` memuatnya ke tabel sementara lalu membandingkan langsung dengan `aset_tr_aset`. Ia memeriksa tenant, scope dua unit menurut
`responsible_org_unit_id`, pengguna tanpa grant, dan jumlah uang terpisah per mata uang. Profil latensi
menerapkan p95 < 200 ms dan p99 < 500 ms. Profil penjenuhan menjaga 0 error 5xx aplikasi; 502/504, timeout,
dan 429 `analytics.busy` dicatat terpisah sebagai tanda kapasitas.

Skenario external hanya membaca publikasi JSON/CSV area 15. Ia meminta halaman 10 baris untuk benar-benar
melewati cursor pada hasil fixture, memeriksa `X-Next-Cursor` pada CSV, dan mencoba membaca publikasi tenant
sebelah dengan token milik tenant sendiri; jawaban yang benar adalah 404. Nama analisis tersimpan dan klien
integrasi menyertakan `RUN_ID`, jadi setiap putaran harus memakai `RUN_ID` baru.

SLO halaman berisi 5.000 baris tetap berlaku, tetapi fixture agregat ini belum menghasilkan keluaran sebanyak
itu. Skenario ini membuktikan cursor dan format, bukan latensi payload maksimum; area 10 tetap terbuka sampai
ukuran tersebut diuji tanpa mengendurkan batas tenant atau oracle.

OData menyusul di area 16 dan embed di area 17; keduanya belum menjadi endpoint skenario area 10.

Untuk melihat oracle scope memerah, siapkan **project Compose dan database uji terpisah** dengan
`ANALYTICS_POLICY_RED=1`, project name, image tag, dan port yang berbeda dari gate normal. Fixture ini memberi
akun dua-unit grant seluruh organisasi, sementara tabel oracle tetap menyimpan scope yang seharusnya. Jalankan
skenario sedikitnya 384 VU agar semua akun dua-unit mendapat query, konversi observasi, lalu jalankan `verify.sql`;
hasil yang diharapkan adalah exit code 3 pada pemeriksaan policy. Buang stack negative-control sesudahnya dan
siapkan ulang fixture normal sebelum mencatat hasil gate.

Jangan menyatakan area ini lulus sebelum laporan memuat concurrency tertinggi yang memenuhi SLO, perangkat
keras, durasi beban penuh, jumlah tenant, serta container atau resource yang jenuh lebih dulu. `docker stats
--no-stream` dan jumlah backend dari `pg_stat_activity` memberi bukti untuk bottleneck.

