# Integrasi dengan Core

Halaman ini untuk developer. Isinya semua hal yang **tidak** dibuat sendiri oleh modul aset.

Aturan dasarnya satu: **modul tidak pernah menyentuh tabel milik Core atau modul lain.** Modul dan
Core memakai database tenant yang sama, jadi yang menolak di sini bukan database — `DB::table()`
akan berhasil menjangkaunya. Yang menolak adalah penjaga batas di
`apps/control-plane/tests/Feature/Boundary/`, dan itu tetap batas.

Pintunya satu: antarmuka di `App\Support\Modules\Contracts`. Itu **satu-satunya** namespace Core yang
boleh disebut modul ini.

::: tip Yang berubah, dan kenapa halaman ini ditulis ulang
Empat klien HTTP — nomor, kalender fiskal, satuan, workflow — sudah tidak ada. Begitu pula token
konteks, tanda tangan HMAC pada event, dan `services.coreerp`. Semuanya jadi pemanggilan fungsi di
dalam proses yang sama. Alasannya ada di [keputusan satu runtime](/todo/satu-runtime/00-keputusan).
:::

## Yang diminta modul ke Core

### Nomor dokumen

Setiap `kode` — master maupun transaksi — diterbitkan Number Sequence Core lewat kontrak
`PenerbitNomor`, dibungkus `PenerbitNomorAset` yang menerjemahkan kegagalan Core menjadi kegagalan
yang berarti bagi pemanggil modul.

Yang perlu dipahami:

- **Modul tidak boleh punya penghitung sendiri.** Nomor yang tidak bisa dipertanggungjawabkan lebih buruk daripada gagal.
- **Penerbitan berjalan di koneksi yang sama dengan dokumennya**, jadi ia ikut ke dalam transaksi
  dokumen itu. Dokumen gagal, nomornya ikut batal. Inilah keuntungan yang paling nyata dari satu
  runtime: tidak ada lagi lompatan nomor yang harus dijelaskan ke pemeriksa.
- Kalau reference-nya belum diaktifkan admin tenant, permintaan gagal dan pesannya menyebut sebabnya. Ini bukan kegagalan yang perlu disembunyikan; ia memberi tahu admin ada yang belum disiapkan.
- Permintaan membawa **kunci idempotency**, sehingga permintaan yang diulang tidak membuang nomor.
- Permintaan untuk aset membawa `legal_entity_id`, karena penomorannya bisa direset per tahun buku — dan tahun buku milik badan hukum, bukan unit operasi.

### Tahun buku

`KalenderFiskalAset` menanyakan periode fiskal ke Core lewat kontrak `KalenderFiskal`. Modul tidak
menyimpan kalender fiskalnya sendiri, karena kalender itu milik badan hukum dan dipakai bersama
modul lain.

### Satuan

`DaftarSatuanAset` mengambil daftar satuan lewat kontrak `DaftarSatuan`, untuk dipakai rencana
pengadaan. Kalau daftar satuan belum bisa diambil, endpointnya menjawab galat, bukan daftar kosong —
daftar kosong akan terbaca sebagai "tidak ada satuan", padahal yang terjadi adalah "belum tahu".

### Persetujuan

Dokumen dekomisioning diajukan ke workflow Core lewat kontrak `MesinWorkflow`. Modul tidak tahu
siapa approver-nya dan tidak boleh tahu — itu dikonfigurasi admin tenant di Core.

Pengajuan berjalan **di dalam transaksi yang menyimpan dokumennya**. Kalau alur persetujuan belum
disiapkan untuk entitas legal tersebut, dokumennya tidak jadi dibuat dan nomornya tidak jadi terbit.

### Konteks permintaan

Tenant, badan hukum, unit kerja, id pengguna, permission efektif, dan lingkup kebijakan data dibaca
dari kontrak `KonteksTenant` dan `KonteksPermintaan`, yang diisi middleware `konteks-module`.
Pembagiannya bukan selera: tenant dan batas organisasi adalah **tempat** sebuah query boleh membaca,
sedang izin dan kebijakan data adalah **apa** yang boleh dilakukan pengguna di tempat itu.

Keduanya gagal menutup: tanpa konteks, jawabannya `false` atau sebuah lemparan, tidak pernah sebuah
tebakan.

## Yang dikirim Core ke modul

Fakta dari Core datang sebagai **event Laravel** yang dipancarkan di dalam proses, bukan sebagai
permintaan HTTP bertanda tangan. Modul mendengarkannya dengan listener biasa.

| Event | Listener | Akibatnya |
| --- | --- | --- |
| `TenantDisiapkan` | `SiapkanDataAwalTenant` | Master dasar Indonesia diisi |
| `KeputusanWorkflowDiambil` | `TerapkanKeputusanDekomisioning` | Dokumen dan aset berpindah status |

Tanda tangan HMAC, batas selisih waktu, dan `COREERP_APP_CONTEXT_SIGNING_KEY` tidak ada lagi pada
jalur ini. Ketiganya melindungi permintaan yang menyeberangi jaringan; permintaan itu sudah tidak
ada.

### Menyaring, bukan memvalidasi

Ini perbedaan terpenting antara sebuah listener dan sebuah controller, dan yang paling mudah salah.

Event ini sampai ke **setiap** listener di runtime, termasuk milik modul yang tidak dibeli tenant
tersebut. Amplop yang bukan urusan modul ini dilewati diam-diam. Menolaknya sebagai kesalahan berarti
pendaftaran usaha gagal karena ada modul yang tidak dibeli — kegagalan yang sebabnya tidak masuk akal
bagi siapa pun yang membacanya.

### Mencatat, bukan `abort`

Dokumen yang tidak ditemukan dulu menjadi 404 kepada Core. Di dalam proses, melempar dari sini
**membatalkan transaksi keputusannya**: persetujuan yang sah gagal karena modul tidak menemukan
dokumennya. Yang benar mencatatnya sebagai peringatan dan membiarkan keputusan Core berdiri.

### Pengiriman seketika mengubah urutan

Event dipancarkan di dalam transaksi yang membuat faktanya, jadi listener berjalan **sebelum**
pemanggilnya selesai. Untuk penyediaan tenant itu berarti penyediaan yang gagal ikut membatalkan
tenantnya.

Dulu jalur HTTP berjalan setelahnya dan terpisah, sehingga penyediaan yang gagal meninggalkan tenant
hidup tanpa satu pun klasifikasi fiskal, profil penyusutan, atau setup maintenance — rusak dengan
cara yang tidak terlihat sampai pemakainya membuka layar pertama. Gagal seketika lebih baik daripada
itu, tetapi ia **perubahan perilaku**, bukan sekadar perpindahan jalur, dan itu yang harus diingat
saat menambah listener baru.

### Dedup tetap ada, dan itu disengaja

`aset_processed_core_events` dipertahankan meski barisnya sekarang datang dari satu jalur:

```php
if (DB::table('aset_processed_core_events')->insertOrIgnore([...]) === 0) {
    // sudah pernah diproses, berhenti tanpa mengerjakan apa pun
}
```

Id event yang dipancarkan sama dengan id baris outbox. Kalau kelak baris yang sama juga tiba lewat
jalur lain — modul dipasang di luar proses, atau outbox diputar ulang — pemrosesan keduanya tetap
terhitung sekali.

Kunci uniknya `(tenant_id, event_id)`. `insertOrIgnore` mengembalikan jumlah baris yang benar-benar
masuk; nol berarti event itu sudah pernah dicatat.

### Keputusan bisa datang berhari-hari kemudian

Ini yang paling sering salah dirancang, dan satu runtime tidak mengubahnya. Workflow bisa selesai
jauh setelah permintaan yang memulainya berakhir. Karena itu id korelasi **disimpan pada dokumen
sejak awal** — membacanya dari permintaan yang sedang berjalan tidak mungkin, permintaan itu sudah
lama selesai.

Kalau Anda menambah event baru yang membawa asal-usul suatu kejadian, simpan datanya saat kejadian
itu dicatat, bukan saat event dikirim.

## Penyiapan tenant baru

Saat `TenantDisiapkan` diterima:

1. Listener memeriksa `data.app_ids` memuat id modul ini. Kalau tidak, ia berhenti tanpa kesalahan.
2. `ProvisionIndonesiaStarterData` mengisi master dasar: kelompok harta fiskal PMK 72/2023, profil penyusutan, buku, tipe lokasi, kondisi, setup maintenance.
3. Semua nomor tetap diminta ke Core.

Sifatnya idempoten dan tidak menimpa: record yang sudah disesuaikan tenant dibiarkan, record yang
sengaja diarsipkan tidak dihidupkan lagi. Itu sebabnya tidak ada tabel dedup untuk jalur ini — yang
menahan pengulangan adalah penyedianya sendiri, bukan catatan terpisah yang bisa menyimpang darinya.

## Yang hilang tanpa pengganti

`COREERP_SERVICE_TOKEN`, `COREERP_APP_CONTEXT_SIGNING_KEY`, dan `COREERP_APP_ID` tidak dibaca lagi
oleh modul ini. Ketiganya milik app yang berjalan sebagai proses terpisah; modul tidak punya
lingkungan sendiri, jadi tidak punya nilai sendiri untuk dicocokkan.

Kalau Anda menemukan salah satunya masih disebut di suatu tempat pada modul ini, itu sisa yang
belum dibersihkan, bukan konfigurasi yang perlu diisi.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `src/Services/PenerbitNomorAset.php` | Permintaan nomor lewat kontrak Core |
| `src/Services/KalenderFiskalAset.php` | Tahun buku |
| `src/Services/DaftarSatuanAset.php` | Satuan |
| `src/Services/PersetujuanAset.php` | Pengajuan persetujuan |
| `src/Listeners/SiapkanDataAwalTenant.php` | Penyiapan tenant baru |
| `src/Listeners/TerapkanKeputusanDekomisioning.php` | Penerapan keputusan persetujuan |
| `src/Http/Controllers/ReferenceDataController.php` | Endpoint referensi yang meneruskan satuan dan kelompok fiskal |
| `src/Models/support/ProcessedCoreEvent.php` | Penjagaan event ganda |

Semuanya relatif terhadap `modules/apperp/management-aset/`.

## Halaman terkait

- [API dan integrasi](/dev/04-api-and-integration) — batas mana yang memakai bentuk apa
- [Number sequence](/dev/14-number-sequences) — cara penomoran bekerja di Core
- [Kontrak](/apps/management-aset/arsitektur/kontrak) — cara menjaga janji ini tetap tertulis
