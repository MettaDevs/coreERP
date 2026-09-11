# Kontrak

Halaman ini untuk developer. Isinya cara janji modul ini ke kode di luar dirinya ditulis dan dijaga.

Kontrak adalah **janji ke kode yang tidak kita kendalikan**. Ia bukan dokumentasi kode, dan ia tidak opsional begitu sebuah endpoint atau event melewati batas modul.

## Dua berkas

| Berkas | Isi |
| --- | --- |
| `contracts/openapi.yaml` | Endpoint HTTP |
| `contracts/asyncapi.yaml` | Event yang **diterima** dari Core |

`openapi.yaml` adalah hasil gabungan, bukan berkas yang disunting langsung. Sumbernya di `contracts/src/`:

```
contracts/src/
├── openapi.yaml                    kerangka dan daftar bagian
├── paths/
│   ├── platform.yaml               health, context, reference-data
│   ├── master-data.yaml            semua master beserta endpoint turunannya
│   ├── maintenance.yaml            setup maintenance dan penautannya
│   ├── aset.yaml                   register aset dan dokumen siklus
│   ├── pemeliharaan-aset.yaml      work order
│   └── penyusutan.yaml             proposal, finalisasi, pembalikan
└── components/                     schema, response, parameter, security
```

Urutan berkas di `paths/` ditentukan `x-bundle` pada `src/openapi.yaml`; berkas baru harus didaftarkan di sana, kalau tidak isinya tidak ikut tergabung.

Setelah menyunting `src/`, bangun ulang:

```bash
python contracts/bundle.py
```

Berkas gabungan ikut di-commit supaya perkakas yang tidak bisa menyelesaikan `$ref` lintas berkas tetap terlayani. Kalau ia tertinggal dari sumbernya, pembacanya melihat gambaran yang sudah tidak berlaku. Tidak ada CI yang memeriksanya lagi; lihat bagian berikutnya.

## Tidak ada lagi pemeriksa cakupan di sini

Dulu `contracts/check-contract-coverage.py` membandingkan daftar rute Laravel dengan
`openapi.yaml` dan gagal bila keduanya berbeda. Skrip itu **dihapus pada F3-23**, dan
alasannya bukan karena pemeriksaan kontrak jadi kurang penting.

Ia dibaca dari `api/` dan menjalankan `php artisan route:list --json` di sana. Sejak module
masuk ke dalam runtime Core, `api/` tidak lagi memuat `artisan`, jadi skripnya berhenti
sebelum membandingkan satu rute pun. Ditambah lagi, tidak ada alur CI yang pernah
memanggilnya: langkah `Check internal API contract coverage` pada
`.github/workflows/lint.yml` berjalan dengan `working-directory: apps/core`, jadi
yang dijalankan adalah pemeriksa milik Core atas `contracts/openapi-internal.yaml` Core.

Yang menggantikannya adalah batas permukaannya sendiri: rute `/api/v1/...` module hanya
dipanggil UI module ini, di dalam proses dan repo yang sama, sehingga penyimpangan terlihat
pada test module dan pada UI yang memanggilnya. Permukaan yang benar-benar melewati batas
module tidak lagi berbentuk HTTP — ia kontrak PHP (`PenyediaLaporanModul`) dan event
Laravel in-process.

Rinciannya ada di `modules/apperp/management-aset/contracts/README.md`.

## Aturan menulis kontrak

**Endpoint baru masuk kontrak dalam perubahan yang sama.** Bukan menyusul.

**Tulis yang benar, bukan yang diinginkan.** Kalau kode belum memenuhi rancangan, kontrak menggambarkan kode dan menyebut celahnya. Konsumen yang membangun di atas field yang tidak pernah datang akan rusak diam-diam.

**Kode juga tidak boleh menerima yang dilarang kontrak.** Kebalikannya sama buruknya: handler yang memvalidasi field yang kontraknya nyatakan mustahil mengiklankan kemampuan yang tidak ada.

**Versi yang sudah terbit tidak diubah.** Menambah field wajib, menghapus field, atau menyempitkan tipe adalah versi berikutnya. Melebarkan `enum` yang dipakai konsumen untuk bercabang juga termasuk.

**Kedua sisi bergerak bersama.** Kontrak penerbit ada di repo Core, kontrak penerima di sini. Perubahan pada satu belum selesai sampai yang lain menyusul dalam pekerjaan yang sama.

## Yang belum dikontrakkan, dan itu disengaja

Tiga rute `permintaan-pembelian-aset/{id}` belum masuk kontrak karena perilaku yang akan dijanjikannya belum diputuskan. Kontrak yang mendahului keputusan menggambarkan bentuk yang tidak bisa diandalkan pemanggil.

Dulu statusnya tercatat di daftar `DEFERRED` milik pemeriksa cakupan. Setelah pemeriksa itu dihapus, satu-satunya catatannya adalah halaman ini.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `contracts/src/` | Sumber yang disunting |
| `contracts/bundle.py` | Penggabung |
| `contracts/README.md` | Keputusan F3-23: status berkas di folder ini |

## Halaman terkait

- [API dan integrasi](/dev/04-api-and-integration) — aturan platform untuk kontrak dan event
- [Integrasi dengan Core](/apps/management-aset/arsitektur/integrasi-core) — apa saja yang melewati batas modul
