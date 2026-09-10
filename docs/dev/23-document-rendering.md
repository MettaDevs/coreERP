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
| Katalog laporan | Report object | Manifest app | Blok `reports` di `app.yaml`, disalin ke tabel `app_reports` Core saat registrasi |
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

1. Satu kelas dataset: kode, nama, permission datanya, aturan parameter, daftar placeholder, dan query yang memakai scope organisasi yang sama dengan endpoint detailnya.
2. Satu layout bawaan `.docx` atau `.xlsx`, dibangkitkan dari kode lewat command supaya perubahannya terbaca di review.
3. Blok `reports` di `app.yaml`.
4. Cara Core mencapainya: module mendaftarkan `PenyediaLaporanModul` dari penyedia layanannya.
5. Tombol Cetak pada halaman record yang meminta Shell mencetak.

Blok manifestnya:

```yaml
reports:
  - code: procurement.purchase-order        # berawalan ID app; kode di sisi app: purchase-order
    name: Purchase order
    description: Satu purchase order beserta barisnya.
    permission: procurement.purchase-order.read   # permission app ini; wajib dipegang pengguna
    parameters: [id]                              # nama parameter; validasinya di app
    builtin_layouts:
      - key: standar
        name: Purchase order standar (Word)
        format: docx
```

Tiga hal yang diminta Core:

| Yang diminta | Guna |
| --- | --- |
| Definisi | Placeholder, parameter, layout bawaan |
| Berkas layout bawaan | Isi `.docx`/`.xlsx` yang ikut rilis |
| Dataset | Data yang sudah disaring; kegagalan disampaikan sebagai pesan siap-baca bila record tidak ada atau di luar scope |

Ketiganya adalah tiga method pada `PenyediaLaporanModul`. Sampai 10 September 2026 ada bentuk kedua
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

- **Nilai sudah siap tampil saat keluar dari dataset.** Tanggal, angka, dan label status diformat di kelas definisi app, bukan di layout, supaya semua layout satu laporan menampilkannya dengan cara yang sama.
- **Placeholder tak dikenal dikosongkan, bukan dibiarkan.** Dokumen yang sampai ke vendor tidak boleh memuat `${...}`. Saat unggah, placeholder yang tidak ada di dataset dilaporkan sebagai peringatan tanpa menolak unggahan; salah ketik ketahuan sebelum dicetak.
- **Dokumen bermakro ditolak saat unggah.** Berkas dibuka engine bersama semua tenant; tidak ada alasan sebuah layout menjalankan kode. Yang diperiksa isi zip-nya, bukan ekstensinya.
- **Rumus di bawah baris template Excel diperluas.** `=SUM(B3:B3)` pada satu baris template menjadi `=SUM(B3:B7)` setelah lima baris hasil, karena Excel sendiri tidak memperluas rentang yang berakhir tepat di baris penyisipan.

## Ekspor dikerjakan di latar belakang

Permintaan cetak dijawab `202` dengan baris ekspor berstatus `queued`, lalu `core-worker` yang meminta dataset ke app, mengisi layout, mengubah format, dan menyimpan berkas. Shell memantau status dan menawarkan unduhan setelah selesai. Pengguna tidak menunggu di layar, dan daftar ribuan baris tidak menahan request sampai timeout.

Akibatnya pada arsitektur:

- **Token ke app diterbitkan ulang saat job berjalan**, dari membership yang meminta, bukan dibekukan saat permintaan dibuat. Pencabutan hak antara "Cetak" dan pengerjaan langsung berlaku; membership yang tidak lagi aktif membuat ekspor gagal dengan pesan yang jelas.
- **Worker Core harus selalu dihidupkan ulang oleh deployment.** `queue:work` keluar sendiri setelah `--max-time` supaya memori bersih; container tanpa kebijakan restart mati diam-diam setelah satu jam dan setiap ekspor berhenti di `queued`. Stack lokal memakai `restart: unless-stopped`; bundle release dan orchestrator harus setara.
- **Worker Core diskalakan dengan menambah replica**, tanpa load balancer: database yang menjamin satu baris antrean hanya diambil satu worker, worker tidak menyimpan apa pun di memori, dan berkas ditulis ke penyimpanan bersama. Seribu orang menekan Cetak bersamaan hanya memanjangkan antrean, bukan menumbangkan API.
- **Web dan worker Core melihat penyimpanan yang sama.** Pada Compose keduanya berbagi volume `core-storage`; pada cloud multi-instance disk `reporting` harus menunjuk object storage.
- **Batas per pengguna dan per ekspor.** Ekspor aktif per pengguna dibatasi, dan dataset melewati batas baris ditolak sebelum render, supaya satu orang atau satu permintaan tidak menguasai worker. Batas baris ini permanen: seluruh dataset disusun di memori sebelum layout diisi, apa pun jalur permintaannya. Laporan ribuan halaman adalah pekerjaan [reporting projection](07-reporting-and-replicas.md), bukan mesin cetak.
- **Hasilnya diumumkan Shell** lewat tray Ekspor di header, toast, dan lonceng notifikasi. Shell memantau Core hanya selama ada ekspor yang berjalan, lalu berhenti sendiri.
- **Kegagalan tidak diulang.** Layout yang salah susun atau data yang terlalu besar akan gagal lagi dengan cara yang sama; pengguna lebih terbantu oleh pesan yang jelas pada baris ekspor daripada tiga percobaan diam-diam.

## Hasil ekspor punya masa simpan

Berkas hasil disimpan sebagai riwayat ekspor milik pemintanya, bukan sebagai lampiran pada record, dan dihapus setelah masa simpan lewat. Dokumen selalu dapat dibuat ulang dari data dan layout terbaru; menyimpannya selamanya hanya membengkakkan penyimpanan dan menghasilkan dua versi kebenaran ketika data berubah setelah dicetak. Kalau sebuah proses bisnis membutuhkan salinan resmi yang tidak boleh berubah, itu kebutuhan manajemen dokumen dengan aturannya sendiri, bukan tugas mesin cetak.

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
4. **Load.** Endpoint permintaan ekspor Core dan endpoint dataset app masuk skenario load test; `apps/control-plane/loadtest/k6/reporting-e2e.js` menjalankan alur penuh pada stack lokal.
5. **Dokumentasi.** Halaman fitur di `docs/apps/<app>/` menyebut kode laporan, placeholder, dan permission-nya.

## Lihat juga

- [Reporting dan read replica](07-reporting-and-replicas.md) — laporan gabungan lintas app dibangun dari projection event, bukan dari dokumen cetak
- [Release dan on-prem](03-release-and-on-prem.md) — `core-renderer` dalam bundle
- [Development stack lokal](11-local-docker-development.md) — service renderer dan storage Core di stack lokal
- [Jalur membangun app baru](../apps/membangun-app-baru.md) — pesan `coreerp.print` dan `coreerp.notification`
- [Laporan dan ekspor Management Aset](../apps/management-aset/transaction/laporan/index.md) — implementasi pertama
