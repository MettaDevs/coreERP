# Uji beban Management Aset

Folder ini berisi **skenario dan oracle** milik modul. Ia tidak lagi berisi stack: sejak F7-03
hanya ada satu, di `apps/control-plane/loadtest/`, dan ia menjalankan runtime Core yang sungguhan
dengan empat instance di belakang nginx.

Cara menjalankan, hasil terukur, dan batas kejujurannya ada di `README.md` folder itu. Yang
dijelaskan di sini hanya yang khas modul ini.

## Isi

| Berkas | Apa | Status |
| --- | --- | --- |
| `k6/master-data.js` | Penjenuhan master + transaksi: CRUD, idempotency, batas tenant, eskalasi hak | dijalankan pada runtime baru |
| `k6/maintenance.js` | Setup maintenance, dan perlombaan penggantian kaitan | dijalankan pada runtime baru |
| `k6/depreciation.js` | Proposal dan finalisasi penyusutan | **belum dipindah** |
| `k6/work-order.js` | Dokumen work order di bawah beban | **belum dipindah** |
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
usaha yang sungguhan di dalam `setup()` — lihat `apps/control-plane/loadtest/k6/lib.js`.

**Nama tabel berawalan `aset_`.** Modul berbagi database Core dan dipisahkan awalan tabel, bukan
database sendiri. Query oracle yang masih menyebut `m_group_aset` tidak error — ia hanya tidak
menemukan tabel, dan gate-nya lolos secara palsu. Itu sebabnya seluruh `verify.sql` ditulis ulang.

## Menjalankan skenario modul

Dari `apps/control-plane/loadtest/`, dengan stack sudah menyala:

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

docker compose exec -T db psql -U core_erp -d core_erp -f - < ..\..\..\modules\apperp\management-aset\loadtest\verify.sql
```

## Profil

| Profil | Menjawab | Yang digate |
| --- | --- | --- |
| `saturation` | Apakah modul tetap **benar** saat jenuh? | 0 pelanggaran, 0 error aplikasi |
| `link-race` | Apakah dua penulis yang berebut kaitan yang sama saling merusak? | 0 himpunan gabungan |
| `attribute-race` | Apakah Values dan nilai aset tetap cocok saat diubah bersamaan? | 0 nilai di luar Values |
| `latency` | Berapa concurrency yang masih memenuhi SLO? | p95/p99 per jenis operasi |

Latensi pada beban jenuh mengukur kedalaman antrean, bukan biaya kode. Karena itu gate latensi
diambil pada concurrency yang masih tertahan, bukan pada titik jenuh.

## Oracle kebenaran

Tiga sumber terpisah, tidak ada yang memakai kode yang sedang diuji sebagai hakim:

1. **`verify.sql` modul** — langsung ke database: kode ganda per tenant, `creation_key` ganda,
   anak yang menunjuk induk tenant lain, anak yatim, prefix kode yang tertukar antar reference,
   baris modul yang menunjuk tenant yang tidak ada, dan tiap kode terikat ke satu baris terbitan
   Number Sequence.
2. **`verify.sql` Core** — batas tenant, materialisasi sequence per entitlement, dan terbitan nomor
   yang menembus batas tenant.
3. **Probe di dalam k6** — sesi tenant A membaca record tenant B (harus 404), menulis anak di bawah
   induk tenant B (harus 422), dan tenant yang role-nya dipersempit ke satu duty membuka master
   sebelahnya (harus 403). Semuanya berjalan **selama** beban penuh, bukan sesudahnya.

Ketiganya sudah dibuktikan bisa merah; caranya dan angkanya ada di README stack. Jalankan
`SELFTEST=1` untuk mengulang pembuktian itu.

## Batas kejujuran

- `depreciation.js` dan `work-order.js` belum dipindahkan. Keduanya berhenti dengan galat pada
  `open('./tenants.json')` bila dijalankan — sengaja, supaya tidak ada skenario yang hijau tanpa
  menguji apa pun. Permukaan penyusutan dan work order berstatus belum terverifikasi di bawah beban.
- Satu sesi dipakai banyak VU (limiter login berlaku per email + IP). Yang tidak diuji karenanya:
  pembuatan sesi serentak dalam jumlah besar.
- UI tidak disentuh uji beban ini.
