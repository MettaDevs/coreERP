# Phase 7 - Fixed Assets F&O, Asset-only

Fase ini meneruskan refactor Management Aset tanpa mengubah Fase 0-6. Finance,
ledger, voucher, jurnal umum, posting GL, account resolution, dan rekonsiliasi
Finance berada di luar scope. `Finalisasi` hanya mengunci subledger Fixed Asset.

Dokumen ini menjadi checkpoint implementasi. Setiap subphase dicatat setelah gate
verifikasinya dijalankan. Karena worktree sudah memiliki perubahan sebelum Phase 7,
checkpoint digunakan sebagai jejak perubahan tanpa membuat commit campuran yang
dapat membawa perubahan agent sebelumnya.

## Checklist

| Subphase | Status | File berubah | Command | Hasil | Error | Perbaikan |
| --- | --- | --- | --- | --- | --- | --- |
| 7.0 Baseline dan tracing | selesai | - | `php artisan test tests/Feature/DepreciationScaleTest.php tests/Feature/DepreciationBookTest.php tests/Feature/DepreciationEndToEndTest.php tests/Feature/DepreciationTest.php tests/Unit/DepreciationCalculatorTest.php` | 36 test, 6.240 assertion lulus | PHPUnit tidak dapat menulis `.phpunit.result.cache` | Tidak mengubah source; warning dicatat sebagai environment permission |
| 7.1 Mesin perhitungan penyusutan | selesai | `api/app/Services/DepreciationCalculator.php`, `api/tests/Unit/DepreciationCalculatorTest.php` | `php artisan test tests/Unit/DepreciationCalculatorTest.php` | 13 test, 27 assertion lulus | - | Round-off nominal ditambahkan; periode terakhir mengambil sisa tepat |
| 7.2 Schema dan konfigurasi Asset | selesai | `GroupBukuPenyusutanController.php`, `GroupBookMatrix.tsx`, `contracts/src/components/schemas.yaml`, migration buku, migration rollback index | `php artisan migrate --no-interaction` + `php artisan migrate:rollback --step=4 --no-interaction` pada SQLite temporary; `php artisan test tests/Feature/DepreciationBookTest.php tests/Feature/DepreciationEndToEndTest.php`; `php artisan test ...` suite lengkap; `node_modules\\.bin\\tsc.cmd --noEmit`; `npm.cmd run build`; `bundle.py --check` | Migration Phase 7 up/down lulus; suite depresiasi 45 test, 6.297 assertion lulus; bundle sinkron; type-check/build lulus | Full rollback seluruh histori masih berhenti pada migration lama 2026_07_28_094000; bukan migration Phase 7 | Field akun dikeluarkan dari API/UI/contract/migration fresh-install; rollback index migration 7.2 diperbaiki; histori lama tidak diubah |
| 7.3 Proposal, finalisasi, dan reversal subledger | pending - reverify | `DepreciationController.php`, `DepreciationTest.php`, `contracts/src/paths/penyusutan.yaml` | Menunggu gate terpisah setelah 7.2 | Boundary akun sudah dibersihkan dan suite lifecycle ikut lulus, tetapi subphase belum ditutup sebagai gate tersendiri | - | Jalankan ulang proposal/finalisasi/reversal dan verifikasi export bridge tanpa perilaku GL |
| 7.4 Shell dan halaman utama Fixed Assets | selesai | `ui/src/App.tsx`, master registry, UI existing Book/Depreciation | `npm.cmd run build` | Type-check dan Vite build lulus; hanya warning chunk >500 kB | Sandbox menolak file sementara Vite | Build diulang dengan izin workspace; tidak menambah Shell kedua |
| 7.5 Placeholder F&O | pending - reverify | `ui/src/fixed-assets-setup/FixedAssetSetupPlaceholderPage.tsx`, `app.yaml`, `ui/src/master/GroupBookMatrix.tsx` | Build sudah lulus pada gate 7.2 | Matrix akun sudah dihapus; placeholder tetap hanya empty state | Gate UI belum ditutup terpisah setelah boundary cleanup | Verifikasi ulang placeholder dan copy tanpa menyentuh Finance |
| 7.6 Architecture, security, manifest, contract | selesai | `app.yaml`, `contracts/src/paths/penyusutan.yaml`, `contracts/openapi.yaml` | `php artisan app:register-manifest ... --dry-run`; `docker ... bundle.py --check`; `docker ... check-manifest.py`; `docker ... check-contract-coverage.py`; query DB runtime | Manifest dry-run lulus; bundle sinkron; checker manifest `PROBLEMS: none`; coverage `13 internal routes`; runtime navigation memuat dua layar baru, permission `2`, duty `1` | Runtime single-app shortcut pada `start.ps1` membuang compose app | Tidak mengubah start.ps1; runtime direbuild dengan dua app lokal, lalu registry diverifikasi read-only |
| 7.7 Scale-out k6 | gagal - gate berhenti | `loadtest/k6/depreciation.js`, `loadtest/verify.sql`, `loadtest/README.md`, `loadtest/mint-tenants.mjs` | `docker run --rm --network aset-loadtest_default --ulimit nofile=65536:65536 -v "D:\\Kerja\\app-erp-management-aset\\loadtest\\k6:/scripts:ro" -v "D:\\Kerja\\app-erp-management-aset\\loadtest\\results:/results" -e BASE_URL=http://lb -e PROFILE=saturation -e VUS=1000 -e DURATION=90s -e RUN_ID=dep-run1 grafana/k6:0.55.0 run /scripts/depreciation.js`; `docker compose -p aset-loadtest exec -T db psql -U aset -d app_erp_management_aset -f - < verify.sql` | Setup rounding/zero lulus; 1000 VU, 128 tenant, 4 API, 90 detik menghasilkan 70.281 request, 418,67 rps, correctness violations 0; SQL oracle 17 pemeriksaan seluruhnya 0 | k6 gagal threshold: checks 99,11%, http_req_failed 0,86%, 148 response >=500, 459 timeout klien; p95/p99 proposal 1.098/62.485 ms, finalisasi 1.032/62.400 ms, read 844/59.595 ms | Bottleneck terukur pada antrean worker/koneksi: 4 x 48 Apache worker dengan koneksi persisten; LB mencatat 371 upstream timeout, API tidak mencatat SQLSTATE/fatal. Perlu perbaikan kapasitas/pacing lalu rerun 7.7; 7.8 belum boleh dimulai |
| 7.8 Runtime verification dan penutupan | belum mulai | - | - | - | Menunggu 7.5-7.7 | Rebuild runtime, smoke test UI, graphify update, lalu tutup phase |

## Keputusan boundary

- Fixed asset setup dan subledger tetap berada di app Management Aset.
- General Ledger pages tidak dibuat.
- Tidak ada endpoint atau tombol `Post`.
- Artefak `tr_export_penyusutan` lama dipertahankan untuk kompatibilitas, bukan
  dianggap sebagai voucher atau bukti posting GL.
- Number sequence yang sudah dideklarasikan untuk Book dan Profile dipertahankan;
  Phase 7 tidak menambah reference dokumen Finance atau matriks.
- Data memakai `TenantContext`, `tenant_id`, `legal_entity_id`, dan `org_unit_id`
  sesuai sumber fakta yang sudah ada.

## Gate per subphase

### 7.1 Mesin perhitungan penyusutan

- Nilai buku berhenti tepat di `0` atau nilai residual.
- Nilai penyusutan tidak negatif dan tidak menembus residual.
- Round-off memakai nominal pembulatan; periode terakhir tidak dibulatkan.
- Test deterministic untuk SL, SLLR, RB, alternative profile, residual, zero, dan
  round-off lulus sebelum schema atau UI disentuh.

### 7.2 Schema dan konfigurasi Asset

- Round-off tersedia pada Book, Asset group book, dan snapshot AssetBook.
- Parent-child tetap tenant-safe melalui validasi dan constraint yang tersedia.
- Tidak menambah field akun atau logic Finance.

### 7.3 Lifecycle subledger

`Proposal -> Finalisasi -> Balikkan/Koreksi` harus idempotent dan tidak membuat GL
journal atau voucher.

### 7.4-7.5 UI

- Reuse Shell dan `@apperp/ui`.
- Tidak ada menu Finance atau tombol `Post`.
- Fixed asset parameters dan posting profiles yang belum didukung hanya menjadi
  placeholder yang tidak menyimpan logic Finance.

### 7.6 Architecture

- Entry point, permission, privilege, duty, data policy, dan contract harus sesuai
  `coreerp-architecture`.
- Tidak ada database query lintas app.
- Contract source diedit di `contracts/src/`, lalu bundle diverifikasi.

### 7.7 Scale-out

- k6 memakai PostgreSQL nyata, 1000+ VU, 100+ tenant, 2+ API instance, dan 90
  detik full load.
- SQL oracle memverifikasi 0 error aplikasi, 0 duplicate, 0 cross-tenant, 0 NBV
  negatif, dan 0 finalisasi ganda.

#### Run 2026-08-07

Skenario rounding menghasilkan `300`, `300`, `400` dari nilai perolehan `1.000`
dengan round-off matrix `100`; saldo akhir setiap tenant berada di `0`. Setup
berhasil untuk 128 tenant. Pada beban penuh, k6 mencatat correctness violation
`0`, tetapi gate ketahanan gagal karena antrean request melampaui kapasitas
worker: 418,67 request/detik, 459 timeout klien, dan p99 sekitar 62 detik.

SQL oracle langsung ke PostgreSQL menghasilkan `0` untuk semua 17 pemeriksaan,
termasuk duplicate export, penyusutan negatif, NBV melewati residual atau nol,
finalisasi ganda, dan saldo round-off yang tidak berakhir di residual atau nol.
Karena gate k6 belum lulus, subphase ini berhenti di sini dan 7.8 belum dimulai.

#### Temuan audit boundary setelah run

Run 7.7 memakai `export_to_backoffice: false`, sehingga SQL oracle tidak
menemukan export baru. Namun audit source menemukan perubahan lama yang masih
menerima atau membaca `akun_nilai_perolehan`, `akun_biaya_penyusutan`, dan
`akun_akumulasi_penyusutan` pada matrix dan payload finalisasi. Ini bukan logic
yang boleh dibawa ke Asset-only. Gate 7.2 sudah ditutup setelah field tersebut
dihapus dari source aktif; gate 7.3 dan 7.5 masih menunggu verifikasi terpisah
sebelum 7.7 diulang.

### 7.8 Runtime

- Rebuild melalui `D:\Kerja\erp-dev\start.ps1 -Build`.
- Verifikasi UI di `http://localhost:8000`.
- Jalankan `graphify update .` setelah perubahan source selesai.
