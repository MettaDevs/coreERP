# Dokumen cetak, layout, dan ekspor

Cara platform menghasilkan PDF, Word, dan Excel dari data app: work order, purchase order, daftar untuk dianalisis, dan dokumen lain yang dibawa ke lapangan atau dikirim ke pihak luar. Halaman ini aturan platformnya; contoh jadinya ada di [Laporan dan ekspor Management Aset](../apps/management-aset/transaction/laporan/index.md).

## Model yang ditiru: Business Central, bukan F&O

Dynamics 365 menawarkan dua model. Finance & Operations memakai Electronic Reporting: data model abstrak, model mapping, format configuration berversi, dan Business Document Management yang menuntut SharePoint dan Office 365 untuk menyunting template. Business Central memakai model yang jauh lebih kecil: satu **report** adalah dataset yang ditentukan developer, dan dataset itu dipakai banyak **layout** Word/Excel yang boleh diubah pengguna tanpa deploy.

CoreERP mengambil model Business Central, dengan satu ide dari F&O: dataset adalah kontrak yang stabil, bukan tabel mentah. Alasannya:

- Skala platform ini sepadan dengan BC. ER adalah framework tersendiri yang butuh tim untuk merawatnya.
- On-prem tidak boleh bergantung pada Office 365. Pengguna menyunting `.docx` di Word lokal lalu mengunggahnya; tidak ada integrasi cloud yang wajib.
- Yang berbeda antar customer adalah tampilan, bukan data. Dengan dataset yang sama, customer A mengunggah layout A dan customer B layout B, dan developer tidak menulis ulang apa pun.

## Core memegang mesinnya, app memegang datanya

Ini keputusan yang paling menentukan, dan alasannya bertumpuk seiring jumlah app: finance, HR, procurement, cost control, inventory, healthcare, POS, dan kasir. Bila mesin cetak disalin ke setiap app, delapan app berarti delapan salinan yang perlahan saling menyimpang, delapan worker, dan delapan halaman pengaturan layout untuk satu admin tenant.

Karena itu mesinnya milik Core, seperti Number Sequence, Workflow, dan Fiscal Calendar. App hanya menyerahkan datanya.

| Lapis | Padanan BC | Pemilik | Di mana |
| --- | --- | --- | --- |
| Dataset | Report dataset | Developer module | Kelas definisi laporan di module, diserahkan lewat kontrak `PenyediaLaporanModul` di dalam proses |
| Katalog laporan | Report object | Developer module | Dibaca dari kelas dataset lewat `PenyediaLaporanModul::catalog()` saat `app:register-manifest`, disalin ke tabel `app_reports` Core |
| Layout bawaan | Extension layout | Release app | Berkas `.docx`/`.xlsx` di app, dibaca Core lewat kontrak yang sama dan disimpan per versi release |
| Layout unggahan | User-defined layout | Tenant | Tabel `report_layouts` Core, ber-`tenant_id`, opsional `legal_entity_id` |
| Layout default | Report Selections + Document Layouts | Tenant per legal entity | Tabel `report_layout_defaults` Core; legal entity mengalahkan tenant |
| Antrean ekspor dan hasilnya | Report scheduling | Core | Tabel `report_exports` dan disk `reporting`; dikerjakan `core-worker` |
| Engine render PDF | Report rendering | Platform | Container `core-renderer` (Gotenberg + LibreOffice), stateless |
| Dialog cetak, tray, lonceng, halaman layout dan riwayat | Request page, Report Layouts | Shell | Komponen di Control Plane. Module hanya meminta, dengan mengirim `CustomEvent('coreerp:print')` pada `window` |
| Identitas cetak (nama kop, baris induk, footer, logo) | Company Information | Tenant per legal entity atau operating unit | Tabel `print_identities` Core, digabung alamat dan kontak dari buku alamat, disuntikkan ke dataset sebagai `kop.*` |

Dua batas yang mengikat semua app:

**Core tidak pernah membaca data app sendiri.** Ia selalu meminta dataset kepada app, dan app yang menegakkan permission serta scope organisasi pengguna itu persis seperti pada layar biasa, lalu menyerahkan data yang sudah disaring. Ini konsekuensi langsung [boundary platform](01-grand-design.md): Core boleh memanggil app dan app boleh memanggil Core, tetapi app tidak saling memanggil.

Bentuk permintaannya bergantung pada tempat app berjalan:

- **Module** mendaftarkan `PenyediaLaporanModul` ke `DaftarLaporan` sekali saat boot, dan Core memanggilnya sebagai fungsi. Konteks pengguna dibawa **sebagai argumen**, bukan dibaca dari permintaan — ekspor berjalan di worker antrean, tempat tidak ada `Request` maupun sesi, dan keadaan global yang benar pada permintaan biasa tetapi kosong pada worker adalah persis kegagalan yang paling sulit ditemukan. Isi konteksnya sama dengan yang dulu dibawa token: tenant, entitas legal, unit kerja, id pengguna, permission efektif, dan lingkup kebijakan data.

Pemeriksaan izin tetap terjadi dua kali pada kedua bentuk, dan itu bukan pemeriksaan ganda yang mubazir: Core memeriksa "boleh menjalankan laporan ini", app memeriksa "boleh membaca data yang dilaporkan".

**Engine render bukan business module.** Ia tidak menyimpan apa pun, tidak mengenal tenant, dan hanya mengubah satu berkas Office yang sudah diisi menjadi PDF. Kelasnya sama dengan PostgreSQL: infrastruktur yang dibawa Core, dipakai bersama semua app dan semua tenant pada satu deployment, dan ikut bundle on-prem sebagai bagian Core.

## Apa yang ditulis tim app untuk satu laporan

Tidak ada framework yang dibangun ulang. Untuk satu laporan:

1. Satu kelas dataset: kode, nama, keterangan, permission datanya, aturan parameter, layout bawaannya, daftar placeholder, dan query yang memakai scope organisasi yang sama dengan endpoint detailnya.
2. Satu layout bawaan `.docx` atau `.xlsx`, dibangkitkan dari kode lewat command supaya perubahannya terbaca di review.
3. Cara Core mencapainya: module mendaftarkan `PenyediaLaporanModul` dari penyedia layanannya.
4. Tombol Cetak pada halaman record yang meminta Shell mencetak.

**Tidak ada blok manifest.** Sampai 28 September 2026 laporan juga ditulis di blok `reports` manifest,
dan dua sumber itu menyimpang: laporan yang terlewat di manifest tampil di pratinjau, lalu menjawab
404 saat dicetak. Sekarang `app:register-manifest` membaca katalognya dari kelas dataset lewat
`PenyediaLaporanModul::catalog()`: kode berawalan id module, nama, keterangan, permission, nama
parameter (kunci aturan parameternya), dan layout bawaan. Kelas dataset menjadi satu-satunya
sumber, sama seperti objek report di Business Central. Manifest module yang masih memuat blok
`reports` ditolak.

Empat hal yang diminta Core:

| Yang diminta | Guna |
| --- | --- |
| Katalog | Kode, nama, permission, parameter, dan layout bawaan setiap laporan, dibaca saat registrasi |
| Definisi | Placeholder, parameter, layout bawaan |
| Berkas layout bawaan | Isi `.docx`/`.xlsx` yang ikut rilis |
| Dataset | Data yang sudah disaring; kegagalan disampaikan sebagai pesan siap-baca bila record tidak ada atau di luar scope |

Keempatnya adalah method pada `PenyediaLaporanModul`. Sampai 10 September 2026 ada bentuk kedua
— `GET internal/v1/laporan/{kode}`, `GET internal/v1/laporan/{kode}/layouts/{key}`, dan
`POST internal/v1/laporan/{kode}/dataset` — untuk app yang berjalan sebagai container tersendiri. Ia
dibuang bersama app berkontainer terakhir.

## Identitas cetak: kop dan footer bukan bagian layout

Business Central menyimpan nama, alamat, dan logo perusahaan di Company Information; layout hanya memanggilnya. CoreERP meniru itu per legal entity sebagai **Identitas cetak** pada kartu organisasi (`settings/organization`), dengan dua perbedaan.

Pertama, logo adalah daftar berposisi (kiri, tengah, kanan, sampai empat), bukan satu gambar, karena kop instansi pemerintah memuat lambang daerah di kiri dan logo instansi di kanan. BC dan F&O menyerah pada logo kedua dan menanamnya di template; di sini ia tetap data.

Kedua, **alamat dan kontak bukan milik identitas cetak.** Keduanya hidup di [buku alamat](24-global-address-book.md) organisasi: bagian Alamat Utama & Cabang dan Informasi Kontak pada kartu yang sama, seperti Addresses dan Contact information pada legal entity Dynamics 365. Identitas cetak hanya menyimpan yang memang khas kop: nama pada kop bila berbeda dari nama organisasi, baris induk, NPWP dan NIB, teks footer, dan logo. Saat kop disusun, alamat utama menjadi `kop.alamat*` dan kontak utama tiap jenis menjadi `kop.telepon`, `kop.whatsapp`, `kop.fax`, `kop.email`, `kop.laman`. Versi pertama fitur ini menyimpan alamat dan telepon di tabel identitas; itu dibongkar karena satu alamat kantor akan diubah di dua tempat dan cepat atau lambat keduanya berbeda.

Core menyuntikkan identitas ke setiap dataset sebagai placeholder `kop.*` sebelum render: `kop.nama`, `kop.induk` (baris di atas nama, misalnya "PEMERINTAH KABUPATEN BADUNG"), `kop.alamat`, `kop.telepon`, `kop.email`, `kop.laman`, `kop.npwp`, `kop.nib`, `kop.footer`, dan gambar `kop.logo_kiri`, `kop.logo_tengah`, `kop.logo_kanan`, `kop.logo_1` sampai `kop.logo_4`. Layout bawaan setiap laporan memakai satu blok kop tiga kolom yang sama, sehingga satu logo swasta, dua logo instansi, atau tanpa logo sama-sama rapi tanpa template per kasus.

Akibatnya mengganti logo, alamat, atau teks footer tidak pernah menyentuh layout; unggah layout menjadi jalur langka untuk bentuk dokumen yang memang berbeda. Operating unit boleh punya identitas sendiri (puskesmas di bawah dinas): saat mencetak, identitas yang paling spesifik dipakai. Logo hanya PNG atau JPEG, karena keduanya yang dimengerti Word, Excel, dan LibreOffice tanpa konversi dan tidak dapat membawa skrip.

## Layout adalah data tenant, bukan security object

Tenant boleh mengunggah, mengganti, dan menghapus layout tanpa menyentuh manifest, sama seperti ia boleh mengubah kop surat. Ini berbeda dari entry point dan permission yang [dideklarasikan manifest dan tidak dapat dibuat tenant](09-identity-and-access.md). Yang dikunci hanya kodenya: kode laporan adalah kontrak yang dipakai layout tersimpan dan riwayat ekspor, sehingga mengganti kode berarti memutus layout yang sudah diunggah tenant.

Layout bawaan tidak pernah disunting di tempat. Pengguna mengunduhnya, mengubahnya, lalu mengunggah sebagai layout baru. Core menyimpan salinan layout bawaan per versi release app, jadi upgrade app otomatis memperbarui layout bawaan tanpa menimpa milik tenant.

"Available in All Companies" di BC menjadi lingkup tenant di sini; company BC adalah legal entity CoreERP. Layout milik legal entity lain tidak ditawarkan kepada pengguna yang bekerja di legal entity aktif.

Hak mengelola layout adalah hak admin tenant (`manage-report-layouts`, owner atau admin), terpisah dari hak mencetak yang mengikuti permission data laporan. Tenant dapat memberi hak cetak kepada teknisi tanpa memberi hak mengganti kop surat perusahaan.

## Cara layout membaca dataset

Placeholder `${kode}` mengambil satu nilai. Baris tabel Word atau baris lembar Excel yang memuat `${baris.asset_kode}` digandakan per baris tabel `baris` pada dataset, dan setiap placeholder berawalan `baris.` pada baris itu diisi per baris. Daftar placeholder dibaca Core dari app dan ditampilkan pada halaman Layout laporan, padanan Available Fields di BC.

Aturan yang dijaga engine di Core, dan alasannya:

- **Uang, angka, persen, tanggal, dan bulan dikirim mentah dan diformat Core.** Placeholder yang menyatakan `type` pada `fields()` — `money`, `number`, `percent`, `date`, `month`, dan `datetime` untuk waktu — dikirim dataset sebagai nilai asli: uang dan angka sebagai angka, persen `12.5`, tanggal `2026-07-23`, bulan `2026-07`. Core yang memformatnya per keluaran: Word, PDF, dan layar pratinjau mendapat teks (`Rp 20.000.000,00`, `2,5`, `12,5%`, `23/07/2026`, `Juli 2026`), Excel mendapat angka atau tanggal asli dengan format selnya, supaya kolom uang dapat dijumlah dan tanggal dapat diurutkan. Presisi uang dibaca dari setelan mata uang tenant, sumber yang sama dengan pembulatan jurnal. Ini padanan `AutoFormatType` pada kolom laporan Business Central: laporan hanya menyatakan jenis nilainya, platform yang memutuskan presisi dan simbolnya. Sebelumnya setiap definisi memformat sendiri; dalam satu module sudah ada dua aturan rupiah, dan uang yang dikirim sebagai teks tidak dapat dijumlah di Excel.
- **Waktu dikirim dalam UTC dan ditulis menurut zona pengguna yang mencetak.** Placeholder `datetime` dikirim sebagai waktu UTC (`2026-09-27 17:30:00` atau ISO 8601 `2026-09-27T17:30:00Z`). Core menulisnya menurut zona waktu pengguna beserta nama zonanya: "28/09/2026 00:30 WIB" di Word, PDF, dan pratinjau; di Excel sebagai tanggal-jam dengan nama zona di format selnya, karena Excel tidak mengenal zona. Zona Indonesia ditulis WIB, WITA, atau WIT, zona lain selisihnya dari UTC ("UTC+09:00"). Zonanya ikut konteks laporan sebagai `timezone`, dihitung dari setelan pengguna di My Profile atau entitas legalnya, sehingga ekspor di worker tanpa sesi tetap memakai zona yang sama dengan layar. Module memakai zona yang sama untuk "hari ini" miliknya: periode bawaan dan nama berkas. Tanggal tanpa jam (`date`) tidak punya zona dan tidak digeser.
- **Nilai tanpa tipe sudah siap tampil saat keluar dari dataset.** Label status dan teks lain diformat di kelas definisi app, bukan di layout, supaya semua layout satu laporan menampilkannya dengan cara yang sama.
- **Placeholder tak dikenal dikosongkan, bukan dibiarkan.** Dokumen yang sampai ke vendor tidak boleh memuat `${...}`. Saat unggah, placeholder yang tidak ada di dataset dilaporkan sebagai peringatan tanpa menolak unggahan; salah ketik ketahuan sebelum dicetak.
- **Dokumen bermakro ditolak saat unggah.** Berkas dibuka engine bersama semua tenant; tidak ada alasan sebuah layout menjalankan kode. Yang diperiksa isi zip-nya, bukan ekstensinya.
- **Rumus di baris template Excel ikut digandakan.** Formula Excel tanpa placeholder pada baris template, misalnya `=D5*E5`, disalin ke setiap baris hasil seperti fill handle Excel: referensi relatif bergeser (`=D6*E6`, `=D7*E7`), referensi absolut seperti `$H$2` tetap, dan rentang di dalam baris itu sendiri (`=SUM(D5:E5)`) tidak ikut diperluas.
- **Rumus di bawah baris template Excel diperluas.** `=SUM(B3:B3)` pada satu baris template menjadi `=SUM(B3:B7)` setelah lima baris hasil, karena Excel sendiri tidak memperluas rentang yang berakhir tepat di baris penyisipan.

## Ekspor dikerjakan di latar belakang

Permintaan cetak dijawab `202` dengan baris ekspor berstatus `queued`, lalu `core-worker` yang meminta dataset ke app, mengisi layout, mengubah format, dan menyimpan berkas. Shell memantau status dan menawarkan unduhan setelah selesai. Pengguna tidak menunggu di layar, dan daftar ribuan baris tidak menahan request sampai timeout.

Akibatnya pada arsitektur:

- **Token ke app diterbitkan ulang saat job berjalan**, dari membership yang meminta, bukan dibekukan saat permintaan dibuat. Pencabutan hak antara "Cetak" dan pengerjaan langsung berlaku; membership yang tidak lagi aktif membuat ekspor gagal dengan pesan yang jelas.
- **Worker Core harus selalu dihidupkan ulang oleh deployment.** `queue:work` keluar sendiri setelah `--max-time` supaya memori bersih; container tanpa kebijakan restart mati diam-diam setelah satu jam dan setiap ekspor berhenti di `queued`. Stack lokal memakai `restart: unless-stopped`; bundle release dan orchestrator harus setara.
- **Worker Core diskalakan dengan menambah replica**, tanpa load balancer: database yang menjamin satu baris antrean hanya diambil satu worker, worker tidak menyimpan apa pun di memori, dan berkas ditulis ke penyimpanan bersama. Seribu orang menekan Cetak bersamaan hanya memanjangkan antrean, bukan menumbangkan API.
- **Web dan worker Core melihat penyimpanan yang sama.** Pada Compose keduanya berbagi volume `core-storage`; pada cloud multi-instance disk `reporting` harus menunjuk object storage.
- **Batas per pengguna dan per ekspor.** Ekspor aktif per pengguna dibatasi, dan dataset melewati batas baris (`reporting.max_rows`) ditolak sebelum render, supaya satu orang atau satu permintaan tidak menguasai worker. Batas baris ini berlaku untuk setiap ekspor **laporan**, termasuk "Excel (data saja)": dataset laporan disusun module di memori sebelum ditulis, apa pun keluarannya. Laporan ribuan halaman adalah pekerjaan [reporting projection](07-reporting-and-replicas.md), bukan mesin cetak. Ekspor daftar di layar tidak memakai batas ini, karena barisnya tidak pernah dikumpulkan di memori; batasnya lembar Excel dan `reporting.list_export_max_csv_rows` (lihat di bawah).
- **Hasilnya diumumkan Shell** lewat tray Ekspor di header, toast, dan lonceng notifikasi. Shell memantau Core hanya selama ada ekspor yang berjalan, lalu berhenti sendiri.
- **Kegagalan tetap tidak diulang, gangguan sesaat diulang terbatas.** Layout yang salah susun, data yang terlalu besar, hak yang dicabut, atau parameter yang ditolak module akan gagal lagi dengan cara yang sama, jadi baris ekspor langsung `failed` dengan pesan yang jelas. Kesalahan tak terduga juga tidak diulang, karena hampir selalu cacat kode. Yang diulang hanya dua:
  - layanan PDF yang terlambat menjawab, menolak sambungan, atau menjawab 5xx, 408, atau 429 (`RenderException::transient()`); 4xx lainnya adalah penolakan atas dokumennya sendiri dan tetap langsung gagal;
  - worker yang mati di tengah ekspor (deploy, restart, kehabisan memori).

  Batasnya `reporting.export_attempts` (bawaan 3, `COREERP_REPORTING_EXPORT_ATTEMPTS`) dengan jeda `reporting.export_retry_seconds` (15 lalu 60 detik), padanan *Maximum No. of Attempts to Run* pada Job Queue Entry BC. Selama menunggu percobaan berikutnya baris kembali `queued` dan `failure_message` memuat catatan seperti "percobaan 2 dari 3"; catatan itu dihapus bila ekspornya lalu berhasil, dan percobaan terakhir yang gagal menulis pesan yang menyebut jumlah percobaannya. Ekspor yang melewati batas waktu job (10 menit) tidak diulang dan ditandai gagal dengan pesan batas waktu.
- **Baris `running` punya masa sewa.** `retry_after` antrean database (90 detik) lebih pendek dari batas waktu job, jadi antrean dapat menyerahkan job yang sama ke worker kedua selagi worker pertama masih bekerja. Baris `running` dianggap masih dipegang sampai `started_at` + batas waktu job + 30 detik: percobaan yang datang lebih cepat mengembalikan job ke antrean sampai sewa habis, dan pengambilalihan adalah satu UPDATE bersyarat. Karena itu satu ekspor tidak dikerjakan dua worker sekaligus, dan ekspor yang ditinggal worker mati tetap diselesaikan. Akibatnya, ekspor yang ditinggal worker mati baru dilanjutkan sekitar sepuluh menit kemudian.

## Opsi terakhir dan preset laporan

Padanan "Last used options and filters" dan setelan laporan bernama (page 1560 *Report Settings*, tabel *Object Options*) di Business Central. Keduanya milik Core, di samping mesin laporannya, dan dibatasi tenant, pengguna, dan kode laporan.

- **Opsi terakhir (K-24).** Tabel `report_last_used_options`, satu baris per pengguna per laporan. Halaman filter laporan module mencatat filter setiap kali pratinjaunya berhasil dimuat, dan permintaan ekspor mencatat filter, format, dan layout yang dipakai (`data` untuk "Excel (data saja)"). Saat laporan dibuka lagi, filternya terisi pilihan terakhir itu, dan dialog cetak memilih layout serta format terakhir selama layout itu masih ada. Yang terakhir menang, tanpa versi baris: ini jejak kebiasaan satu orang, bukan data yang diedit bersama. Simpannya satu `INSERT ... ON CONFLICT` pada indeks unik parsial, supaya dua tab yang menjalankan laporan bersamaan tidak jatuh di indeks itu.
- **Preset (K-25).** Tabel `report_presets`: nama, pemilik, penanda `shared`, dan parameter. Preset pribadi hanya terlihat pemiliknya. Preset bersama terlihat oleh setiap pengguna tenant yang boleh menjalankan laporannya, beserta nama pembuatnya. Mengubah dan mengarsipkan memakai versi baris (`If-Match` atau `version`); arsip mengisi `deleted_at`, dan nama preset yang diarsipkan boleh dipakai lagi. Preset pribadi hanya diubah pemiliknya. **Preset bersama dibuat, diubah, dan diarsipkan pemegang `core.report-preset.update`** (duty *Kelola preset laporan bersama*, dipegang Owner), siapa pun pembuatnya — padanan *Shared with all users* di halaman Report Settings BC, yang juga dijaga izin sendiri, terpisah dari izin layout. `GET .../options` memulangkan `can_share` supaya layar hanya menawarkan pilihan itu kepada pemegangnya. Nama preset pribadi unik per pemilik; nama preset bersama unik per laporan.
- **Tanggal relatif.** Nilai tanggal pada preset boleh berupa token: `@today`, `@this_month.start`/`.end`, `@last_month.start`/`.end`, `@this_year.start`/`.end`, `@last_year.start`/`.end`, dan untuk parameter bulan `@this_month`/`@last_month` (`App\Support\Reporting\RelativeDates`). Yang tersimpan tokennya; Core menerjemahkannya saat preset dipakai, menurut zona pengguna — pukul 00.30 WIB tanggal 1 Oktober, "bulan ini" adalah Oktober walaupun jam server masih 30 September. Permintaan ekspor yang membawa token diterjemahkan saat diminta, supaya ekspor yang menunggu di antrean tidak bergeser hari.
- **Yang disimpan hanya parameter yang dikenal laporan**, berupa teks atau daftar teks. Isinya tidak divalidasi Core: aturannya milik module, dan module memeriksanya setiap kali laporan dijalankan.

Rutenya sesi login, dijaga hak menjalankan laporan (laporan yang tidak boleh dijalankan dijawab 404): `GET /api/v1/reports/{kode}/options`, `PUT .../options/last-used`, `POST .../presets` (dengan `shared: true` untuk preset bersama; tanpa permission-nya dijawab 403), `PATCH` dan `DELETE .../presets/{id}` (preset yang tidak boleh diubah dijawab 404, sama dengan yang tidak ada).

## Filter tambahan pada kolom data item

Padanan "+ Filter" di request page Business Central (K-30). Setiap laporan punya filter tetap dari developer atau konsultan — periode, buku, group, dan sejenisnya — dan pengguna boleh menambah filter pada **kolom mana pun di tabel data item laporan**, tanpa rilis baru. Kebutuhan "tambah A, B, C" dari klien saat handover dijawab di sini.

| BC | CoreERP |
| --- | --- |
| `dataitem` laporan, satu bagian filter per data item | `ReportDefinition::dataItems()` di module: data item (misalnya Aset, atau Dokumen lalu Baris) dengan model tabelnya dan alias tabel itu di query laporan |
| Field tabel beserta Caption | Katalog field per model: konstanta `FIELD_CAPTIONS`, `FIELD_OPTIONS`, `FIELD_LOOKUPS`, dan `FIELD_HIDDEN` dibaca `App\Support\Modules\Contracts\TableFields`; tipe kolom dibaca dari database |
| `RequestFilterFields` | Kolom bawaan data item (`defaultFields`), langsung tampil tanpa ditambahkan |
| `DataItemTableView` | Batasan di query laporan dan kebijakan data organisasi; filter tambahan hanya mempersempit |
| Sintaks filter | `App\Support\Modules\Contracts\FieldFilterExpression`: `..`, `\|`, `&`, `<>`, `<`, `<=`, `>`, `>=`, `*`, `?`, `@`, `''`, dan `t` untuk hari ini |
| Field Option dan TableRelation | Kolom pilihan dan rujukan dipilih dari daftar; beberapa pilihan berarti *atau* |

- **Semua kolom.** Kolom teknis (`id`, `tenant_id`, `version`, `deleted_at`, `creation_key`, jejak pengguna) dan kolom berkelas `AccountData` tidak pernah ditawarkan. Kolom lain wajib diberi nama tampilan atau disembunyikan dengan alasannya; penjaganya `ReportFieldCatalogTest` di module aset, sehingga kolom baru di tabel data item tidak diam-diam hilang dari "+ Tambah filter".
- **Parameter `filters[<data item>][<kolom>]`**: teks ekspresi untuk teks, angka, tanggal, dan waktu; daftar nilai untuk pilihan, ya/tidak, dan rujukan. Ikut disimpan di opsi terakhir dan preset, dan ditulis di kepala laporan lewat placeholder `filter_tambahan` ("Aset — Lokasi: Gudang; Nilai perolehan: >1000000").
- **Tanggal dan waktu** dibaca menurut zona pengguna: `01/09/2026` pada kolom waktu berarti satu hari penuh di zona itu.
- **Keamanan.** Nama kolom hanya dari katalog, nilai selalu lewat binding. Ekspresi dibatasi 250 karakter dan 50 istilah. Kolom yang tidak dikenal dan ekspresi yang salah ditolak dengan pesan siap-baca, sama seperti parameter yang tidak diterima.
- **Yang dikerjakan module untuk satu laporan:** isi katalog field model data item-nya, kembalikan data item dari `dataItems()`, dan panggil `AdditionalFilters::apply()` pada query setiap data item. Halaman laporan menampilkan bagiannya lewat `ReportFilterBar` (`additional`).

Batas saat ini: kolom rujukan hanya dipilih dari daftar, belum diketik dengan pola kode seperti `GDG*`; filter total (*Filter totals by*, FlowFilter BC) belum ada.

## Excel (data saja)

Padanan *Microsoft Excel Document (data only)* pada Send to BC (K-26): setiap laporan dapat diekspor sebagai dataset apa adanya, tanpa layout, tanpa kop, dan tanpa layanan PDF, lewat antrean ekspor yang sama. Dialog cetak menawarkannya di samping format layout. Barisnya `report_exports.kind = data`.

- Satu lembar per tabel dataset, dengan judul kolom dari label placeholder pada definisi laporan dan urutan seperti definisinya, lalu lembar "Keterangan" untuk nilai tunggal (filter, total, tanggal cetak). Placeholder `kop.*` tidak ikut.
- Nilai bertipe ditulis lewat `ValueFormat::cell()`, sumber yang sama dengan layout Excel: angka dan uang sebagai angka dengan format selnya, tanggal dan waktu sebagai tanggal Excel.
- Teks tidak pernah menjadi rumus: nilai berawalan `=` ditulis sebagai teks.
- Penulisnya OpenSpout (`App\Support\Reporting\Rendering\TypedSheetWriter`), bukan PhpSpreadsheet, karena ia menulis baris demi baris ke berkas. PhpSpreadsheet menyusun seluruh buku kerja di memori dan tetap dipakai untuk layout ber-template.

## Ekspor daftar di layar

Padanan "Open in Excel" pada list page BC (K-27, TODO 8.4): baris dan kolom yang tampil di layar daftar module, dengan filter dan urutan yang sedang dipakai, untuk **semua** baris yang cocok — bukan hanya yang sudah dimuat layar — tanpa layout. Kustomisasi analitis bukan urusan jalur ini; di BC pun ia milik analysis mode dan Power BI, bukan template ekspor.

- **Jalurnya antrean ekspor yang sama.** Layar module mengirim `CustomEvent('coreerp:list-export')` berisi kode daftar, kolom yang tampil beserta judulnya, urutan, dan filter. Shell memeriksa bentuknya lalu memanggil `POST /api/v1/list-exports`; hasilnya tampil di tray Ekspor dan ikut masa simpan `report_exports` (`kind = list`).
- **Core tidak membaca tabel module.** Module mendaftarkan daftarnya ke `ListExportSources` lewat kontrak `ListExportSource`: nama, permission baca, kolom beserta tipenya, aturan filter, jumlah baris, dan baris yang dibaca bertahap. Worker meminta barisnya dengan konteks pengguna yang sama dengan laporan.
- **Hak sama dengan melihat.** Mengekspor menuntut permission baca daftar itu, diperiksa Core saat diminta dan saat job berjalan, lalu module menerapkan permission dan kebijakan data organisasinya persis seperti daftar di layarnya. Daftar yang tidak boleh dilihat dijawab 404.
- **Memori datar.** Module membaca per seribu baris dengan urutan tetap (kunci terakhir `id`), dan setiap baris langsung ditulis ke berkas. Lima puluh ribu aset diekspor dengan memori puncak yang praktis sama dengan lima ribu (`ListExportTest`).
- **xlsx lalu CSV.** Sampai batas satu lembar Excel (1.048.575 baris data) hasilnya xlsx bertipe; lebih dari itu CSV, sampai `reporting.list_export_max_csv_rows` (bawaan 2.000.000, `COREERP_REPORTING_LIST_EXPORT_MAX_CSV_ROWS`). CSV menulis angka mentah dan tanggal seperti di layar, dan memberi petik pada teks berawalan `=`, `+`, `-`, atau `@`. Batas waktu job tetap sepuluh menit.
- **Pilotnya register aset** (`management-aset`, daftar `aset`). Daftar lain menyusul dengan satu kelas `ListExportSource` di module-nya dan tombol Ekspor di layarnya; `DataTable` memberi tahu urutan yang tampil lewat `sort`/`onSortChange`.

## Hasil ekspor punya masa simpan

Berkas hasil disimpan sebagai riwayat ekspor milik pemintanya, bukan sebagai lampiran pada record, dan dihapus setelah masa simpan lewat. Masa simpannya kebijakan retensi `report_exports`: bawaannya `reporting.retention_days`, tenant boleh mengubahnya di Pengaturan → Retensi data (minimal 1 hari), `expires_at` pada baris diisi dari setelan efektif tenant saat ekspor selesai atau gagal, dan baris beserta berkasnya dihapus layanan retensi berdasarkan `created_at`. Dokumen selalu dapat dibuat ulang dari data dan layout terbaru; menyimpannya selamanya hanya membengkakkan penyimpanan dan menghasilkan dua versi kebenaran ketika data berubah setelah dicetak. Kalau sebuah proses bisnis membutuhkan salinan resmi yang tidak boleh berubah, itu kebutuhan manajemen dokumen dengan aturannya sendiri, bukan tugas mesin cetak.

## Per profil deployment

| Profil | Engine render | Worker | Layout dan hasil ekspor | Catatan |
| --- | --- | --- | --- | --- |
| Pooled cloud | Satu `core-renderer` untuk semua tenant | Replica `core-worker` sesuai antrean | Database Core dan object storage bersama, dipisah `tenant_id` | Engine berada di network internal tanpa jalan keluar, supaya dokumen unggahan tenant tidak dapat memancingnya mengambil sumber dari internet |
| Isolated cloud | Bersama atau per tenant, mengikuti tier | Sama | Per tenant | Tidak ada perubahan kode; hanya keputusan penempatan |
| On-prem perpetual | Satu container tambahan dalam bundle Core | Angka `replicas` di compose customer | Database Core dan volume lokal | Tidak ada dependensi Office 365 atau SharePoint; ini alasan utama model BC dipilih |

Module tidak punya alamat: Core memanggilnya di dalam proses yang sama. Tidak ada setelan alamat app yang perlu benar sebelum sebuah laporan bisa dicetak — `COREERP_APP_API_ENDPOINTS` dan batas waktunya ikut dibuang bersama jalur HTTP ke app berkontainer pada 10 September 2026.

## Trade-off yang diterima secara sadar

- Core lebih gemuk dan lebih sering berubah: fitur laporan baru adalah release Core.
- Kontrak dataset per app ditulis tangan dan dirawat.
- Konteks pengguna diteruskan Core ke app; ini jalur keamanan sendiri yang diuji sendiri.
- Kalau worker Core mati, tidak ada app yang bisa mencetak.
- Dua lompatan jaringan per dokumen dan dataset yang lewat sebagai JSON **gugur**: dataset module dibaca dengan pemanggilan fungsi di proses yang sama.

## Gate yang berlaku saat menambah laporan

1. **Permission.** Laporan menyebut permission data app yang wajib dipegang pengguna; Core memeriksanya saat tombol ditekan, app memeriksanya lagi saat dataset diminta.
2. **Data policy.** Dataset memakai scope organisasi yang sama dengan endpoint detail; jalur dataset tidak punya jalan pintas.
3. **Kontrak.** `PenyediaLaporanModul` terdaftar saat boot, dan ada test yang membuktikan ketiga methodnya menjawab dari jalur yang sungguhan.
4. **Load.** Endpoint permintaan ekspor Core dan endpoint dataset app masuk skenario load test; `apps/core/loadtest/k6/reporting-e2e.js` menjalankan alur penuh pada stack lokal.
5. **Dokumentasi.** Halaman fitur di `docs/apps/<app>/` menyebut kode laporan, placeholder, dan permission-nya.

## Lihat juga

- [Reporting dan read replica](07-reporting-and-replicas.md) — laporan gabungan lintas app dibangun dari projection event, bukan dari dokumen cetak
- [Release dan on-prem](03-release-and-on-prem.md) — `core-renderer` dalam bundle
- [Development stack lokal](11-local-docker-development.md) — service renderer dan storage Core di stack lokal
- [Jalur membangun app baru](../apps/membangun-app-baru.md) — pesan `coreerp.print` dan `coreerp.notification`
- [Laporan dan ekspor Management Aset](../apps/management-aset/transaction/laporan/index.md) — implementasi pertama
