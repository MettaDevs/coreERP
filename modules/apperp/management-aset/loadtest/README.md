# Uji beban Management Aset

Folder ini berisi **skenario dan oracle** milik modul. Ia tidak lagi berisi stack: sejak F7-03
hanya ada satu, di `apps/core/loadtest/`, dan ia menjalankan runtime Core yang sungguhan
dengan empat instance di belakang nginx.

Cara menjalankan, hasil terukur, dan batas kejujurannya ada di `README.md` folder itu. Yang
dijelaskan di sini hanya yang khas modul ini.

## Isi

| Berkas | Apa | Status |
| --- | --- | --- |
| `k6/master-data.js` | Penjenuhan master + transaksi: CRUD, idempotency, batas tenant, eskalasi hak | dijalankan pada runtime baru |
| `k6/maintenance.js` | Setup maintenance, dan perlombaan penggantian kaitan | dijalankan pada runtime baru |
| `k6/depreciation.js` | Proposal, finalisasi, "Post penyusutan", dan saldo penyusutan; perlombaan finalisasi dan perlombaan post | dijalankan pada runtime baru |
| `k6/work-order.js` | Siklus dokumen work order, transisi terlarang, perlombaan transisi | dijalankan pada runtime baru |
| `k6/posting-group.js` | Posting group aset: perlombaan pembuatan dan arsip tanggal berlaku, akun dan group tenant lain | dijalankan pada runtime baru |
| `k6/receipt-posting.js` | Penyelesaian penerimaan dan jurnalnya — perolehan untuk pembelian, saldo awal untuk aset lama: perlombaan menyelesaikan dokumen yang sama, beban serentak dengan impor saldo awal dari CSV, perlombaan koreksi nilai perolehan aset yang sama, pratinjau dan penyelesaian dokumen tenant lain | dijalankan pada runtime baru |
| `verify.sql` | Oracle kebenaran modul, dibaca langsung dari database | dipakai sebagai gate |
| `check-manifest.py` | Pemeriksa `app.yaml`; tidak ada hubungannya dengan beban | — |

## Yang berubah ketika modul masuk ke runtime Core

**Tidak ada lagi tiruan Core.** `stub-core/` dulu berdiri di tempat Control Plane untuk
menerbitkan nomor lewat HTTP, dan sekaligus menjadi oracle nomor lewat `/__stats`. Di dalam satu
runtime, nomor diterbitkan proses yang sama lewat kontrak `PenerbitNomor`; tidak ada yang tersisa
untuk ditiru. Oracle-nya pindah ke `verify.sql`, dan pindah ke atas: tiap `kode` yang tersimpan
modul harus punya satu baris di `number_sequence_issues` pada tenant **dan** reference yang benar.
Yang dulu dibuktikan dengan menghitung ("4.342 terbit, 4.342 unik") sekarang dibuktikan dengan
mengikat tiap baris ke terbitannya.

**Tidak ada lagi token konteks.** Rute modul berada di belakang `['web', 'auth']` dengan awalan
`/api/modules/management-aset/v1/`; identitasnya sesi Core, lengkap dengan cookie dan CSRF. Karena
itu `mint-tenants.mjs` dan `k6/tenants.json` dihapus, dan tenant disiapkan lewat alur pendaftaran
usaha yang sungguhan di dalam `setup()` — lihat `apps/core/loadtest/k6/lib.js`.

**Nama tabel berawalan `aset_`.** Modul berbagi database Core dan dipisahkan awalan tabel, bukan
database sendiri. Query oracle yang masih menyebut `m_group_aset` tidak error — ia hanya tidak
menemukan tabel, dan gate-nya lolos secara palsu. Itu sebabnya seluruh `verify.sql` ditulis ulang.

## Menjalankan skenario modul

Dari `apps/core/loadtest/`, dengan stack sudah menyala:

```powershell
$core = "$PWD\k6"
$aset = "$PWD\..\..\..\modules\apperp\management-aset\loadtest\k6"

docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=128 -e VUS=1000 -e DURATION=90s `
  -e RUN_ID=gate-sat-3 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/master-data.js

docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=link-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-race-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/maintenance.js

docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=64 -e VUS=256 -e DURATION=90s `
  -e RUN_ID=gate-wo-sat-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/work-order.js

docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=transition-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-wo-race-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/work-order.js

docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=128 -e VUS=1000 -e DURATION=90s `
  -e RUN_ID=gate-dep-sat-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/depreciation.js

docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=finalize-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-dep-race-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/depreciation.js

# Perlombaan "Post penyusutan": FIXTURE wajib baru, periode yang sudah di-post tidak dapat dibalapkan lagi.
docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=post-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-dep-post-race-1 -e FIXTURE=pr1 `
  grafana/k6:0.55.0 run /scripts/aset/depreciation.js

docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-pg-race-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/posting-group.js

docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=128 -e VUS=1000 -e DURATION=90s `
  -e RUN_ID=gate-pg-sat-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/posting-group.js

docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-rcp-race-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/receipt-posting.js

docker run --rm -i --network core-loadtest_default `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWDesults:/results" `
  -e BASE_URL=http://lb -e PROFILE=adjust-race -e VUS=32 -e DURATION=90s -e RACE_TENANTS=4 `
  -e RUN_ID=gate-adj-race-1 -e FIXTURE=ar1 `
  grafana/k6:0.55.0 run /scripts/aset/receipt-posting.js

docker run --rm -i --network core-loadtest_default --ulimit nofile=65536:65536 `
  -v "${core}:/scripts" -v "${aset}:/scripts/aset" -v "$PWD\results:/results" `
  -e BASE_URL=http://lb -e PROFILE=saturation -e TENANTS=128 -e VUS=1000 -e DURATION=90s `
  -e RUN_ID=gate-rcp-sat-1 -e FIXTURE=g1 `
  grafana/k6:0.55.0 run /scripts/aset/receipt-posting.js

docker compose exec -T db psql -U core_erp -d core_erp -f - < ..\..\..\modules\apperp\management-aset\loadtest\verify.sql
```

`depreciation.js` menyiapkan fixture yang jawabannya sudah diketahui: satu aset bernilai 1.000
dengan residu 0, garis lurus sisa umur, masa manfaat tiga periode, dan round-off 100 pada matriks
group x buku. Nilainya karena itu wajib 300, 300, 400 dan saldo akhirnya akumulasi 1.000 dengan
nilai buku 0. Kunci seed-nya diikat ke `FIXTURE`, bukan ke `RUN_ID`, supaya run berikutnya memakai
kembali aset dan ketiga periode yang sama alih-alih menumbuhkan data. Feed posting finance entitas
legalnya dinyalakan dengan cutover 1 Januari 2026, seperti `receipt-posting.js`: tanpanya setiap jurnal
tercatat `manual` dan penerbit tidak pernah menilai baris jurnal penyusutan.

## Profil

| Profil | Menjawab | Yang digate |
| --- | --- | --- |
| `saturation` | Apakah modul tetap **benar** saat jenuh? | 0 pelanggaran, 0 error aplikasi |
| `link-race` | Apakah dua penulis yang berebut kaitan yang sama saling merusak? | 0 himpunan gabungan |
| `attribute-race` | Apakah Values dan nilai aset tetap cocok saat diubah bersamaan? | 0 nilai di luar Values |
| `transition-race` | Apakah dua transisi dari versi yang sama dapat sama-sama menang? | 0 `transition_double_wins` |
| `finalize-race` | Apakah satu periode dapat menambah saldo buku dua kali? | 0 jawaban berbeda, akumulasi tetap |
| `post-race` (`depreciation.js`) | Apakah dua "Post penyusutan" untuk periode yang sama dapat sama-sama menerbitkan posting — beban dua kali di aplikasi finance? | 0 `post_serentak_dua_posting`, 0 `server_errors`, dan `verify.sql`: total tiap posting penyusutan sama dengan periode yang ditandainya |
| `race` (`posting-group.js`) | Apakah dua penyimpanan pertama untuk group dan tanggal yang sama, atau penyimpanan dan arsip yang bersamaan, dapat berakhir 500 atau baris campuran? | 0 `server_errors`, 0 `posting_group_mixed_rows` |
| `race` (`receipt-posting.js`) | Apakah dua penyelesaian dokumen penerimaan yang sama dapat sama-sama menang — aset kembar dan jurnal perolehan atau saldo awal dua kali? | 0 `correctness_violations`, 0 `server_errors`, dan `verify.sql`: tepat satu posting berjenis benar per penerimaan selesai, akumulasi jurnal saldo awal sama dengan register |
| `adjust-race` (`receipt-posting.js`) | Apakah koreksi nilai perolehan serentak atas aset yang sama dapat menghitung selisih dari nilai lama — buku besar dan register berpisah tanpa ada yang ditolak? | 0 `correctness_violations`, 0 `server_errors`, 0 `adjust_rejected`, dan `verify.sql`: rantai koreksi tiap aset tersambung dari jurnal perolehannya sampai register, tanpa nomor bolong |
| `latency` | Berapa concurrency yang masih memenuhi SLO? | p95/p99 per jenis operasi |

Latensi pada beban jenuh mengukur kedalaman antrean, bukan biaya kode. Karena itu gate latensi
diambil pada concurrency yang masih tertahan, bukan pada titik jenuh.

## Oracle kebenaran

Tiga sumber terpisah, tidak ada yang memakai kode yang sedang diuji sebagai hakim:

1. **`verify.sql` modul** — langsung ke database: kode ganda per tenant, `creation_key` ganda,
   anak yang menunjuk induk tenant lain, anak yatim, prefix kode yang tertukar antar reference,
   baris modul yang menunjuk tenant yang tidak ada, tiap kode terikat ke satu baris terbitan
   Number Sequence (kecuali group aset dan buku penyusutan, yang kodenya diketik dan diperiksa
   bentuknya), posting group yang menunjuk akun tenant lain, dan jurnal perolehan: tepat satu
   posting per penerimaan selesai di tenant yang sama, tidak ada posting tanpa penerimaan selesai,
   debit posting sama dengan nilai perolehan asal ditambah PPN, dan jumlah aset sama dengan jumlah
   unit. Untuk koreksi nilai perolehan: rantai "sebelum" dan "sesudah" tiap aset tersambung dari
   jurnal perolehannya, nilai asal ditambah seluruh selisihnya sama dengan register, nomor urutnya
   tanpa lubang, dan tiap koreksi merujuk jurnal asal dengan mode yang sama.
   Untuk jurnal penyusutan: periode yang ditandai di-post menunjuk posting berjenis benar, total
   tiap posting penyusutan sama dengan periode yang ditandainya, dan pembalikan mengikuti periode
   aslinya.
2. **`verify.sql` Core** — batas tenant, materialisasi sequence per entitlement, dan terbitan nomor
   yang menembus batas tenant.
3. **Probe di dalam k6** — sesi tenant A membaca record tenant B (harus 404), menulis anak di bawah
   induk tenant B (harus 422), memindahkan status work order tenant B (harus 404), memfinalkan
   periode penyusutan tenant B (harus 404), menulis posting group tenant B (harus 404) atau
   memakai akun tenant B (harus 422), membaca pratinjau jurnal atau menyelesaikan penerimaan
   tenant B (harus 404), memeriksa "Post penyusutan" atas entitas legal dan buku tenant B (harus
   403, 422, atau tanpa satu periode pun), dan tenant yang role-nya dipersempit ke satu duty
   membuka master atau permukaan sebelahnya (harus 403). Semuanya berjalan **selama** beban penuh,
   bukan sesudahnya.

Ketiganya sudah dibuktikan bisa merah; caranya dan angkanya ada di README stack. Jalankan
`SELFTEST=1` untuk mengulang pembuktian itu.

> **`SELFTEST=1` pada `depreciation.js` merusak fixture-nya.** Salah satu pembuktian merah
> mengirim `reversal`, dan pembalikan memang menggeser saldo untuk selamanya. Jalankan dengan
> `FIXTURE` tersendiri; sesudahnya fixture itu tidak dapat dipakai untuk run sungguhan, dan
> setup-nya akan berhenti dengan galat — yang memang diinginkan, bukan dibiarkan hijau.

## Batas kejujuran

- Skenario work order menumbuhkan datanya sendiri: tiap iterasi siklus, balapan transisi, dan
  probe transisi terlarang membuat dokumen baru. Angka latensi antar-sesi karena itu tidak
  sebanding begitu tabelnya jauh lebih besar; angka kebenarannya tetap sebanding.
- Penyusutan diuji pada satu aset per tenant dengan satu buku. Tutup bulan massal
  (`penyusutan/proposal-massal`) dan aset dengan beberapa buku sekaligus belum diukur di bawah beban.
- "Post penyusutan" di bawah beban karena itu selalu satu aset per periode: ringkasan jurnal banyak
  group dan unit, buku kedua yang tidak mengirim, dan penghalang presisi diuji test feature
  (`DepreciationPostingTest`). Pembalikan yang selesai di sela pembacaan pertama dan kunci proses post
  diuji deterministik di sana juga, bukan dibalapkan di bawah beban: jendelanya terlalu sempit untuk
  terkena secara acak.
- Jurnal balik penyusutan (`asset.depreciation_reversal`) hanya terbit lewat SELFTEST profil
  saturation, tidak pernah di run sungguhan: pembalikan menggeser saldo fixture untuk selamanya. Pada
  area 11 SELFTEST itu menerbitkan tiga jurnal balik dengan beban ringan (16 VU) dan `verify.sql` tetap
  0. Jalurnya diuji test feature; pemeriksaan `verify.sql`-nya dibuktikan merah lewat suntikan (README
  stack).
- Satu sesi dipakai banyak VU (limiter login berlaku per email + IP). Yang tidak diuji karenanya:
  pembuatan sesi serentak dalam jumlah besar.
- Jurnal perolehan, saldo awal, dan penyusutan pada uji beban selalu `held`: tenant uji beban tidak
  punya hierarki manajemen, jadi BU tidak dapat diturunkan. Satu posting per penerimaan atau periode,
  total yang sama dengan register, dan batas tenant tetap digate; jalur `pending` dengan akun dan BU
  terisi diuji oleh test feature, bukan di bawah beban.
- Group uji beban hanya punya satu buku, jadi saldo awal yang angkanya berbeda per buku (K-28) dan
  penyusutan lanjutan sesudah cutover diuji oleh test feature (`OpeningBalanceTest`), bukan di bawah
  beban.
- `receipt-posting.js` membuat draf arena balapan berurutan. Pembuatan serentak dalam satu tenant
  menabrak deadlock penerbitan nomor Core (40P01 pada `number_sequence_allocations`); cacat itu
  diukur terpisah lewat `number_sequence_failures` dan diperbaiki di Number Sequence, bukan di sini.
- UI tidak disentuh uji beban ini.
