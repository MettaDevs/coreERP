# Pelaporan kesalahan

Ketika sesuatu gagal di CoreERP, dua hal terjadi tanpa perlu diminta: sebuah laporan lengkap
ditulis ke berkas di mesin, dan laporan yang sama dikirim ke SigNoz sebagai catatan
terstruktur. Halaman ini menjelaskan apa isinya, ke mana perginya, dan bagaimana mematikannya.

## Apa yang dilaporkan

Satu kesalahan menghasilkan satu blok seperti ini:

```
🔴 CoreERP · KESALAHAN INTERNAL
────────────────────────────────────────────────────────────
POST http://erp.test/management-aset/entitas   ·   500   ·   10.10.0.14
2026-09-11 08:03:51 +07:00
tenant   : Apotek Sejahtera (apotek-sejahtera / 01J8…)
pengguna : Budi Santoso (01J9…)
module   : management-aset
entitas  : 01J8…
unit     : 01J8…
app      : -
rute     : management-aset.layar
korelasi : jejak 4bf92f3577b34da6 · alur 01K3…
────────────────────────────────────────────────────────────
Illuminate\Database\QueryException
SQLSTATE[22001]: String data, right truncated …
/repo/apps/core/app/Http/Controllers/…:118
────────────────────────────────────────────────────────────
pgsql · core_erp @ core-db:5432 (write) · SQLSTATE 22001
value too long for type character varying(8)

SQL:
insert into "uji" ("kode", "kode_satuan") values ('AST-1', 'Vial + Ampul Pelarut')

Nilai yang tidak muat (batas kolom 8 karakter):
  → kode_satuan              20 karakter   Vial + Ampul Pelarut
    kode                      5 karakter   AST-1
```

Field yang tidak tersedia ditandai `-`, bukan dihilangkan. Pembaca laporan perlu bisa
membedakan "permintaan ini memang tidak punya tenant" dari "bagian ini lupa ditulis".

### Bagian database

Muncul hanya untuk `QueryException`, dan mencari `QueryException` sampai ke dalam rantai
`getPrevious()` — kegagalan database sering sudah dibungkus lapisan lain sebelum sampai ke
penangan.

Pesan driver dan pesan `QueryException` **ditampilkan terpisah**. Yang kedua adalah yang
pertama dengan SQL ditempel di belakangnya, dan menempelkannya membuat kalimat yang
sebenarnya menjelaskan — *"value too long for type character varying(8)"* — tenggelam.

### Tersangka pemotongan

Kesalahan "nilai tidak muat" adalah satu-satunya jenis yang pesannya tidak pernah menyebut
nilai penyebabnya. Driver hanya berkata data akan terpotong; kolom mana dan nilai mana tidak
ikut. Pada `insert` dengan delapan belas kolom, itu berarti seseorang harus menghitung tanda
tanya satu per satu sambil mencocokkannya dengan daftar binding.

`TersangkaPemotongan` mengerjakan penghitungan itu sekali, saat kejadiannya masih segar.
Nama kolom ditarik dari query, dipasangkan dengan binding pada urutan yang sama, lalu
diurutkan. Kalau pesan driver menyebut batas kolom — PostgreSQL menyebutkannya, SQL Server
lewat ODBC tidak — nilai yang melebihi batas ditandai `→`.

Urutannya **bukan** sekadar dari yang terpanjang. Kolom `text` berisi 320 karakter selalu
kalah panjang dari apa pun, tetapi sama sekali sehat; yang gagal bisa jadi `varchar(8)`
berisi 20. Ketika batasnya diketahui, yang paling dekat di atas batas naik lebih dulu.

Ini tetap **dugaan**, dan ada satu bentuk kegagalan yang sudah terukur: batas yang dipakai
membandingkan hanyalah batas yang disebut pesan driver — satu angka untuk seluruh baris.
Kolom yang memang lebih lebar dari angka itu ikut terdaftar sebagai tersangka meski sehat.
Diuji pada `insert` ke `aset_m_buku_penyusutan` dengan `posting_layer varchar(20)` sebagai
penyebab sungguhan: dua kolom ULID `varchar(26)` ikut terdaftar dan menempati urutan di
atasnya, sehingga penyebabnya muncul ketiga, bukan pertama.

Daftarnya tetap jauh lebih cepat dibaca daripada menghitung tanda tanya, tetapi jangan
perlakukan urutan teratas sebagai jawaban. Memperbaikinya menuntut batas **per kolom**, dan
itu berarti membaca skema — bertanya ke database dari dalam penangan kesalahan, harga yang
belum sepadan.

## Ke mana perginya

| Tujuan | Selalu ada? | Kegunaannya |
|---|---|---|
| `storage/logs/kesalahan-internal-<peran>-<tanggal>.log` | Ya | Tidak butuh jaringan, tidak butuh collector, tidak butuh izin keluar dari mesin pelanggan |
| SigNoz, sebagai catatan OTLP | Hanya bila telemetri menyala | Bisa dicari, disaring per tenant, dan diklik menuju jejak permintaannya |
| Satu channel Discord, lewat webhook | Hanya bila webhooknya diisi | Mendatangi orang alih-alih menunggu dibuka |

Yang pertama menjamin laporan selalu ada, yang kedua membuatnya berguna, yang ketiga
memberitahu. Ketiganya punya penjaga sendiri, jadi collector yang mati tidak ikut menghapus
berkasnya dan Discord yang diblokir tidak ikut menghapus keduanya.

### Discord

Kenapa ada sama sekali: berkas dan SigNoz hanya menjawab pertanyaan yang sudah diajukan.
Keduanya diam sempurna selama belum ada yang curiga dan membuka.

Tiga hal yang menentukan bentuknya, dan ketiganya sudah menjadi test di
`PengirimDiscordTest`:

- **Sebutan harus berada di `content`.** Discord tidak pernah menerbitkan notifikasi untuk
  sebutan yang ditulis di dalam embed — di sana ia tampil biru, bisa diklik, dan tidak
  membunyikan apa pun. Karena itu pesannya terbelah: ringkasan beserta sebutannya di
  `content`, laporan utuh di embed.
- **`allowed_mentions` dinyatakan dari konfigurasi, bukan disimpulkan dari isi pesan.** Pesan
  kesalahan memuat data pengguna; sebuah nilai yang kebetulan berbunyi `@everyone` tidak boleh
  berubah menjadi sebutan sungguhan hanya karena ia gagal divalidasi.
- **Ada penjeda per kesalahan** (`PenjedaKiriman`, bawaannya 60 detik). Satu kali membuka
  halaman daftar sudah menghasilkan dua kegagalan dengan sebab yang sama; database yang mati
  menghasilkan ratusan. Dengan `@everyone` dan tanpa penjeda, yang sampai ke tim bukan
  peringatan melainkan alasan untuk mematikan notifikasi channel itu — dan peringatan yang
  dimatikan tidak lebih berguna daripada peringatan yang tidak pernah dikirim.

Bentuk pesannya sendiri berbeda dari yang ditulis ke berkas, dan bedanya disengaja:

- **Garis pemisah dibuang.** Di berkas ia menandai batas antar laporan yang ditulis
  sambung-menyambung; satu pesan Discord sudah menjadi batasnya sendiri.
- **Penanda `(dipotong)` dibuang.** Yang dibutuhkan pembaca bukan pemberitahuan bahwa ada yang
  hilang — ia sudah bisa melihatnya — melainkan jalan menuju yang utuh.
- **Selalu ada tautan ke catatannya sendiri di SigNoz**, selama `COREERP_SIGNOZ_URL` diisi —
  bukan ke penjelajah yang kosong. Tautannya di luar blok kode, karena di dalamnya tidak bisa
  diklik. Bila laporannya punya `trace_id`, menyusul tautan kedua ke jejak permintaannya.

### `coreerp.laporan_id`

Satu ULID dicetak per laporan, lalu muncul di tiga tempat: baris `laporan` pada blok teks,
atribut pada catatan OTLP, dan saringan di dalam tautan Discord.

Alasannya `trace_id` tidak cukup. Ia tidak ada pada perintah artisan maupun pekerja antrean —
dua tempat yang kesalahannya paling sering luput diperhatikan, dan karena itu paling butuh
tautan yang mendarat tepat. Tanpa id, yang tersisa bagi keduanya adalah mencocokkan potongan
teks dengan mata.

Bentuk tautannya:

```
{COREERP_SIGNOZ_URL}/logs/logs-explorer?relativeTime=1d&compositeQuery=<JSON tersandi>
```

dengan `filter.expression` berisi `coreerp.laporan_id = '<ulid>'`. Rentangnya sehari, bukan
setengah jam seperti bawaan penjelajah: tautan ini dibuka ketika seseorang sempat membacanya,
dan rentang bawaan akan menyajikan halaman kosong yang terlihat seperti catatannya tidak
pernah sampai.

**`compositeQuery` adalah urusan dalam SigNoz, bukan antarmuka yang dijanjikan.** Diperiksa
langsung pada v0.141.1 dan bisa berubah pada versi berikutnya. Kalau suatu saat tautannya
membuka penjelajah tanpa saringan, `PengirimDiscord::tautanSigNoz()` adalah tempat
memperbaikinya — dan sementara itu tidak ada yang rusak selain kenyamanan.

Penjedanya memakai berkas di `storage/logs/.penjeda-kiriman`, bukan `Cache::`. Penyimpanan
cache di sini adalah tabel di database yang sama dengan aplikasinya, dan keadaan yang paling
butuh dijeda justru keadaan ketika database tidak bisa ditanya. Sidik jarinya jenis exception
beserta baris tempat ia dilempar — sengaja tumpul, karena pesan `QueryException` memuat nilai
binding dan penjeda yang memakai pesan tidak akan pernah menjeda apa pun.

### Ini bukan pengganti alert SigNoz

Keduanya menjawab pertanyaan yang berbeda, dan sebaiknya dipakai berdampingan:

| | Discord dari Laravel | Notification channel SigNoz |
|---|---|---|
| Pemicu | Satu kesalahan, seketika | Aturan atas agregat, dievaluasi berkala |
| Isi | Laporan utuh: SQL, tenant, pengguna, module | Nama alert, label, dan angka ambangnya |
| Cocok untuk | "Ada yang rusak, ini persisnya" | "Kesalahan melonjak", "layanan diam" |

SigNoz tidak punya tipe Discord pada daftar channelnya. Jalurnya adalah memilih tipe **Slack**
lalu menambahkan `/slack` di ujung URL webhook Discord — Discord menerima muatan berformat
Slack pada alamat itu. Belum dipasang; lihat "Yang belum dikerjakan".

### Nama berkas dibedakan per peran

`web`, `worker`, dan `scheduler` menjalankan image yang sama di atas volume yang sama. Kalau
ketiganya menulis ke satu berkas, tulisan mereka berselang-seling dan satu blok laporan yang
terdiri dari belasan baris tercabik di tengah. Satu volume, tiga nama berkas.

### Volume `core-logs`

Tanpa volume, `storage/logs` hidup di lapisan tulis container dan lenyap setiap kali
container diganti — termasuk pada setiap upgrade. Volume `core-logs` ada di
`deploy/compose.edition.yaml` dan `erp-dev/compose.yaml` untuk ketiga peran.

## Menyalakan dan mematikan

Semua variabel OpenTelemetry **harus** berupa environment proses, bukan `.env` Laravel: SDK
menyalakan diri saat autoload Composer, sebelum Laravel membaca `.env` sama sekali. Menulisnya
di `.env` membuatnya *terlihat* dikonfigurasi padahal tidak pernah terbaca.

| Variabel | Dev | On-prem | Arti |
|---|---|---|---|
| `OTEL_PHP_AUTOLOAD_ENABLED` | `true` | `false` | Saklar utama. Mati berarti tidak ada trace dan tidak ada catatan yang dikirim |
| `OTEL_LOGS_EXPORTER` | `otlp` | `otlp` | Tujuan catatan. Tidak berpengaruh selama saklar utama mati |
| `OTEL_PHP_DISABLED_INSTRUMENTATIONS` | `psr3` | `psr3` | Lihat catatan di bawah |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | alamat SigNoz | **wajib diisi sendiri** | Kosong secara bawaan, dan itu disengaja — lihat di bawah |

Berkas log ditulis tanpa bergantung pada satu pun dari variabel di atas.

### Kenapa alamat kolektor kosong di on-prem, bukan diisi alamat kami

Sampai 13 September 2026, `deploy/compose.edition.yaml` membawa alamat kolektor **milik vendor**
sebagai nilai bawaan. Saklar utama memang mati, jadi tidak ada yang pernah terkirim — tetapi
menyalakannya cuma satu baris, dan pelanggan yang menyalakannya tanpa menyebut alamat sendiri akan
mengirimkan trace serta catatannya ke mesin kami. Trace memuat pernyataan SQL, URL, dan id tenant.

Untuk pelanggan healthcare itu data yang **tidak boleh kami terima**, bukan sekadar tidak ingin. Dan
ia bertentangan dengan aturan yang ditulis di `AGENTS.md`: on-prem perpetual berdiri sendiri, tanpa
telemetry wajib — bawaan yang menelepon rumah begitu dinyalakan bukan "berdiri sendiri".

Kosong berarti pemasang yang menyalakan telemetry **wajib** menyebut tujuannya. Kalau ia lupa,
exporter-nya gagal berisik di mesinnya sendiri, dan gagal berisik jauh lebih baik daripada berhasil
diam-diam ke tempat yang salah.

Aturan umumnya, dan ia berlaku di luar telemetry: **alamat lingkungan tertentu tidak pernah menjadi
nilai bawaan di dalam artefak.** Artefak yang memuat fakta spesifik-lingkungan berhenti dapat
dipromosikan — image yang diuji bukan lagi image yang dikirim.

Pengiriman Discord berdiri sendiri dan **tidak** terikat saklar OpenTelemetry. Ketiganya
adalah `.env` Laravel biasa, karena yang membacanya Laravel dan bukan SDK:

| Variabel | Bawaan | Arti |
|---|---|---|
| `COREERP_DISCORD_WEBHOOK_URL` | kosong | Kosong berarti mati. Itu bawaan yang benar untuk on-prem: channelnya milik pelanggan |
| `COREERP_DISCORD_MENTION` | kosong | `@everyone`, `@here`, `<@id_orang>`, `<@&id_role>`, atau gabungannya. `@nama` biasa tidak menyebut siapa pun |
| `COREERP_DISCORD_JEDA_DETIK` | `60` | Jeda minimal antara dua kiriman untuk kesalahan yang sama. Nol mematikan penjeda |
| `COREERP_SIGNOZ_URL` | kosong | Alamat antarmuka SigNoz, bukan porta OTLP. Kosong berarti laporannya tanpa tautan |

### Kenapa `psr3` dimatikan

`opentelemetry-auto-laravel` memasang `LogWatcher` yang meneruskan setiap panggilan `Log::`
menjadi catatan OTLP; `opentelemetry-auto-psr3` melakukan hal yang sama lewat antarmuka
PSR-3. Membiarkan keduanya hidup berarti setiap baris log terkirim dua kali.

Ini juga alasan laporan kesalahan **tidak** ditulis lewat `Log::` melainkan langsung ke
berkas (`BerkasLaporan`). Sebelum diperbaiki, satu kesalahan tiba di SigNoz sebagai tiga
catatan: log bawaan Laravel, laporan yang dikirim sengaja, dan salinan laporan itu lagi tanpa
atribut apa pun sehingga tidak bisa disaring.

Menulis langsung juga memberi sifat yang pantas dimiliki alat diagnostik: ia tetap bekerja
ketika konfigurasi log sendiri yang rusak.

## Correlation id — dua, bukan satu

Laporan membawa keduanya karena umurnya berbeda dan tidak bisa saling gantikan:

- **`trace_id`** hidup satu permintaan. Ia yang menyambungkan laporan ke jejaknya di SigNoz —
  nama fieldnya harus persis `trace_id` dan `span_id` supaya SigNoz mengenalinya.
- **`correlation_id`** hidup berhari-hari. Dipakai alur kerja yang event keputusannya terbit
  belakangan (`InternalWorkflowInstanceController`, `WorkflowRuntime`).

Laporan hanya **membaca** keduanya. Ia tidak pernah mencetak correlation id sendiri: id baru
yang tidak berhubungan dengan apa pun akan menyesatkan orang pertama yang mencarinya.

Menyatukan keduanya adalah pekerjaan `LIFE-14`, dan sengaja belum dikerjakan.

## Aturan untuk kode telemetri

Satu kalimat, dan ia berlaku untuk `JejakAktif`, `LaporanKesalahan`, `PelaporKesalahan`,
`BerkasLaporan`, dan `SqlTerbaca`:

> **Tidak ada jalur yang boleh melempar.**

Kode ini berjalan setelah sesuatu sudah gagal. Lemparan kedua dari sini menimpa kesalahan
asli dengan kesalahan tentang pelaporan kesalahan — dan yang hilang justru satu-satunya
keterangan tentang apa yang sebenarnya terjadi.

Yang mengikutinya:

- Seluruh badan dibungkus `catch (Throwable) {}`.
- **Penjaga masuk-ulang** di `PelaporKesalahan`. Tanpa itu, pelapor yang gagal menulis akan
  dilaporkan lagi oleh penangan, berputar sampai memori habis — dan paling mungkin terjadi
  ketika database atau disk bermasalah, yaitu ketika laporan paling dibutuhkan.
- **`CurrentWorkspace` tidak disentuh ketika kesalahannya menyangkut database.** Method itu
  menjalankan query *dan* menulis sesi. Menanyakan pada database kenapa database mati adalah
  cara satu kesalahan berubah menjadi dua.
- **`QueryException::getRawSql()` tidak dipakai.** Ia menyelesaikan koneksi lewat container,
  untuk kesalahan yang mungkin justru kegagalan koneksi. `SqlTerbaca` menggantikannya tanpa
  I/O apa pun.
- **`SqlTerbaca` menolak menebak.** Kalau jumlah tanda tanya tidak sama dengan jumlah binding,
  ia mengembalikan `null` dan nilainya ditampilkan terpisah. Query hasil rekonstruksi yang
  salah tetapi tampak yakin mengirim orang menelusuri baris data yang tidak pernah terlibat.

## Yang tidak dilaporkan

Kesalahan HTTP 4xx — 404, 419, 422. Semuanya bagian normal dari lalu lintas, dan
membiarkannya masuk mengubur laporan yang berarti. Laporan yang tidak pernah dibaca sama
nilainya dengan laporan yang tidak pernah ditulis.

## Di luar permintaan HTTP

Perintah artisan dan pekerja antrean adalah tempat kesalahan paling mudah luput — tidak ada
yang menatap layar ketika mereka gagal. Laporan mereka membawa dua hal yang bisa diketahui
**tanpa menyentuh database**:

| Baris | Sumber |
|---|---|
| `perintah` | `$_SERVER['argv']`, tanpa nama berkasnya |
| `tenant` | Ikatan container `coreerp.module.tenant_id`, yang sama dengan yang dibaca `TenantScope` |

Ikatan tenant itu bukan tebakan: tanpanya query module tidak berjalan sama sekali, jadi setiap
pekerjaan yang menyentuh data sebuah tenant pasti memilikinya.

Yang **tidak** ikut adalah nama tenantnya. Untuk itu perlu bertanya ke database, dan laporan
ini sering berjalan justru ketika database yang gagal. Pada jalur HTTP nama itu ada karena
middleware sudah memegang objeknya di memori sebelum kegagalan terjadi; di luar HTTP tidak ada
yang setara.

Batas keduanya jujur: pekerjaan yang tidak pernah mengikat tenant — perintah lintas tenant,
migrasi, atau pekerjaan yang gagal sebelum sempat mengikat — melaporkan `-`. Itu keadaan
sebenarnya, bukan kegagalan membaca.

## Yang belum dikerjakan

- **Alert SigNoz ke Discord.** Tipe Slack dengan `/slack` di ujung URL webhook; lihat tabel
  perbandingan di atas untuk kapan itu yang dibutuhkan dan kapan bukan.
- **Pengiriman di luar permintaan.** Kiriman Discord berjalan segaris dengan penangan
  kesalahan, dengan batas waktu 2 detik sambung dan 4 detik total. Itu pilihan sadar: antrean
  memakai database yang sama, jadi jalur yang lewat antrean akan diam persis pada kegagalan
  yang paling perlu diberitahukan. Kalau suatu saat antreannya pindah ke Redis, jalur ini
  layak ditinjau ulang.

## CI tidak menyentuh OpenTelemetry

Ekstensi PECL `opentelemetry` hanya ada di image runtime, tidak di runner mana pun. Dua hal
yang membuatnya tidak menggagalkan CI:

1. `--ignore-platform-req=ext-opentelemetry` pada `composer install` — dua paket
   auto-instrumentation mendeklarasikannya sebagai platform requirement keras.
2. `OTEL_PHP_DISABLED_INSTRUMENTATIONS: all` sebagai env job. Ini bukan sekadar meredam:
   `_register.php` memeriksa `Sdk::isInstrumentationDisabled()` **sebelum** memeriksa
   ekstensi, lalu keluar lebih awal — jadi `trigger_error(E_USER_WARNING)` tidak pernah
   tercapai. Peringatan itu bukan sekadar berisik: selama `post-autoload-dump`, penangan error
   Composer mengubahnya menjadi exception.

Env yang sama juga mendiamkan peringatan di mesin pengembang.

`edition.yml` membangun image dengan `PASANG_OTEL=0` sehingga kompilasi PECL dilewati; ia
membangun hanya untuk memverifikasi pemangkasan module lalu membuang hasilnya. `release.yml`
tidak menyetelnya, jadi image yang benar-benar dikirim selalu membawa ekstensinya.
Konsekuensinya disadari: image yang diverifikasi saat PR bukan image yang dikirim.

## Berkas terkait

| Berkas | Isi |
|---|---|
| `app/Support/Observabilitas/PelaporKesalahan.php` | Pintu masuk, penjaga, tiga tujuan |
| `app/Support/Observabilitas/PengirimDiscord.php` | Muatan webhook, sebutan, dan izinnya |
| `app/Support/Observabilitas/PenjedaKiriman.php` | Penjeda per kesalahan, berbasis berkas |
| `app/Support/Observabilitas/LaporanKesalahan.php` | Pengumpul konteks dan perender |
| `app/Support/Observabilitas/BerkasLaporan.php` | Penulisan berkas, rotasi per hari dan peran |
| `app/Support/Observabilitas/SqlTerbaca.php` | Penyisipan binding tanpa I/O |
| `app/Support/Observabilitas/TersangkaPemotongan.php` | Penunjuk nilai yang tidak muat |
| `app/Support/Observabilitas/JejakAktif.php` | Satu-satunya penyentuh span aktif |
| `resources/js/lib/pelaporan-kesalahan.ts` | Padanannya di peramban |
| `tests/Feature/Observabilitas/` | Penjaga untuk semua sifat di atas |
