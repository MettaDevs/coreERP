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

Urutan berkas di `paths/` ditentukan `x-bundle` pada `src/openapi.yaml`; berkas baru harus didaftarkan di sana, kalau tidak isinya tidak ikut tergabung dan pemeriksa cakupan akan melaporkan rutenya sebagai tak terdokumentasi.

Setelah menyunting `src/`, bangun ulang:

```bash
python contracts/bundle.py
```

Berkas gabungan ikut di-commit supaya perkakas yang tidak bisa menyelesaikan `$ref` lintas berkas tetap terlayani. Kalau ia tertinggal dari sumbernya, konsumen membaca janji yang sudah tidak berlaku — karena itu CI memeriksanya.

## Pemeriksa cakupan

Tidak ada satu test pun yang gagal ketika sebuah endpoint absen dari kontrak. Persis begitulah `PUT /api/v1/jenis-aset/{id}/models` sempat ada tanpa dokumentasi sementara 181 test tetap hijau.

```bash
python contracts/check-contract-coverage.py
```

Tiga hal yang membuatnya berguna, dan yang perlu Anda pertahankan kalau menyuntingnya:

**Rute dibaca dari Laravel**, lewat `php artisan route:list --json`, bukan dari teks `routes/api.php`. Sebagian besar master didaftarkan lewat loop atas array `$masters`, jadi jalurnya tidak pernah muncul sebagai literal — pendekatan pencocokan teks akan melapor bersih sambil melewatkan puluhan rute.

**Jalur bertemplat ber-`enum` dimekarkan** sebelum dibandingkan. `/api/v1/{lifecycleDocument}` mendokumentasikan empat resource nyata lewat satu jalur. Dibandingkan apa adanya, ia melaporkan endpoint yang sebenarnya terdokumentasi sebagai hilang. Pemeriksa yang sering salah memberi peringatan akan berhenti dipercaya, lalu diabaikan — dan itu lebih buruk daripada tidak punya pemeriksa sama sekali.

**Celah yang ditunda disebut, bukan dimaafkan diam-diam.** Daftar `DEFERRED` memuat alasannya dan dicetak tiap kali pemeriksa jalan. Entry yang tidak lagi cocok dengan rute hidup dilaporkan sebagai galat, supaya pengecualian basi tidak memaafkan rute lain yang kelak memakai jalur itu.

Keduanya dijalankan `.github/workflows/contracts.yml` pada tiap PR.

## Aturan menulis kontrak

**Endpoint baru masuk kontrak dalam perubahan yang sama.** Bukan menyusul.

**Tulis yang benar, bukan yang diinginkan.** Kalau kode belum memenuhi rancangan, kontrak menggambarkan kode dan menyebut celahnya. Konsumen yang membangun di atas field yang tidak pernah datang akan rusak diam-diam.

**Kode juga tidak boleh menerima yang dilarang kontrak.** Kebalikannya sama buruknya: handler yang memvalidasi field yang kontraknya nyatakan mustahil mengiklankan kemampuan yang tidak ada.

**Versi yang sudah terbit tidak diubah.** Menambah field wajib, menghapus field, atau menyempitkan tipe adalah versi berikutnya. Melebarkan `enum` yang dipakai konsumen untuk bercabang juga termasuk.

**Kedua sisi bergerak bersama.** Kontrak penerbit ada di repo Core, kontrak penerima di sini. Perubahan pada satu belum selesai sampai yang lain menyusul dalam pekerjaan yang sama.

## Yang belum dikontrakkan, dan itu disengaja

Tiga rute `permintaan-pembelian-aset/{id}` belum masuk kontrak karena perilaku yang akan dijanjikannya belum diputuskan. Kontrak yang mendahului keputusan menggambarkan bentuk yang tidak bisa diandalkan pemanggil.

Statusnya tercatat di `DEFERRED` dan dicetak tiap kali pemeriksa jalan.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `contracts/src/` | Sumber yang disunting |
| `contracts/bundle.py` | Penggabung |
| `contracts/check-contract-coverage.py` | Pemeriksa cakupan |
| `.github/workflows/contracts.yml` | Penjalan keduanya di CI |

## Halaman terkait

- [API dan integrasi](/dev/04-api-and-integration) — aturan platform untuk kontrak dan event
- [Integrasi dengan Core](/apps/management-aset/arsitektur/integrasi-core) — apa saja yang melewati batas modul
