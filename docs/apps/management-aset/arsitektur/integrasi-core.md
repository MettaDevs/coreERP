# Integrasi dengan Core

Halaman ini untuk developer. Isinya semua hal yang **tidak** dibuat sendiri oleh modul aset.

Aturan dasarnya satu: **app tidak pernah menyentuh database Core.** Semua pertukaran lewat API, token bertanda tangan, atau event bertanda tangan.

## Yang diminta app ke Core

### Nomor dokumen

Setiap `kode` — master maupun transaksi — diterbitkan Number Sequence Core lewat `NumberSequenceClient`.

Yang perlu dipahami:

- **App tidak boleh punya penghitung sendiri.** Nomor yang tidak bisa dipertanggungjawabkan lebih buruk daripada gagal.
- Kalau reference-nya belum diaktifkan admin tenant, permintaan gagal **503**. Ini bukan kegagalan yang perlu disembunyikan; ia memberi tahu admin ada yang belum disiapkan.
- Permintaan membawa **kunci idempotency**, sehingga permintaan yang diulang tidak membuang nomor.
- Permintaan untuk aset membawa `legal_entity_id`, karena penomorannya bisa direset per tahun buku — dan tahun buku milik badan hukum, bukan unit operasi.

### Tahun buku

`FiscalCalendarClient` menanyakan periode fiskal ke Core. App tidak menyimpan kalender fiskalnya sendiri, karena kalender itu milik badan hukum dan dipakai bersama modul lain.

### Satuan

`UnitOfMeasureClient` mengambil daftar satuan dari Core untuk dipakai rencana pengadaan. Kalau layanan satuan belum bisa dihubungi, endpoint mengembalikan 503, bukan daftar kosong — daftar kosong akan terbaca sebagai "tidak ada satuan", padahal yang terjadi adalah "belum tahu".

### Persetujuan

Dokumen dekomisioning dikirim ke workflow Core lewat `WorkflowClient`. App tidak tahu siapa approver-nya dan tidak boleh tahu — itu dikonfigurasi admin tenant di Core.

## Yang dikirim Core ke app

Semuanya masuk lewat `POST /api/internal/v1/...` dan diverifikasi middleware `coreerp-event` sebelum isinya diproses.

| Event | Endpoint penerima | Akibatnya |
| --- | --- | --- |
| `core.workflow.decision.v2` | `/internal/v1/workflow-events` | Aset menjadi `decommissioned` kalau disetujui |
| `core.tenant.provisioned.v1` | `/internal/v1/provisioning/tenant` | Master dasar Indonesia diisi |

### Tanda tangan

Header `X-CoreERP-Event-Timestamp` dan `X-CoreERP-Event-Signature`. Tanda tangannya HMAC SHA-256 atas gabungan timestamp dan isi mentah, memakai kunci bersama.

Kunci itu — `COREERP_APP_CONTEXT_SIGNING_KEY` — harus **sama persis** di Core dan di app. Kalau berbeda, event ditolak dan gejalanya terlihat seperti "persetujuan tidak pernah sampai".

### Keputusan bisa datang berhari-hari kemudian

Ini yang paling sering salah dirancang. Workflow bisa selesai jauh setelah permintaan yang memulainya berakhir. Karena itu id korelasi **disimpan pada dokumen sejak awal** — membacanya dari request yang sedang berjalan tidak mungkin, request itu sudah lama selesai.

Kalau Anda menambah event baru yang membawa asal-usul suatu kejadian, simpan datanya saat kejadian itu dicatat, bukan saat event dikirim.

## Penyiapan tenant baru

Saat `core.tenant.provisioned.v1` diterima:

1. App memeriksa `data.app_ids` memuat id-nya. Kalau tidak, permintaan dijawab `skipped` — event yang sama disiarkan ke banyak app.
2. `ProvisionIndonesiaStarterData` mengisi master dasar: kelompok harta fiskal PMK 72/2023, profil penyusutan, buku, tipe lokasi, kondisi, setup maintenance.
3. Semua nomor tetap diminta ke Core.

Sifatnya idempoten dan tidak menimpa: record yang sudah disesuaikan tenant dibiarkan, record yang sengaja diarsipkan tidak dihidupkan lagi.

Untuk tenant lama yang sudah ada sebelum event ini dibuat, Core menyediakan perintah backfill.

## Konfigurasi yang harus cocok

| Nilai | Di mana | Kalau salah |
| --- | --- | --- |
| `COREERP_SERVICE_TOKEN` | env API app | Permintaan ke Core ditolak |
| `COREERP_APP_CONTEXT_SIGNING_KEY` | env Core **dan** app | Token dan event ditolak |
| `COREERP_APP_ID` | env API app | Event provisioning dianggap bukan untuk app ini |

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Services/NumberSequenceClient.php` | Permintaan nomor |
| `api/app/Services/WorkflowClient.php` | Pengiriman untuk persetujuan |
| `api/app/Services/FiscalCalendarClient.php` | Tahun buku |
| `api/app/Services/UnitOfMeasureClient.php` | Satuan |
| `api/app/Http/Controllers/TenantProvisioningController.php` | Penerima event penyiapan |
| `contracts/asyncapi.yaml` | Kontrak event yang diterima |

## Halaman terkait

- [API dan integrasi](/dev/04-api-and-integration) — bentuk envelope dan aturan versinya
- [Number sequence](/dev/14-number-sequences) — cara penomoran bekerja di Core
- [Kontrak](/apps/management-aset/arsitektur/kontrak) — cara menjaga janji ini tetap tertulis
