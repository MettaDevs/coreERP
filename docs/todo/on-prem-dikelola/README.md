# On-prem yang dikelola vendor

Rencana kerja, bukan desain kanonik. Ditulis 14 September 2026 setelah seorang calon klien meminta
aplikasi **disewa**, **dipasang di server miliknya sendiri**, tetapi **tetap kita yang mengelola**.

::: warning Bagian image dan distribusi rilis sudah digantikan
Sejak 15 September 2026 image untuk server klien ditarik dari **Harbor milik kita sendiri**, satu image
untuk semua klien, dan lisensi **mengunci**. Semua yang di halaman ini menyebut GHCR, image per edisi,
`RELEASE_SIGNING_KEY` di GitHub, atau lisensi yang hanya memperingatkan berlaku dari
[Registry image sendiri dengan Harbor](/todo/registry-harbor/), bukan dari sini. Sisanya — agen yang
menarik, daftar operasi tertutup, kunci situs, dan tanda tangan permintaan — tetap berlaku.
:::

## Pertanyaan yang harus dijawab halaman ini

- Bagaimana admin provider mengelola server milik klien **hanya dari admin.erp**, tanpa SSH ke
  setiap server satu per satu?
- Bagaimana server yang berada di belakang router klinik — tanpa IP publik, tanpa port masuk —
  tetap dapat diperbarui?
- Apa yang boleh diminta admin.erp dari server klien, dan apa yang **tidak boleh** — termasuk ketika
  admin.erp sendiri dibobol?
- Data apa yang boleh keluar dari server fasilitas kesehatan?
- Di mana setiap bagiannya dibangun, dan bagaimana ia diuji sebelum menyentuh server klien sungguhan?

## Kenapa ini ada

Sebagian besar klien aplikasi lama memakai **server fisik miliknya sendiri**, bukan akun cloud.
Jalur yang ada di repo dibangun untuk dua keadaan yang berseberangan:

| | SaaS (`pooled`, `isolated`) | On-prem beli putus | **On-prem dikelola** — halaman ini |
| --- | --- | --- | --- |
| Pemilik server | kita | klien | klien |
| Pembayaran | langganan | sekali | **langganan** |
| Yang menjalankan pembaruan | kita | admin klien | **kita, dari jauh** |
| Jangkauan admin.erp | penuh | tidak ada | **lewat agen di server klien** |

Kolom terakhir adalah gabungan yang belum ada. Ia mengambil server dari on-prem, pembayaran dari
SaaS, dan menuntut sesuatu yang tidak dimiliki keduanya: **cara memerintah server yang bukan milik
kita, dari satu tempat, tanpa membuka pintu masuk ke server itu.**

## Yang sudah ada hari ini

Bukan pekerjaan dari nol. Yang di bawah ini sudah berdiri dan tinggal dipakai:

| Bagian | Keadaan | Tempatnya |
| --- | --- | --- |
| Image per edisi, hanya berisi modul yang dibeli | Ada, dijaga CI | `scripts/build-edition.sh`, `scripts/verify-edition.sh` |
| Image edisi diterbitkan ke registry, ditandai SHA commit, tanpa tag bergerak | Ada | `.github/workflows/release.yml` → `ghcr.io/.../edisi-<edisi>:<sha>` |
| Bundle berisi seluruh image, compose, manifest, checksum, dan tanda tangan | Ada | `scripts/build-bundle.sh` |
| Pemasangan dan pembaruan dari bundle, dengan mundur yang memulihkan image **dan** database | Ada, dijalankan di Docker lokal | `scripts/update.sh` |
| Compose satu server tanpa konsol dan tanpa MinIO | Ada | `deploy/compose.edition.yaml` |
| Pola operasi yang dicatat per langkah, beralasan bila gagal, satu berjalan per sasaran | Ada, untuk lingkungan | tabel `environment_operations` |
| Masuk ke admin.erp lewat SSO, pintu kata sandi berbatas percobaan | Ada | `apps/control-plane/app/Http/Controllers/Sso/`, `apps/control-plane/app/Http/Controllers/Login.php` |

Satu hal yang **belum pernah dibuktikan**: bundle dipasang di mesin yang tidak punya git, composer,
npm, maupun akses ke repo kita. Itu kriteria selesai paling atas di
[bundle dan pemasangan on-prem](/todo/bundle-on-prem/), dan ia menunggu mesin bersih. Server
dev kedua adalah mesin itu — pengujian di halaman ini sekaligus menutupnya.

## Yang belum ada

- Daftar **situs** — satu server klien beserta profil, versi yang diminta, dan versi yang dilaporkan.
- **Agen** di server klien, beserta cara mendaftarkannya.
- **API agen** di admin.erp dan kontraknya.
- Antrean operasi yang **menunggu diambil** — `environment_operations` hanya mengenal operasi yang
  dijalankan saat itu juga.
- **Jejak audit tindakan operator.** Hari ini admin.erp tidak mencatat satu pun.
- **Lisensi bertanda tangan** yang dibaca Core.
- **Cadangan luar lokasi** yang dijadwalkan dan dilaporkan.
- Cara Core di server klien lahir dengan **id tenant yang sama** dengan catatan di admin.erp.

## Bentuknya: agen yang menarik, bukan konsol yang mendorong

### Kenapa menarik

Server klinik hampir selalu berada di belakang router: tanpa IP publik, dan tanpa port yang boleh
dibuka dari luar. admin.erp yang menyambung ke server klien — lewat SSH atau API — menuntut pintu
masuk yang justru tidak ada, dan membukanya menambah satu jalan serangan ke server yang menyimpan
data pasien.

Karena itu arahnya dibalik. **Agen di server klien yang menyambung keluar** lewat HTTPS, bertanya
"ada perintah untuk saya?", mengerjakannya, lalu melaporkan hasilnya. Tidak ada port masuk yang
dibuka untuk CoreERP.

Pola ini bukan temuan kita; ia bentuk yang dipakai produk yang mengelola mesin di tempat
pelanggannya. Tautannya di [Sumber](#sumber).

| Produk | Yang ditiru dari sana |
| --- | --- |
| Portainer Edge Agent | Agen yang berkala menanyakan pekerjaan ke server, tanpa membuka port di host; perintah pasang dibuat dari layar; id dan token unik per mesin |
| Azure Arc | Skrip pasang dibuat dari portal; agen berbicara keluar lewat 443; **daftar ekstensi yang boleh dipasang disetel di server itu sendiri dan tidak dapat diubah dari Azure** — padanan daftar tertutup di bawah |
| AWS Systems Manager, *hybrid activation* | Kode dan id aktivasi yang umurnya dibatasi — bawaan 24 jam, paling lama 30 hari — padanan token pendaftaran |
| Rancher | Perintah daftar dibuat dari layar; agen di sisi pelanggan yang membuka terowongan ke server |

Satu catatan dari Azure yang berlaku sama di sini: siapa pun yang memegang root di server dapat
mengubah konfigurasi agennya. Daftar tertutup menjaga server klien dari admin.erp yang dibobol,
**bukan** dari orang yang sudah menguasai server klien itu sendiri.

### Satu jalan antar

Yang dikerjakan agen selalu **paket bertanda tangan**: rilis yang dipasang, lisensi yang berlaku,
atau perintah yang diminta. Semuanya sampai lewat jalan yang sama — agen menyambung keluar ke
admin.erp lewat HTTPS:

| | |
| --- | --- |
| Pendaftaran pertama | Perintah satu baris dari admin.erp |
| Pembaruan | Agen menarik perintah dan berkas rilis sendiri; image ditarik lewat digest |
| Laporan balik | Heartbeat berkala |
| Lisensi | Operasi `install_license` yang diminta dari admin.erp |

Agen memeriksa tanda tangannya, lalu mengerjakannya atau menolaknya.

Klien yang internetnya putus-sambung tidak memerlukan keputusan tersendiri: selama sambungannya
putus situsnya tampil tidak melapor, dan putaran pertama sesudah sambungannya kembali mengirim
keadaan terbaru lalu mengambil operasi yang masih menunggu.

### Daftar tertutup: yang boleh diminta dari agen

Agen hanya mengenal operasi yang tertulis di dirinya sendiri. admin.erp memilih operasi dan
parameternya; ia **tidak pernah** mengirim perintah shell.

| Operasi | Di layar | Parameter | Keterangan |
| --- | --- | --- | --- |
| `upgrade` | Perbarui | edisi, nomor rilis | Rilis harus bertanda tangan kunci rilis dan lebih baru dari yang terpasang. Dijalankan lewat `update.sh` |
| `backup` | Cadangkan | — | Cadangan sekarang, di luar jadwal |
| `install_license` | Pasang lisensi | lisensi dan tanda tangannya | Lisensi harus bertanda tangan kunci lisensi |
| `rotate_key` | Ganti kunci situs | — | Agen membuat pasangan kunci baru, mengirim kunci publiknya dengan tanda tangan kunci lama, lalu membuang yang lama |
| `send_diagnostics` | Kirim diagnosa | — | Hanya isi yang tercantum di [data yang boleh keluar](#data-yang-boleh-keluar-dari-server-klien) |

Kode operasinya berbahasa Inggris karena ia bagian dari kontrak dan CHECK constraint; kata di layar
berbahasa Indonesia.

Yang **sengaja tidak ada**, dan alasannya:

- **Shell jarak jauh.** Satu perintah bebas sudah cukup untuk membaca seluruh database pasien.
  Dukungan yang benar-benar butuh masuk ke server memakai kanal terpisah yang dinyalakan klien
  sendiri — VPN yang tersambung keluar — dan dimatikan sesudahnya.
- **Memulihkan cadangan dari jauh.** Pemulihan menimpa data yang ditulis sesudah cadangan diambil.
  Keputusan seperti itu diambil bersama klien dan dijalankan di tempat, bukan dengan satu tombol.
- **Mengubah berkas compose atau setelan sembarang.** Perubahan setelan datang lewat rilis, yang
  bertanda tangan dan melewati CI.

### Tiga kunci, tiga peran

| Kunci | Menandatangani | Disimpan di | Kalau bocor |
| --- | --- | --- | --- |
| **Kunci rilis** | bundle dan berkas rilis | di luar admin.erp: rahasia alur rilis (`RELEASE_SIGNING_KEY`) | Rilis palsu dapat dibuat — kunci ini yang paling dijaga |
| **Kunci lisensi** | lisensi | admin.erp | Lisensi palsu dapat dibuat — akibatnya hanya peringatan yang tidak muncul, karena lisensi tidak mengunci apa pun |
| **Kunci situs** (RSA) | permintaan agen ke admin.erp | kunci privat di server klien; admin.erp hanya menyimpan **kunci publik**-nya | Hanya situs itu yang dapat ditiru; kuncinya diputar |

Kunci situs asimetris, bukan rahasia bersama. Rahasia bersama menuntut admin.erp menyimpan salinan
yang dapat dipakai; database admin.erp yang bocor lalu berarti setiap agen dapat ditiru. Dengan
kunci publik, yang bocor dari admin.erp tidak dapat menandatangani apa pun.

Bentuk tanda tangan permintaan tidak dikarang sendiri. Ia mengikuti HTTP Message Signatures
(RFC 9421) dengan algoritma `rsa-v1_5-sha256`, dan hanya sebagian kecil standarnya yang dipakai:

| | |
| --- | --- |
| Komponen yang ditandatangani | `@method`, `@path`, dan `content-digest` (RFC 9530) |
| Parameter | `created`, `keyid` — id situs — dan `alg` |
| Ditolak bila | `created` berselisih lebih dari 300 detik dari jam admin.erp, digest tidak cocok dengan isi, atau situsnya dicabut |
| Dihitung dengan | `openssl dgst -sha256 -sign` di bash, `openssl_verify` di PHP — tanpa ekstensi tambahan |

`@path` dipilih alih-alih `@target-uri` karena admin.erp berada di belakang proxy: alamat lengkap
yang dilihat PHP bergantung pada setelan proxy yang dipercaya, sementara path tidak.

Pemisahan ini yang menentukan seberapa buruk admin.erp yang dibobol. **admin.erp tidak memegang
kunci rilis**, jadi penyerang yang menguasainya hanya dapat meminta agen memasang rilis yang memang
kita terbitkan. Ia tidak dapat membuat rilis baru.

Satu celah tersisa dan disebut apa adanya: meminta agen **mundur ke rilis lama** yang sah tetapi
punya cacat keamanan. Karena itu nomor rilis di manifest wajib naik terus, dan agen menolak
`upgrade` ke nomor yang lebih kecil dari yang sedang terpasang. Mundur hanya terjadi di dalam
`update.sh` sendiri, ketika pembaruan gagal.

Kunci publik rilis dipasang sekali saat pendaftaran dan tidak pernah diambil dari paket yang
diverifikasinya — alasan yang sama dengan
[keputusan bundle](/todo/bundle-on-prem/#_1-yang-memverifikasi-tanda-tangan-tiba-lewat-jalur-lain).

### Data yang boleh keluar dari server klien

Daftar tertutup. Yang tidak ada di sini tidak dikirim:

- id situs, versi agen, nomor rilis dan digest image yang terpasang;
- keadaan tiap container: menyala, sehat, atau berhenti;
- sisa ruang disk data dan disk cadangan;
- waktu, ukuran, dan hasil cadangan terakhir;
- hasil operasi terakhir beserta langkah tempat ia berhenti;
- tanggal habis sertifikat dan lisensi;
- selisih jam server terhadap admin.erp.

**Tidak pernah dikirim:** isi tabel, dump database, log aplikasi, trace, nama pengguna, dan
setelan rahasia. Log dan trace mungkin memuat data pasien; mereka tetap di server klien.

## Alurnya

### Mendaftarkan situs

1. Operator membuat **situs baru** di admin.erp: tenant, profil `on-prem-dikelola`, edisi, alamat,
   dan jendela pembaruan yang disepakati dengan klien.
2. admin.erp menampilkan **perintah pasang satu baris**. Token di dalamnya hanya berlaku sekali dan
   kedaluwarsa dalam satu jam.
3. Perintah itu dijalankan sekali di server klien. Skripnya memasang Docker bila belum ada, memasang
   agen, lalu mendaftarkan situs dengan token tadi.
4. Saat mendaftar, agen **membuat pasangan kunci RSA** di server. admin.erp hanya menerima kunci
   publiknya — token pendaftaran tidak pernah menjadi kredensial jangka panjang. Laporan pertamanya
   menandai situs terdaftar di admin.erp.
5. **Rahasia aplikasi dibuat di server itu juga** — `APP_KEY` dan kata sandi database — dan tidak
   pernah dikirim ke admin.erp.
6. Operator meminta **Perbarui** ke rilis pertama dari admin.erp. Agen menariknya di dalam jendela
   pembaruan dan menjalankan `update.sh`.
7. Orang yang memasang menjalankan `coreerp-agent bootstrap-tenant` di terminal server. Tenant lahir
   dengan id yang sama dengan catatan di admin.erp, dan kata sandi sementara admin pertama tampil
   **sekali di terminal itu**. Langkah ini sengaja tidak dijalankan agen dari timer: keluarannya
   memuat kata sandi, dan keluaran timer masuk ke journal sistem.

### Memperbarui

Operator menekan **Perbarui ke rilis X** di rincian situs. Operasinya tercatat `requested`. Agen
mengambilnya pada kunjungan berikutnya **di dalam jendela pembaruan**, memegang *lease* 15 menit,
menjalankan `update.sh`, dan melaporkan tiap langkah — sampai `succeeded`, atau `failed` beserta
langkah tempat ia berhenti dan sebabnya. Setiap laporan langkah memperpanjang *lease*-nya.

*Lease* yang habis dibaca saat itu juga — ketika agen meminta operasi berikutnya atau ketika rincian
situs dibuka — bukan oleh penjadwal. admin.erp sengaja tidak punya antrean maupun penjadwal, dan
satu pekerjaan kecil tidak cukup alasan untuk menambahkannya.

### Mencabut situs

Operator mencabut situs di admin.erp: tanda tangan kunci situsnya berhenti diterima dan operasi yang tertunda
dibatalkan. **Aplikasi di server klien tetap berjalan** — mencabut situs menghentikan pengelolaan,
bukan pelayanan pasien.

### Rilis yang dapat dipilih

Yang ditarik agen tidak membawa `images.tar.gz`. Yang diterbitkan per rilis adalah **berkas rilis**:
`manifest.json`, `compose.yaml`, `update.sh`, `SHA256SUMS`, dan `SHA256SUMS.sig` — bentuk yang sama
dengan bundle, dikurangi arsip image-nya.

- `manifest.json` menyebut image edisi **lewat digest registry** (`ghcr.io/…@sha256:…`), bukan tag.
  Tag dapat dipindahkan; digest tidak.
- Rantai kepercayaannya tidak putus: tanda tangan menjamin `SHA256SUMS`, `SHA256SUMS` menjamin
  `manifest.json`, dan `manifest.json` menyebut isi image lewat digest.
- `update.sh` menarik image dari registry bila bundle tidak membawa arsip image, lalu memeriksa id
  image yang ditariknya sama dengan yang disebut manifest — pemeriksaan yang sudah ada untuk jalur
  bundle.
- Alur rilis menandatangani berkas rilis dan **mendaftarkannya ke admin.erp**. admin.erp memeriksa
  tanda tangannya dengan kunci publik rilis sebelum menyimpannya; berkas bertanda tangan salah tidak
  pernah muncul sebagai pilihan.
- Pasangan edisi dan nomor rilis unik. Alur rilis berjalan di setiap push ke `main`, jadi build
  dengan nomor rilis yang sudah terdaftar **tidak** didaftarkan ulang — rilis baru menuntut nomor
  `rilis:` di manifest edisi dinaikkan. Itu yang membuat penolakan mundur di agen bermakna.

Image pendamping — PostgreSQL dan perender PDF — hari ini masih disebut lewat tag di
`deploy/compose.edition.yaml`. Untuk situs yang menarik image dari registry, itu berarti isinya dapat
berubah tanpa rilis kita berubah. Menyematkan keduanya lewat digest dicatat sebagai pekerjaan lanjutan.

## Tenant: satu identitas di dua tempat

Catatan komersial tenant — klien, edisi, masa sewa — tinggal di admin.erp. Data tenant tinggal di
server klien. Keduanya harus menyebut **id tenant yang sama**, supaya laporan, tiket dukungan, dan
SSO menunjuk orang yang sama tanpa tabel penerjemah.

Sebelumnya tenant on-prem lahir lewat pendaftaran usaha di server itu sendiri, dengan id baru.
Sekarang `php artisan tenant:bootstrap-site` melahirkannya **dengan id yang diberikan**, lewat
`RegisterBusiness` yang sama — satu kunci opsional `tenant_id`, bukan salinan alurnya. Id tenant dan
namanya diantar admin.erp ke agen saat pendaftaran, dan `coreerp-agent bootstrap-tenant` yang
meneruskannya. Server yang sudah punya tenant dengan id lain menolak: satu server on-prem melayani
satu tenant.

## Lisensi: tanda, bukan kunci

Lisensi adalah file bertanda tangan kunci lisensi: id tenant, id situs, edisi, dan tanggal berakhir.
Core membacanya (`App\Support\License\SiteLicense`) dan **menampilkan peringatan** ketika lisensi
tidak ada, tidak sah, tinggal 30 hari, atau lewat.

**Aplikasi tidak dikunci ketika lisensi habis.** Klien kita fasilitas kesehatan; aplikasi yang
berhenti berarti pelayanan pasien berhenti, dan akibatnya jatuh pada kita. Pembayaran ditegakkan
lewat kontrak: bayar di muka, dan pembaruan beserta dukungan berhenti ketika pembayaran terlambat.

## Cadangan

Dua cadangan yang berbeda, mengikuti
[keputusan bundle](/todo/bundle-on-prem/#_3-cadangan-mundur-dan-cadangan-bencana-adalah-dua-hal-berbeda):

| | Cadangan mundur | Cadangan bencana |
| --- | --- | --- |
| Siapa membuat | `update.sh`, sebelum migrasi | agen, terjadwal |
| Tempat | disk kedua di server klien | **di luar server klien** — penyimpanan kita, terenkripsi |

Cadangan bencana dienkripsi di server klien **sebelum** dikirim, dengan kunci yang tidak disimpan
di penyimpanan yang sama. Penyimpanan kita yang dibobol tidak boleh berarti data pasien terbuka.

## HTTPS

| Keadaan server | Cara |
| --- | --- |
| **Dapat dijangkau dari internet** | Sertifikat Let's Encrypt biasa untuk subdomain CoreERP |
| **Hanya jaringan lokal** | admin.erp menerbitkan sertifikat lewat DNS-01 di domain kita; agen menariknya. Token DNS **tetap di server kita** — token itu dapat mengubah seluruh zona |

Server yang sudah melayani web lain di port 80 dan 443 tidak dapat menyerahkan port itu kepada
Traefik CoreERP. CoreERP ditempatkan **di belakang reverse proxy yang sudah ada**, pada subdomain
sendiri.

## Server yang juga melayani web publik

Klien pertama memakai server yang sudah menjalankan **web reservasi yang harus dapat diakses dari
luar** untuk layanan homecare, dengan penjagaan hanya id dan kata sandi. CoreERP akan menyimpan data
pasien di mesin yang sama. Sebelum apa pun dipasang di sana:

- **SSH hanya dengan kunci**, masuk dengan kata sandi dimatikan, dan pembatas percobaan seperti
  fail2ban menyala. SSH yang menerima kata sandi dari internet dicoba bot tanpa henti.
- **Port database CoreERP tidak dibuka** ke antarmuka mana pun di luar jaringan Docker-nya.
- **Pengguna yang menjalankan web reservasi tidak masuk grup `docker`.** Anggota grup itu setara
  root.
- **Aturan firewall host tidak menjaga port yang dibuka Docker.** Dokumentasi Docker sendiri
  menyatakan Docker dan UFW tidak cocok: lalu lintas ke port yang dipublikasikan container dibelokkan
  sebelum aturan UFW sempat berlaku. Pemeriksaannya dari luar server, bukan dengan membaca
  `ufw status`.
- **Paling aman, CoreERP berjalan di mesin virtual tersendiri** di server itu. Web yang dibobol lalu
  berhenti di batas mesin virtual, bukan di batas pengguna Linux.

Semua butir ini mengubah setelan server milik klien. Ia dilakukan setelah klien menyetujuinya,
bukan diam-diam oleh skrip pasang.

## Di mana dibangun

| Bagian | Tempat |
| --- | --- |
| Tabel situs, antrean operasi, laporan, rilis, jejak audit | `apps/core/database/migrations/2026_09_14_120000_create_site_registry_tables.php` |
| Layar **Situs** | `apps/control-plane/app/Http/Controllers/Sites/`, `apps/control-plane/resources/js/pages/sites/` |
| API agen dan pendaftaran rilis | `apps/control-plane/routes/api.php`, `apps/control-plane/app/Http/Controllers/Agent/AgentApi.php` |
| Pemeriksaan tanda tangan RFC 9421 | `apps/control-plane/app/Sites/SignedAgentRequest.php` |
| Kontrak API agen dan pemeriksa cakupannya | `apps/control-plane/contracts/` — dijalankan CI di job `konsol` |
| Jejak audit operator | `apps/control-plane/app/Audit/OperatorAudit.php` |
| Agen, skrip pasang, unit systemd | `deploy/agent/` |
| Pembaruan dari registry lewat digest | `scripts/update.sh` |
| Berkas rilis yang ditarik agen | `scripts/build-release-files.sh`, dipanggil `.github/workflows/release.yml` |
| Lisensi di Core | `apps/core/app/Support/License/`, banner di layout aplikasi |
| Tenant dengan id tertentu | `apps/core/app/Console/Commands/BootstrapSiteTenant.php` |

Agen ditulis dalam **bash**, bukan Go. Logika pembaruannya sudah ada dalam bash (`update.sh`), dan
tim yang merawatnya satu engineer dengan tiga anak magang. Bahasa kedua di satu repo berarti satu
rantai build, satu cara uji, dan satu kebiasaan baru. Pertimbangan ini dibuka lagi bila jumlah situs
sudah puluhan.

## Tabel baru

Semuanya di satu migration. Aturan yang tidak boleh dapat dilanggar ditulis sebagai constraint.

**`sites`** — satu server klien: tenant, profil `managed_on_prem`, edisi, jendela pembaruan, kunci
publik, rilis yang dilaporkan, dan laporan terakhirnya. Kunci publik dan waktu pendaftaran
berpasangan; jam mulai dan jam selesai jendela pembaruan berpasangan.

**`site_enrollment_tokens`** — hash token pendaftaran, masa berlakunya, dan waktu pakainya. Sekali
pakai diputuskan UPDATE bersyarat `used_at IS NULL`, bukan pembacaan sebelumnya.

**`site_operations`** — antrean. Berbeda dari `environment_operations` pada satu hal yang
menentukan: ia punya keadaan `requested`, karena operasinya **menunggu diambil** agen yang
berkunjung kemudian.

- `status`: `requested` → `running` → `succeeded` / `failed`; atau `cancelled` / `expired` bila
  tidak pernah diambil
- `running` wajib punya `lease_until`; `failed` wajib punya `failure_message`
- satu operasi `running` per situs, dan satu permintaan per jenis per situs — keduanya partial
  unique index

**`site_reports`** — laporan heartbeat yang isinya **berubah**. Laporan terakhir selalu tersimpan di
`sites.last_report`; riwayatnya hanya menambah baris ketika isinya berbeda, dengan mengabaikan jam
laporan dan sisa disk. Heartbeat per menit akan menjadi setengah juta baris per situs per tahun, dan
baris di repo ini tidak dihapus.

**`site_releases`** — berkas rilis bertanda tangan apa adanya, unik per edisi dan nomor rilis.

**`operator_audit_events`** — hanya-tambah, dan **ditegakkan trigger PostgreSQL**: UPDATE dan DELETE
ditolak database, bukan hanya tidak dipanggil aplikasi.

## Layar di admin.erp

- **Situs** — daftar situs: tenant, profil, rilis terpasang, keadaan, terakhir terlihat. Situs yang
  laporannya tertinggal ditandai; heartbeat yang berhenti tidak boleh terlihat sama dengan "sehat".
- **Situs baru** — isian, lalu perintah pasang.
- **Rincian situs** — keadaan terakhir, riwayat operasi beserta langkahnya, cadangan terakhir,
  lisensi, dan tombol operasi dari [daftar tertutup](#daftar-tertutup-yang-boleh-diminta-dari-agen).

Setiap tombol yang mengubah server klien meminta **konfirmasi tertulis** — nama situs diketik ulang —
dan tercatat di jejak audit sebelum operasinya dibuat.

## Prasyarat dari penilaian kesiapan control plane

Penilaian kesiapan control plane pada 12 September 2026 — sebuah sesi review, belum dijadikan halaman — menyebut admin.erp "belum mengontrol apa pun". Pekerjaan ini justru
menjadikannya mengontrol server klien, jadi tiga kekurangan dari penilaian itu berubah dari catatan
menjadi **syarat sebelum tombol pertama menyala**:

| Kekurangan | Keadaan hari ini | Syarat |
| --- | --- | --- |
| Tanpa jejak audit operator | tidak ada satu pun catatan | `operator_audit_events` berdiri sebelum operasi `upgrade` dapat dibuat |
| Login tanpa MFA | SSO dan batas percobaan sudah ada | MFA ditegakkan di penyedia identitas; toggle "Wajib MFA" di sana hari ini tidak menantang pengguna yang belum memasang MFA |
| Tanpa antrean dan rekonsiliasi | operasi dijalankan saat itu juga | `site_operations` dengan `requested` dan *lease* |

Dua kekurangan lain ikut tertolong tanpa dikerjakan tersendiri. **Konsep armada** lahir sebagai
daftar situs. Dan **kopling database**: agen tidak pernah menyentuh database mana pun — ia hanya
berbicara lewat API, jadi bagian baru ini tidak menambah pembaca tabel Core.

## Yang bertentangan dengan rancangan lama

Rancangan konektor dukungan on-prem menyatakan vendor **tidak** mengirim perintah pembaruan ke
server pelanggan. Aturan itu benar untuk on-prem beli putus: admin klien yang memutuskan kapan
pembaruan terjadi.

On-prem dikelola membutuhkan kebalikannya. Keduanya tidak disatukan; **profilnya dipisah**:

| | On-prem beli putus | On-prem dikelola |
| --- | --- | --- |
| Pembaruan dari vendor | tidak | ya, di jendela yang disepakati |
| Dasar izinnya | — | kontrak sewa |
| Agen | tidak dipasang, atau hanya melapor | dipasang, menjalankan daftar tertutup |

## Pengujian di server dev kedua

Server dev kedua belum memuat apa pun dari ekosistem ERP. Ia dipakai untuk membuktikan skema ini
sebelum menyentuh server klien.

### Menirukan keadaan klien

Server kosong tidak menirukan klien pertama. Sebelum CoreERP dipasang, server itu disiapkan menyerupai
keadaan sungguhan:

- **Web tiruan di port 80 dan 443** — satu nginx dengan halaman statis, berperan sebagai web
  reservasi. CoreERP harus dipasang di belakangnya, bukan mengambil alih portnya.
- **Firewall masuk hanya 22, 80, dan 443.** Tidak ada port khusus CoreERP.
- **SSH hanya dengan kunci.**

### Tahap dan kriteria selesai

Setiap tahap selesai bila dijalankan dan terbukti, bukan bila kodenya ditulis. Jalur gagal
dibuktikan merah lebih dulu.

**Tahap 0 — pasang dengan tangan.**

- CoreERP edisi uji menyala di belakang nginx tiruan, dari bundle, di mesin tanpa git, composer,
  maupun npm.
- Pembaruan ke rilis berikutnya berhasil; pembaruan yang health check-nya digagalkan dengan sengaja
  kembali ke rilis lama dengan data utuh.
- Setiap perintah yang dijalankan dicatat — catatan itu bahan skrip pasang.
- Port database terbukti **tidak** dapat dijangkau dari luar server, diperiksa dari mesin lain.

**Tahap 1 — melihat.**

- Situs dibuat di admin.erp, perintah pasang dijalankan, situs tampil hidup.
- Token pendaftaran yang sudah dipakai **ditolak** pada percobaan kedua; token kedaluwarsa ditolak.
- Heartbeat yang berhenti membuat situs tampil tertinggal, bukan sehat.
- Laporan hanya memuat isi daftar yang boleh keluar — diperiksa dari muatan sungguhan, bukan dari
  kode agen.

**Tahap 2 — satu tombol.**

- `upgrade` dari admin.erp berjalan sampai `succeeded`, dengan langkah-langkahnya tampil.
- Pembaruan yang gagal tampil `failed` beserta langkah dan sebabnya, dan server kembali ke rilis
  lama.
- Operasi di luar jendela pembaruan menunggu, tidak dijalankan.
- `upgrade` ke rilis yang tanda tangannya salah **ditolak agen**; ke nomor rilis yang lebih kecil
  **ditolak agen**.
- Agen yang dimatikan di tengah operasi: *lease*-nya habis, operasinya tidak menggantung selamanya.
- Setiap operasi punya baris jejak audit yang menyebut operatornya.

**Tahap 3 — lengkap.**

- Cadangan bencana terkirim terenkripsi, dan **dipulihkan ke server lain** sampai aplikasinya
  menyala. Cadangan yang tidak pernah dicoba dipulihkan belum terbukti ada.
- Lisensi yang lewat tanggal menampilkan peringatan, dan aplikasi tetap dapat dipakai.
- Situs yang dicabut: permintaan bertanda tangan kuncinya ditolak, aplikasinya tetap melayani.

## Keadaan 14 September 2026

Kode Tahap 1 dan 2 berdiri di cabang `feat/on-prem-dikelola`, dan sebagian Tahap 3 — lisensi dan
mencabut situs. **Belum satu pun dijalankan di server sungguhan**: server dev kedua belum diserahkan,
jadi Tahap 0 belum dimulai dan kriteria yang menyebut server belum terbukti.

Yang sudah dibuktikan, dan caranya:

| Yang dibuktikan | Cara |
| --- | --- |
| API agen, antrean, pendaftaran rilis, layar | Suite konsol, PostgreSQL sungguhan, termasuk setiap bentuk tanda tangan yang diubah dan laporan yang membawa kunci di luar kontrak |
| Agen, `update.sh` jalur registry, `pasang.sh` | Suite agen di container Ubuntu bersih melawan server tiruan yang memeriksa tanda tangan dan skema dari kontrak yang sama. Setiap penjaganya dicabut satu per satu dan test yang sesuai merah |
| **Agen bash sungguhan melawan admin.erp PHP sungguhan** | Database terpisah, `php -S`, agen di container: pendaftaran, laporan, `upgrade` sampai `succeeded`, dan rilis yang isinya diubah langsung di database **ditolak agen** dengan checksum yang tidak cocok |
| Jejak audit hanya-tambah | UPDATE dan DELETE ditolak trigger, dibuktikan test |

Ditemukan saat dijalankan, bukan saat dibaca: `update.sh` menolak pemasangan pertama di server
bersih karena volume database belum ada untuk dibandingkan dengan folder cadangan. Pemeriksaan itu
kini ditunda sampai pembaruan pertama yang benar-benar mencadangkan.

### Yang belum ada sebelum server dev kedua dapat dipakai

- **Pasangan kunci rilis.** `deploy/agent/kunci-rilis.pub` belum di-commit, dan rahasia
  `RELEASE_SIGNING_KEY` serta `CONSOLE_RELEASE_TOKEN` belum disetel di GitHub. Kunci privatnya dibuat
  pemilik repo, bukan pelaksana, dan tidak pernah melewati percakapan.
- **Kunci lisensi dan setelan di server admin.erp**: berkas di `/etc/coreerp/kunci/` dan
  `CONSOLE_RELEASE_TOKEN` di berkas env server.
- **Nomor `rilis:` dinaikkan** di manifest edisi yang akan dipasang, supaya alur rilis mendaftarkannya.
- **Visibilitas paket GHCR.** Bila privat, server klien butuh token baca.
- **Cadangan bencana ke luar server** belum dibangun; yang ada cadangan lokal dan laporannya.

## Keputusan

Diambil 14 September 2026. Pemilik produk menyerahkan seluruh keputusan di bagian ini kepada
pelaksana; alasannya ditulis supaya dapat dibuka lagi dengan sadar, bukan ditebak ulang.

| Keputusan | Diambil | Yang dibeli | Yang dibayar |
| --- | --- | --- | --- |
| Bahasa agen | bash, dengan `curl`, `jq`, dan `openssl` | tim dapat membacanya; `update.sh` dipakai apa adanya | penanganan galat lebih kasar daripada Go; `jq` dipasang skrip pasang |
| Tempat tabel situs | migration Core, seperti registry lingkungan | satu jalur migrasi yang sudah terbukti | kopling database admin.erp–Core bertambah satu kelompok tabel |
| Kunci situs | RSA, kunci privat di server klien, admin.erp menyimpan kunci publik | database admin.erp yang bocor tidak dapat meniru agen | kunci privat yang hilang bersama servernya tidak dapat dipulihkan dari admin.erp |
| Tanda tangan permintaan | RFC 9421, `rsa-v1_5-sha256`, komponen `@method` `@path` `content-digest` | bentuk standar, dihitung `openssl` dan PHP tanpa ekstensi | hanya sebagian standar yang dipakai, dan itu harus ditulis di kontrak |
| Memulihkan cadangan dari jauh | tidak ada di versi pertama | satu tombol yang dapat menimpa data pasien tidak ada | pemulihan menuntut kehadiran atau VPN dukungan |
| Lisensi habis | peringatan, tanpa mengunci | pelayanan pasien tidak pernah berhenti karena tagihan | penagihan bergantung kontrak |
| Id tenant | sama di admin.erp dan server klien | tanpa tabel penerjemah | butuh perintah pembuat tenant dengan id tertentu |
| Image yang ditarik agen | GHCR, disebut lewat digest | tanpa registry sendiri; isi image terkunci | server klien bergantung pada GHCR; token baca per situs bila paketnya privat |
| Pendaftaran rilis | alur rilis menandatangani dan mendaftarkan ke admin.erp; nomor rilis unik per edisi | admin.erp tidak pernah memegang kunci rilis | rilis baru menuntut nomor `rilis:` dinaikkan |
| *Lease* operasi | 15 menit, diperpanjang tiap laporan langkah, dibaca saat diminta | tanpa penjadwal di admin.erp | operasi yang mati terlihat gagal sesudah permintaan berikutnya, bukan tepat pada menitnya |
| Token pendaftaran | 1 jam, sekali pakai | token yang bocor cepat tidak berguna | perintah pasang harus dijalankan dalam satu jam sesudah dibuat |
| Izin pembaruan dari vendor | tertulis di kontrak sewa, dengan jendela pembaruan | dasar untuk menjalankan pembaruan tanpa bertanya setiap kali | kontrak on-prem dikelola berbeda dari beli putus |

## Perkiraan ukuran

Perkiraan kasar untuk satu engineer. Yang paling sulit ditebak Tahap 0, karena menyangkut mesin
yang belum pernah dilihat.

| Tahap | Perkiraan |
| --- | --- |
| 0 — pasang dengan tangan, termasuk di belakang reverse proxy | 2–4 hari |
| 1 — situs, perintah pasang, heartbeat | sekitar 1 minggu |
| 2 — tombol perbarui, antrean, jejak audit | 1–2 minggu |
| 3 — cadangan bencana, lisensi, mencabut situs | 1–2 minggu |

## Sumber

Dibaca dari halaman aslinya pada 14 September 2026. Tanggal di belakang tautan adalah tanggal yang
tertera di halaman itu; yang tanpa tanggal memang tidak mencantumkannya.

- [Portainer — Add an Edge Agent (Docker)](https://docs.portainer.io/admin/environments/add/docker/edge)
  dan [Edge Agent](https://docs.portainer.io/advanced/edge-agent) — agen yang menanyakan pekerjaan
  secara berkala tanpa membuka port di host, perintah pasang dari layar, id dan token per mesin.
  Sisi server Portainer tetap menerima koneksi masuk; yang tanpa port masuk hanya mesin pelanggan.
- [Azure Arc — onboard dari portal](https://learn.microsoft.com/en-us/azure/azure-arc/servers/onboard-portal)
  (28 Januari 2026), [persyaratan jaringan](https://learn.microsoft.com/en-us/azure/azure-arc/servers/network-requirements)
  (1 April 2026), dan [keamanan ekstensi](https://learn.microsoft.com/en-us/azure/azure-arc/servers/security-extensions)
  (28 Juli 2025) — skrip pasang dari portal, koneksi keluar lewat 443, daftar izin ekstensi yang
  disetel lokal. Catatan: "hanya 443" tidak sepenuhnya tepat; satu endpoint sertifikat memakai 80,
  dan agennya butuh daftar alamat yang diizinkan.
- [AWS Systems Manager — hybrid activation](https://docs.aws.amazon.com/systems-manager/latest/userguide/hybrid-activation-managed-nodes.html)
  dan [endpoint VPC](https://docs.aws.amazon.com/systems-manager/latest/userguide/setup-create-vpc.html)
  — kode dan id aktivasi, umur aktivasi, dan agen yang memulai seluruh koneksinya sendiri.
- [Rancher — mendaftarkan kluster yang sudah ada](https://ranchermanager.docs.rancher.com/how-to-guides/new-user-guides/kubernetes-clusters-in-rancher-setup/register-existing-clusters)
  dan [komunikasi dengan kluster hilir](https://ranchermanager.docs.rancher.com/reference-guides/rancher-manager-architecture/communicating-with-downstream-user-clusters)
  — perintah daftar dari layar, agen kluster yang membuka terowongan ke server. Halamannya tidak
  menulis "tanpa port masuk" secara harfiah; tabel port-nya yang menyiratkannya.
- [RFC 9421 — HTTP Message Signatures](https://www.rfc-editor.org/rfc/rfc9421) (Februari 2024) —
  standar IETF untuk menandatangani pesan HTTP.
- [Docker — Packet filtering and firewalls, bagian "Docker and ufw"](https://docs.docker.com/engine/network/packet-filtering-firewalls/)
  (26 Desember 2025) — port yang dipublikasikan container tidak melewati aturan UFW.
