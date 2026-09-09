# Integrasi dengan Core

Halaman ini untuk developer. Isinya semua hal yang **tidak** dibuat sendiri oleh modul aset.

Aturan dasarnya satu: **app tidak pernah menyentuh database Core.** Semua pertukaran lewat API, token bertanda tangan, atau event bertanda tangan.

## Yang diminta app ke Core

### Nomor dokumen

Setiap `kode` — master maupun transaksi — diterbitkan Number Sequence Core lewat `NumberSequenceClient`.

Yang perlu dipahami:

- **App tidak boleh punya penghitung sendiri.** Nomor yang tidak bisa dipertanggungjawabkan lebih buruk daripada gagal.
- Kalau reference-nya belum diaktifkan admin tenant, permintaan gagal **503**. Ini bukan kegagalan yang perlu disembunyikan; ia memberi tahu admin ada yang belum disiapkan.
- Kegagalan dari Core dibungkus `NumberSequenceException`, yang membawa kode galat dan status HTTP-nya sendiri. Controller meneruskannya apa adanya ke pemanggil, bukan mengubahnya jadi 500 — pemanggil perlu tahu bedanya "belum disiapkan" dan "ada yang rusak".
- Permintaan membawa **kunci idempotency**, sehingga permintaan yang diulang tidak membuang nomor.
- Permintaan untuk aset membawa `legal_entity_id`, karena penomorannya bisa direset per tahun buku — dan tahun buku milik badan hukum, bukan unit operasi.

### Tahun buku

`FiscalCalendarClient` menanyakan periode fiskal ke Core. App tidak menyimpan kalender fiskalnya sendiri, karena kalender itu milik badan hukum dan dipakai bersama modul lain.

### Satuan

`UnitOfMeasureClient` mengambil daftar satuan dari Core untuk dipakai rencana pengadaan. Kalau layanan satuan belum bisa dihubungi, endpoint mengembalikan 503, bukan daftar kosong — daftar kosong akan terbaca sebagai "tidak ada satuan", padahal yang terjadi adalah "belum tahu".

### Persetujuan

Dokumen dekomisioning diajukan ke workflow Core lewat kontrak `MesinWorkflow`, bukan lewat HTTP. App tidak tahu siapa approver-nya dan tidak boleh tahu — itu dikonfigurasi admin tenant di Core.

Pengajuan berjalan **di dalam transaksi yang menyimpan dokumennya**. Kalau alur persetujuan belum disiapkan untuk entitas legal tersebut, dokumennya tidak jadi dibuat dan nomornya tidak jadi terbit; jawabannya 422, bukan 503.

## Yang dikirim Core ke app

Sisanya masuk lewat `POST /api/internal/v1/...` dan diverifikasi middleware `coreerp-event` sebelum isinya diproses.

Keputusan workflow **tidak lagi** lewat jalur ini. Ia datang sebagai event Laravel `KeputusanWorkflowDiambil` yang didengarkan `TerapkanKeputusanDekomisioning`, dan listenernya berjalan di dalam transaksi keputusan Core — dokumen dan instance workflow berpindah status bersama-sama atau tidak sama sekali.

| Event | Endpoint penerima | Akibatnya |
| --- | --- | --- |
| `core.tenant.provisioned.v1` | `/internal/v1/provisioning/tenant` | Master dasar Indonesia diisi |

### Tanda tangan

Diperiksa `VerifyCoreErpEvent` sebelum controller mana pun dipanggil. Header `X-CoreERP-Event-Timestamp` dan `X-CoreERP-Event-Signature`; tanda tangannya HMAC SHA-256 atas gabungan timestamp dan **isi mentah** permintaan, memakai kunci bersama.

Tiga hal yang membuat permintaan ditolak 401:

- Timestamp bukan angka, atau **selisihnya lebih dari 300 detik** dari waktu sekarang. Batas ini yang mencegah permintaan lama direkam lalu dikirim ulang orang lain.
- Kunci penandatangan kosong di sisi app.
- Tanda tangan tidak cocok. Dibandingkan dengan `hash_equals`, bukan `===`, supaya lama pembandingan tidak membocorkan isi tanda tangan.

Kunci itu — `COREERP_APP_CONTEXT_SIGNING_KEY` — harus **sama persis** di Core dan di app. Kalau berbeda, event ditolak dan gejalanya terlihat seperti "persetujuan tidak pernah sampai".

::: warning Jam yang meleset ikut menolak event
Karena batas selisihnya 300 detik, jam server app yang meleset lebih dari lima menit dari Core akan menolak **semua** event dengan 401 — meski kunci dan tanda tangannya benar. Kalau event tiba-tiba berhenti diterima tanpa ada perubahan kode, periksa jam sebelum memeriksa kunci.
:::

### Event yang sama bisa datang dua kali

Pengiriman event tidak menjamin sampai tepat sekali. Kalau jaringan putus setelah app selesai memproses tetapi sebelum jawabannya sampai ke Core, Core akan mengirim ulang — dan tanpa penjagaan, satu keputusan persetujuan akan diproses dua kali.

Karena itu ada tabel `processed_core_events` dengan kunci unik `(tenant_id, event_id)`:

```php
if (DB::table('processed_core_events')->insertOrIgnore([...]) === 0) {
    // sudah pernah diproses, jawab sukses tanpa mengerjakan apa pun
}
```

`insertOrIgnore` mengembalikan jumlah baris yang benar-benar masuk. Nol berarti event itu sudah pernah dicatat, jadi pemrosesannya dilewati dan permintaannya tetap dijawab sukses — mengembalikan galat justru membuat Core mengirim ulang lagi.

Penjagaan ini ada di **database**, bukan di memori, karena app berjalan di beberapa instance sekaligus. Dua salinan event yang tiba bersamaan di dua instance berbeda hanya bisa dipisahkan oleh sesuatu yang mereka bagi bersama.

Kalau Anda menambah penerima event baru, ikuti pola ini. Melewatkannya tidak akan pernah terlihat di feature test.

### Keputusan bisa datang berhari-hari kemudian

Ini yang paling sering salah dirancang. Workflow bisa selesai jauh setelah permintaan yang memulainya berakhir. Karena itu id korelasi **disimpan pada dokumen sejak awal** — membacanya dari permintaan yang sedang berjalan tidak mungkin, permintaan itu sudah lama selesai.

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
| `src/Services/PersetujuanAset.php` | Pengajuan persetujuan lewat kontrak Core |
| `src/Listeners/TerapkanKeputusanDekomisioning.php` | Penerapan keputusan persetujuan |
| `api/app/Services/FiscalCalendarClient.php` | Tahun buku |
| `api/app/Services/UnitOfMeasureClient.php` | Satuan |
| `api/app/Http/Controllers/ReferenceDataController.php` | Endpoint referensi yang meneruskan satuan dan kelompok fiskal |
| `api/app/Http/Controllers/TenantProvisioningController.php` | Penerima event penyiapan |
| `contracts/asyncapi.yaml` | Kontrak event yang diterima |

## Halaman terkait

- [API dan integrasi](/dev/04-api-and-integration) — bentuk envelope dan aturan versinya
- [Number sequence](/dev/14-number-sequences) — cara penomoran bekerja di Core
- [Kontrak](/apps/management-aset/arsitektur/kontrak) — cara menjaga janji ini tetap tertulis
