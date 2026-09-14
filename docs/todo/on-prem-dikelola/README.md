# On-prem yang dikelola vendor

Rencana kerja, bukan desain kanonik. Ditulis 14 September 2026 setelah seorang calon klien meminta
aplikasi **disewa**, **dipasang di server miliknya sendiri**, tetapi **tetap kita yang mengelola**.

## Pertanyaan yang harus dijawab halaman ini

- Bagaimana admin provider mengelola server milik klien **hanya dari admin.erp**, tanpa SSH ke
  setiap server satu per satu?
- Bagaimana server yang berada di belakang router klinik — tanpa IP publik, tanpa port masuk —
  tetap dapat diperbarui?
- Bagaimana klien yang **tidak punya internet keluar** tetap dapat dipasang, diperbarui, dan
  dipantau?
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
| Internet keluar | ada | tidak dijamin | **biasanya ada, tidak dijamin** |

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
- **Paket offline**: paket pendaftaran, dan file laporan yang dibawa pulang.
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
| K3s, pemasangan *air-gap* | Arsip image dipindahkan ke mesin tanpa internet lalu dimuat di sana — padanan bundle |

Satu catatan dari Azure yang berlaku sama di sini: siapa pun yang memegang root di server dapat
mengubah konfigurasi agennya. Daftar tertutup menjaga server klien dari admin.erp yang dibobol,
**bukan** dari orang yang sudah menguasai server klien itu sendiri.

### Satu paket, dua jalan antar

Yang dikerjakan agen selalu **paket bertanda tangan**: rilis yang dipasang, lisensi yang berlaku,
atau perintah yang diminta. Cara paket itu sampai ke server yang berbeda:

| | Klien dengan internet keluar | Klien tanpa internet |
| --- | --- | --- |
| Pendaftaran pertama | Perintah satu baris dari admin.erp | admin.erp membuat **paket pendaftaran**, dibawa dengan flashdisk |
| Pembaruan | Agen menarik perintah dan image sendiri | admin.erp menyiapkan **bundle** dari `build-bundle.sh`, dibawa dengan flashdisk |
| Laporan balik | Heartbeat berkala | Agen menulis **file laporan bertanda tangan**, dibawa pulang dan diunggah ke admin.erp |
| Lisensi | Diperbarui otomatis | File lisensi baru ikut di flashdisk |

Agen **tidak membedakan** dari mana paketnya datang. Ia memeriksa tanda tangannya, lalu
mengerjakannya atau menolaknya. Dua jalan antar, satu jalur kode — itu yang mencegah jalur offline
menjadi jalur kedua yang tertinggal dan hanya ketahuan rusak di tempat klien.

Klien yang internetnya putus-sambung tidak memerlukan keputusan tersendiri: laporannya menumpuk di
server, lalu terkirim ketika sambungannya kembali.

### Daftar tertutup: yang boleh diminta dari agen

Agen hanya mengenal operasi yang tertulis di dirinya sendiri. admin.erp memilih operasi dan
parameternya; ia **tidak pernah** mengirim perintah shell.

| Operasi | Parameter | Keterangan |
| --- | --- | --- |
| `perbarui` | nomor rilis | Rilis harus bertanda tangan kunci rilis. Dijalankan lewat `update.sh` |
| `cadangkan` | — | Cadangan sekarang, di luar jadwal |
| `pasang-lisensi` | file lisensi | Lisensi harus bertanda tangan kunci rilis |
| `putar-kredensial` | — | Agen membuat kredensial baru dan membuang yang lama |
| `kirim-diagnosa` | — | Hanya isi yang tercantum di [data yang boleh keluar](#data-yang-boleh-keluar-dari-server-klien) |

Yang **sengaja tidak ada**, dan alasannya:

- **Shell jarak jauh.** Satu perintah bebas sudah cukup untuk membaca seluruh database pasien.
  Dukungan yang benar-benar butuh masuk ke server memakai kanal terpisah yang dinyalakan klien
  sendiri — VPN yang tersambung keluar — dan dimatikan sesudahnya.
- **Memulihkan cadangan dari jauh.** Pemulihan menimpa data yang ditulis sesudah cadangan diambil.
  Keputusan seperti itu diambil bersama klien dan dijalankan di tempat, bukan dengan satu tombol.
- **Mengubah berkas compose atau setelan sembarang.** Perubahan setelan datang lewat rilis, yang
  bertanda tangan dan melewati CI.

### Dua kunci, dua peran

| Kunci | Menandatangani | Disimpan di | Kalau bocor |
| --- | --- | --- | --- |
| **Kunci rilis** | bundle, manifest rilis, lisensi | di luar admin.erp, dipakai alur rilis | Rilis palsu dapat dibuat — kunci ini yang paling dijaga |
| **Kredensial situs** | permintaan agen ke admin.erp, file laporan | server klien; admin.erp hanya menyimpan hash-nya | Hanya situs itu yang dapat ditiru; kredensialnya diputar |

Bentuk tanda tangan permintaan agen tidak dikarang sendiri: HTTP Message Signatures (RFC 9421)
adalah standar IETF untuk menandatangani pesan HTTP, termasuk dengan HMAC yang dapat dihitung
`openssl` di bash.

Pemisahan ini yang menentukan seberapa buruk admin.erp yang dibobol. **admin.erp tidak memegang
kunci rilis**, jadi penyerang yang menguasainya hanya dapat meminta agen memasang rilis yang memang
kita terbitkan. Ia tidak dapat membuat rilis baru.

Satu celah tersisa dan disebut apa adanya: meminta agen **mundur ke rilis lama** yang sah tetapi
punya cacat keamanan. Karena itu nomor rilis di manifest wajib naik terus, dan agen menolak
`perbarui` ke nomor yang lebih kecil dari yang sedang terpasang. Mundur hanya terjadi di dalam
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

### Mendaftarkan situs — dengan internet

1. Operator membuat **situs baru** di admin.erp: tenant, profil `on-prem-dikelola`, edisi, alamat,
   dan jendela pembaruan yang disepakati dengan klien.
2. admin.erp menampilkan **perintah pasang satu baris**. Token di dalamnya hanya berlaku sekali dan
   kedaluwarsa dalam satu jam.
3. Perintah itu dijalankan sekali di server klien. Skripnya memasang Docker bila belum ada, memasang
   agen, lalu mendaftarkan situs dengan token tadi.
4. Saat mendaftar, agen **membuat kredensialnya sendiri** di server. admin.erp hanya menerima dan
   menyimpan hash-nya — token pendaftaran tidak pernah menjadi kredensial jangka panjang.
5. **Rahasia aplikasi dibuat di server itu juga** — `APP_KEY`, kata sandi database, kata sandi admin
   awal — dan tidak pernah dikirim ke admin.erp. Kata sandi admin awal ditampilkan sekali di
   terminal pemasang.
6. Agen menarik rilis pertama dan menjalankan `update.sh`. Laporan pertamanya menandai situs
   **hidup** di admin.erp.

### Mendaftarkan situs — tanpa internet

Langkah 1 sama. Pada langkah 2, admin.erp membuat **paket pendaftaran** berisi id situs, kunci
publik rilis, lisensi, dan bundle rilis pertama. Paket itu dibawa ke server dengan flashdisk, lalu
skrip pasang yang sama dijalankan dengan paket sebagai sumber. Kredensial dan rahasia tetap dibuat
di server, sama seperti jalur online.

Situs offline tampil di admin.erp sebagai **terakhir terlihat lewat laporan**, dengan tanggal file
laporan terakhir yang diunggah — bukan sebagai "hidup", karena admin.erp memang tidak tahu keadaan
sesudah tanggal itu.

### Memperbarui

Operator menekan **Perbarui ke rilis X** di rincian situs. Operasinya tercatat `requested`. Agen
mengambilnya pada kunjungan berikutnya **di dalam jendela pembaruan**, memegang *lease*, menjalankan
`update.sh`, dan melaporkan tiap langkah — sampai `succeeded`, atau `failed` beserta langkah tempat
ia berhenti dan sebabnya.

Untuk situs offline, tombol yang sama menghasilkan bundle untuk diunduh. Hasilnya tercatat ketika
file laporan sesudah pemasangan diunggah.

### Mencabut situs

Operator mencabut situs di admin.erp: kredensialnya berhenti diterima dan operasi yang tertunda
dibatalkan. **Aplikasi di server klien tetap berjalan** — mencabut situs menghentikan pengelolaan,
bukan pelayanan pasien.

## Tenant: satu identitas di dua tempat

Catatan komersial tenant — klien, edisi, masa sewa — tinggal di admin.erp. Data tenant tinggal di
server klien. Keduanya harus menyebut **id tenant yang sama**, supaya laporan, tiket dukungan, dan
SSO menunjuk orang yang sama tanpa tabel penerjemah.

Hari ini tenant on-prem lahir lewat pendaftaran usaha di server itu sendiri, dengan id baru. Yang
dibutuhkan: perintah Core yang melahirkan tenant **dengan id yang diberikan**, dijalankan skrip pasang
dari data situs.

## Lisensi: tanda, bukan kunci

Lisensi adalah file bertanda tangan kunci rilis: id tenant, id situs, edisi, dan tanggal berakhir.
Core membacanya dan **menampilkan peringatan** ketika tanggal itu mendekat atau lewat.

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
| Syarat | tanpa internet | internet keluar, atau dibawa pulang untuk klien offline |

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

| Bagian | Tempat | Isi |
| --- | --- | --- |
| Daftar situs, antrean operasi, laporan | migration baru di `apps/core/database/migrations` | mengikuti pola registry lingkungan: aturan ditegakkan PostgreSQL |
| Jejak audit operator | migration baru, tabel hanya-tambah | siapa, situs mana, operasi apa, kapan, hasilnya |
| Layar | `apps/control-plane`, menu **Situs** | daftar, situs baru, rincian |
| API agen | `apps/control-plane`, rute `api/agen/v1` | daftar, heartbeat, ambil operasi, lapor langkah, unggah laporan |
| Kontrak API agen | `apps/core/contracts/` | ditulis, bukan dibangkitkan; dijaga pemeriksa cakupan |
| Agen | folder baru `deploy/agent/` | bash dengan systemd timer; memanggil `update.sh` |
| Skrip pasang | `deploy/agent/pasang.sh` | Docker, agen, pendaftaran, rahasia yang dibuat di tempat |
| Profil | `editions/*.yaml` menerima `profil: on-prem-dikelola` | edisi yang sama dengan jalur pengelolaan yang berbeda |
| Katalog rilis | `release.yml` mendaftarkan rilis ke admin.erp | admin.erp tahu rilis mana yang dapat dipilih per edisi |
| Lisensi | Core membaca file lisensi dan menampilkan peringatan | tanpa mengunci |
| Tenant dengan id tertentu | perintah artisan baru di Core | dijalankan skrip pasang |

Agen ditulis dalam **bash**, bukan Go. Logika pembaruannya sudah ada dalam bash (`update.sh`), dan
tim yang merawatnya satu engineer dengan tiga anak magang. Bahasa kedua di satu repo berarti satu
rantai build, satu cara uji, dan satu kebiasaan baru. Pertimbangan ini dibuka lagi bila jumlah situs
sudah puluhan.

## Tabel baru

Sketsa, bukan skema final. Nama kolom mengikuti migration yang sudah ada.

**`sites`** — satu server klien.

- `id`, `tenant_id`, `profile` (`on-prem-dikelola`), `edition`, `address`
- `desired_release`, `reported_release`, `reported_digest`
- `update_window` — jendela pembaruan yang disepakati
- `connectivity` — `online` atau `offline`
- `last_seen_at`, `last_seen_via` — heartbeat atau file laporan
- `credential_hash`, `enrolled_at`, `revoked_at`

**`site_enrollments`** — token pendaftaran: hash-nya, `expires_at`, `used_at`. Satu kali pakai
ditegakkan database, bukan kode.

**`site_operations`** — antrean. Berbeda dari `environment_operations` pada satu hal yang
menentukan: ia punya keadaan `requested`, karena operasinya **menunggu diambil** agen yang
berkunjung kemudian.

- `status`: `requested` → `running` → `succeeded` / `failed`; atau `cancelled` / `expired` bila
  tidak pernah diambil
- `lease_until` — tanpa tenggat, agen yang mati di tengah jalan memegang operasi selamanya
- `step`, `failure_message` — gagal wajib beralasan, seperti di registry lingkungan
- satu operasi `running` per situs, ditegakkan partial unique index

**`site_reports`** — isi laporan dari [daftar yang boleh keluar](#data-yang-boleh-keluar-dari-server-klien),
beserta asalnya: heartbeat atau file yang diunggah.

**`operator_audit_events`** — hanya-tambah. Tidak ada `UPDATE` maupun `DELETE` yang diizinkan
aplikasi terhadapnya.

## Layar di admin.erp

- **Situs** — daftar situs: tenant, profil, rilis terpasang, keadaan, terakhir terlihat. Situs yang
  laporannya tertinggal ditandai; heartbeat yang berhenti tidak boleh terlihat sama dengan "sehat".
- **Situs baru** — isian, lalu perintah pasang atau paket pendaftaran.
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
| Tanpa jejak audit operator | tidak ada satu pun catatan | `operator_audit_events` berdiri sebelum operasi `perbarui` dapat dibuat |
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
- **Mode tanpa internet**: aturan firewall keluar yang menolak semua kecuali SSH, dinyalakan hanya
  saat menguji jalur offline.

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

- `perbarui` dari admin.erp berjalan sampai `succeeded`, dengan langkah-langkahnya tampil.
- Pembaruan yang gagal tampil `failed` beserta langkah dan sebabnya, dan server kembali ke rilis
  lama.
- Operasi di luar jendela pembaruan menunggu, tidak dijalankan.
- `perbarui` ke rilis yang tanda tangannya salah **ditolak agen**; ke nomor rilis yang lebih kecil
  **ditolak agen**.
- Agen yang dimatikan di tengah operasi: *lease*-nya habis, operasinya tidak menggantung selamanya.
- Setiap operasi punya baris jejak audit yang menyebut operatornya.

**Tahap 3 — offline.**

- Dengan firewall keluar menutup semua, situs didaftarkan dari paket pendaftaran, diperbarui dari
  bundle, dan file laporannya diunggah ke admin.erp.
- File laporan yang diubah setelah ditandatangani **ditolak** admin.erp.
- Situs offline tampil "terakhir terlihat lewat laporan", bukan hidup.

**Tahap 4 — lengkap.**

- Cadangan bencana terkirim terenkripsi, dan **dipulihkan ke server lain** sampai aplikasinya
  menyala. Cadangan yang tidak pernah dicoba dipulihkan belum terbukti ada.
- Lisensi yang lewat tanggal menampilkan peringatan, dan aplikasi tetap dapat dipakai.
- Situs yang dicabut: kredensialnya ditolak, aplikasinya tetap melayani.

## Keputusan yang menunggu ketok

Masing-masing disertai usulan. Yang belum diketok tidak dikerjakan seolah sudah diputuskan.

| Keputusan | Usulan | Yang dibeli | Yang dibayar |
| --- | --- | --- | --- |
| Bahasa agen | bash | tim dapat membacanya; `update.sh` dipakai apa adanya | parsing JSON dan penanganan galat lebih kasar daripada Go |
| Tempat tabel situs | migration Core, seperti registry lingkungan | satu jalur migrasi yang sudah terbukti | kopling database admin.erp–Core bertambah satu kelompok tabel |
| Memulihkan cadangan dari jauh | tidak ada di versi pertama | satu tombol yang dapat menimpa data pasien tidak ada | pemulihan menuntut kehadiran atau VPN dukungan |
| Lisensi habis | peringatan, tanpa mengunci | pelayanan pasien tidak pernah berhenti karena tagihan | penagihan bergantung kontrak |
| Id tenant | sama di admin.erp dan server klien | tanpa tabel penerjemah | butuh perintah pembuat tenant dengan id tertentu |
| Registry image | GHCR, token baca per situs bila paketnya privat | tanpa registry sendiri | server klien bergantung pada GHCR untuk jalur online |
| Izin pembaruan dari vendor | tertulis di kontrak sewa, dengan jendela pembaruan | dasar untuk menjalankan pembaruan tanpa bertanya setiap kali | kontrak on-prem dikelola berbeda dari beli putus |

## Perkiraan ukuran

Perkiraan kasar untuk satu engineer. Yang paling sulit ditebak Tahap 0, karena menyangkut mesin
yang belum pernah dilihat.

| Tahap | Perkiraan |
| --- | --- |
| 0 — pasang dengan tangan, termasuk di belakang reverse proxy | 2–4 hari |
| 1 — situs, perintah pasang, heartbeat | sekitar 1 minggu |
| 2 — tombol perbarui, antrean, jejak audit | 1–2 minggu |
| 3 — jalur offline | sekitar 1 minggu |
| 4 — cadangan bencana, lisensi, mencabut situs | 1–2 minggu |

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
- [K3s — Air-Gap Install](https://docs.k3s.io/installation/airgap) (14 Agustus 2026) — arsip image
  yang dipindahkan ke mesin tanpa internet.
- [RFC 9421 — HTTP Message Signatures](https://www.rfc-editor.org/rfc/rfc9421) (Februari 2024) —
  standar IETF untuk menandatangani pesan HTTP.
- [Docker — Packet filtering and firewalls, bagian "Docker and ufw"](https://docs.docker.com/engine/network/packet-filtering-firewalls/)
  (26 Desember 2025) — port yang dipublikasikan container tidak melewati aturan UFW.
