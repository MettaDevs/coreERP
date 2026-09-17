# admin.erp: konsol operator

Halaman ini menjawab satu pertanyaan: **apa sebenarnya admin.erp itu, dan apa yang boleh dan tidak boleh
dilakukannya?** Ia aplikasi kedua di repo ini — `apps/control-plane`, namespace `ControlPlane\` — dan satu-satunya
yang dipakai orang kita, bukan pengguna klinik.

Aplikasi ini mengerjakan tiga hal: melahirkan tenant beserta lingkungannya, mengelola **server klien on-prem**
lewat agen, dan menyimpan setelan yang tidak boleh tinggal di `.env` server. Alur rilis yang memasoknya ada di
[Dari branch sampai server klien](29-alur-rilis-server-klien.md); registry-nya di
[Registry Harbor](30-registry-harbor.md); cara operator masuk di [SSO](32-sso.md).

## Anggapan yang keliru

| Anggapan | Yang sebenarnya |
| --- | --- |
| Konsol punya database sendiri | Databasenya **sama persis dengan Core**, satu koneksi. Konsol tidak punya folder `database/` dan tidak boleh punya migration: dua aplikasi yang bermigrasi ke satu tabel akan saling menimpa. Skema seluruhnya milik Core |
| Konsol menulis apa saja langsung ke tabel | Registry server klien memang ditulis langsung. Tetapi tenant, lingkungan, dan pembaruan armada **selalu lewat HTTP ke Core** — Core yang memiliki aturannya |
| Operator adalah peran baru | Tidak ada peran baru. Otoritasnya satu baris `provider_access.role = 'provider_admin'`, tabel yang sama yang dipakai gate Core |
| Konsol memanggil server klien | Tidak pernah. Server klien tidak dibuka dari internet; **agen yang menghubungi konsol**, dan konsol hanya menaruh operasi di antrean |
| Konsol memegang semua kunci | Kunci **privat rilis** tidak pernah ada di sini. Kunci **privat lisensi** justru harus ada, dan itu konsekuensi yang disebut apa adanya di bagian [Kunci dan rahasia](#kunci-dan-rahasia) |

## Bentuk aplikasinya

Konsol sengaja kurus: tanpa migration, tanpa antrean, tanpa broadcast; sesi dan cache memakai berkas justru
supaya ia tidak menuntut tabel. Alasannya ditulis di `apps/control-plane/bootstrap/app.php`.

| Folder | Isinya |
| --- | --- |
| `app/Models/` | Eloquent di atas tabel milik Core. `User` sengaja tanpa `$fillable`, sehingga `create()` gagal seketika: konsol tidak boleh melahirkan akun |
| `app/Sites/` | Seluruh domain server klien: menyiapkan situs, token pendaftaran, perintah pasang, antrean operasi, laporan agen, lisensi, DNS, dan penurunan keadaan pemasangan |
| `app/Environments/` | Lingkungan dan perintah ke Core. `InstalledModules` satu-satunya yang membuka database lingkungan lain |
| `app/Customers/` | Melahirkan tenant. Nol `INSERT`; isinya hampir seluruhnya penanganan kegagalan HTTP |
| `app/Registry/` dan `app/Dns/` | Harbor dan Cloudflare. Rahasianya di `console_settings`, terenkripsi |
| `app/Sso/` | Klien OIDC konsol, terpisah dari klien milik Core |
| `app/Audit/` | Satu-satunya penulis `operator_audit_events` |
| `app/Http/Controllers/Sites/` | Tiga pintu yang sengaja dipisah: `SiteScreens` hanya membaca, `SiteActions` menulis dan mengaudit, `SiteSettings` menyimpan setelan yang tidak memerintah server |

Layarnya Inertia + React di `resources/js/pages/`.

## Batas data: apa milik sisi pusat

Tabel yang hanya dibaca-tulis konsol ditandai di sisi Core dengan trait
`App\Support\ControlPlane\OwnedByControlPlane`. Hari ini trait itu tidak melakukan apa pun — koneksi terpisah
belum ada — dan itu memang disengaja: **batas yang mahal adalah batasnya, bukan koneksinya.** Ketika database
konsol kelak dipisah, yang berubah hanya setelan koneksi, bukan kode yang memakai tabelnya.

Aturannya satu kalimat: **setiap tabel baru yang hanya dipakai konsol wajib punya model penanda di Core.**
Daftar model penandanya ada di `apps/core/app/Models/` — cari `use OwnedByControlPlane`, dan penjaganya
`apps/core/tests/Feature/Boundary/BatasPusatTest.php`. Tabel yang lupa ditandai tidak membuat satu pun test
merah sampai seseorang memisahkan databasenya, dan pada saat itu sebabnya sudah lama terlupakan.

Test konsol membangun skemanya dari folder migration Core (`tests/CoreSchema.php`). Migration baru di Core yang
menyentuh tabel konsol karena itu langsung terasa di suite konsol — itu yang dimaksud "satu skema, dua aplikasi".

## Dua arah percakapan

### Ke Core: HTTP, bukan query

Tidak ada satu pun pemanggilan proses di `app/`. Semua perintah berjalan lewat API internal Core dengan bearer
token `CONTROL_PLANE_TOKEN`, dijaga middleware `ControlPlaneOnly` di sisi Core.

| Yang dikerjakan | Endpoint Core |
| --- | --- |
| Melahirkan tenant | `POST /api/internal/v1/tenants` |
| App yang dibeli tenant (untuk lisensi) | `GET /api/internal/v1/tenants/{tenant}/entitlements` |
| Menyiapkan lingkungan | `POST /api/internal/v1/environments/{id}/provision` |
| Keadaan armada | `GET /api/internal/v1/fleet` |
| Antre pembaruan | `POST /api/internal/v1/environments/upgrade` |

Tiga tenggat yang berbeda, dan bedanya bukan selera (`config/core.php`): permintaan biasa 30 detik, penyiapan
lingkungan 300 detik karena ia menunggu pekerjaan selesai, dan pembacaan entitlement paling pendek karena ia
dipanggil **dari dalam jawaban laporan agen** — laporan yang tertahan menunggu Core berarti agen yang menunggu.

Bentuk setiap panggilan ini dijaga `tests/Feature/CoreCommandContractTest.php`, yang membacanya dari
`apps/core/contracts/openapi-internal.yaml` — bukan dari tiruan yang ditulis bersama kodenya.

### Ke agen: permintaan bertanda tangan

Agen di server klien menghubungi `POST /api/agent/v1/*`. Seluruhnya bertanda tangan kunci situs kecuali
pendaftaran pertama, yang dijaga token berumur pendek. Bentuknya subset RFC 9421 (`rsa-v1_5-sha256`), dan
kontraknya `apps/control-plane/contracts/openapi-agent.yaml` — **ditulis tangan, dan ia sumber kebenaran**:
agen bash dibangun dari situ, dan `contracts/check-contract-coverage.py` menolak rute yang tidak tercatat.

Satu aturan yang mudah dilanggar: **situs tidak pernah dibaca dari isi permintaan.** Middleware
`VerifyAgentSignature` menitipkan situs yang tanda tangannya terbukti ke atribut permintaan, dan controller
membaca dari sana. Membaca `site_id` dari badan permintaan berarti situs yang menandatangani dan situs yang
diubah boleh berbeda.

Galat ke agen selalu `{"error": "<kode>"}` tanpa kalimat penjelas. Menyebut bagian mana yang salah mengajari
penyerang bagian mana yang sudah benar.

## Layar dan rutenya

Alamat rute berbahasa Indonesia, nama kelas dan method berbahasa Inggris. `/situs` tampil sebagai "Server
klien" di layar.

| Layar | Rute | Siapa |
| --- | --- | --- |
| Tenant | `/tenant` | operator |
| Lingkungan, panel Server klien, perintah pasang | `/lingkungan`, `/lingkungan/{id}` | operator |
| Pembaruan armada | `/pembaruan` | operator |
| Server klien dan rinciannya | `/situs`, `/situs/{id}` | operator |
| Pengaturan: kunci, registry, masa lisensi | `/pengaturan` | operator |
| Akun operator dan penautan SSO | `/akun` | operator |
| Berkas pemasang | `/pasang.sh`, `/agen/*` | **tanpa login**, dibatasi laju |
| API agen | `/api/agent/v1/*` | tanda tangan kunci situs |
| Pendaftaran rilis | `/api/releases/v1` | token rilis + tanda tangan berkas |

`/pasang.sh` dan `/agen/*` terbuka karena teknisi di lokasi klien belum punya apa pun untuk masuk. Yang
mengesahkan pemasangan bukan sesi, melainkan token pendaftaran berumur pendek yang dibuat operator.

Setiap rute layar memakai **dua** middleware, `auth` dan `operator`. Bukan salah satunya: `auth` saja berarti
setiap pengguna tenant yang punya akun dapat membuka armada seluruh pelanggan.

## Siapa yang boleh masuk

- **Operator** adalah pengguna Core yang punya baris `provider_access` dengan peran `provider_admin`. Tidak
  ada tabel pengguna terpisah, tidak ada undangan, tidak ada reset kata sandi di sini — semuanya tetap di Core.
- Yang bukan operator menerima **404, bukan 403**. Alamat yang menjawab "dilarang" tetap memberi tahu bahwa
  alamat itu ada.
- Masuk dapat lewat kata sandi atau lewat SSO; pintu kata sandi dapat ditutup, dengan daftar alamat surel yang
  tetap boleh masuk ketika penyedia identitas sedang mati. Rinciannya di [SSO](32-sso.md).

## Kunci dan rahasia

| Rahasia | Tempatnya | Catatan |
| --- | --- | --- |
| Kunci publik rilis | Berkas di server konsol | Disajikan ke agen saat pemasangan pertama; tidak rahasia |
| Kunci privat lisensi | Berkas di server konsol | **Harus** ada di sini. Konsol yang dibobol karena itu dapat menerbitkan lisensi untuk app yang tidak dibeli — itu harga dari lisensi yang diterbitkan otomatis |
| Robot sistem Harbor, token Cloudflare | `console_settings`, terenkripsi `Crypt` | Disimpan lewat perintah artisan yang membaca **stdin**, bukan argumen: argumen proses terbaca `ps` dan tersimpan di riwayat shell |
| Masa lisensi bawaan | `console_settings`, terbuka | Diubah operator di `/pengaturan` |

Halaman Pengaturan menampilkan **sidik jari** kunci, tidak pernah isinya, dan menyebut apakah kunci privat dan
publik lisensi berpasangan. Pasangan yang tidak cocok adalah kegagalan paling sunyi yang ada di sistem ini:
lisensi tetap terbit, agen menerima kunci publik yang salah, lalu setiap lisensi ditolak di server klien.

## Jejak audit

Setiap tindakan operator yang mengubah sesuatu menulis satu baris `operator_audit_events` **di transaksi yang
sama dengan perubahannya**. Tabelnya hanya-tambah: trigger database menolak `UPDATE` dan `DELETE`, dan tidak ada
kolom `updated_at` yang menggoda siapa pun untuk menyuntingnya.

Dua jalur penulisan, dan bedanya penting: `OperatorAudit::record()` untuk tindakan yang punya operator, dan
`recordBySystem()` yang selalu menulis `user_id` kosong — dipakai perpanjangan lisensi dan penerbitan
kredensial registry, yang lahir dari laporan agen, bukan dari klik siapa pun. Daftar nilai `action` yang ada
dibaca dari kodenya: `git grep "OperatorAudit::record" apps/control-plane/app`.

Detailnya tidak pernah memuat nilai rahasia: bukan tanda tangan lisensi, bukan token pendaftaran, bukan kata
sandi sementara.

## Aturan yang dijaga, dan alasannya

- **Konsol tidak memiliki skema.** Tidak ada `database/`, tidak ada migration, dan sesi serta cache memakai
  berkas supaya tidak ada tabel yang lahir diam-diam.
- **Tenant dan lingkungan lewat Core.** Menulisnya langsung akan melewati aturan Core yang tidak diketahui
  konsol — slug yang dicadangkan, entitlement, hierarki organisasi.
- **Entitlement dibaca lewat HTTP walau tabelnya di database yang sama**, dan `tenant_id` di jawaban
  dicocokkan, bukan sekadar diperiksa ada. Hanya status tepat `200` yang diterima: jawaban `202` yang
  ditafsirkan sebagai daftar kosong akan menerbitkan lisensi "hanya Core" untuk tenant yang membeli semuanya.
- **"Apa yang terpasang" dan "apa yang boleh terpasang" adalah dua pertanyaan.** `InstalledModules` menjawab
  yang pertama dari database lingkungan; entitlement menjawab yang kedua. Layar yang menukarnya pernah
  menampilkan modul yang belum dibeli sebagai "Terpasang", dan yang menemukannya adalah orang yang
  menjalankannya, bukan test.
- **Dua salinan aturan yang disengaja.** Penyusun alamat lingkungan dan pemeriksa token SSO ada di Core dan di
  konsol, karena keduanya image terpisah. Yang menahan keduanya sepakat adalah **test kembar**, bukan kode
  bersama: kalau salah satu berubah, ubah keduanya, dan Core yang berwenang.
- **Tindakan operator tidak lagi meminta nama situs diketik ulang** (16 September 2026). Penjaga itu dibuang
  karena nama situs memuat tanda pisah panjang yang tidak ada di papan ketik, sehingga satu-satunya cara
  melewatinya adalah menyalin-tempel — dan penjaga yang selalu dilewati dengan salin-tempel tidak membuat
  siapa pun membaca apa yang diketiknya. Yang menahan kesalahan sekarang adalah jejak audit dan sifat operasinya
  sendiri yang dapat dibatalkan.

## Menguji konsol

```bash
cd apps/control-plane
php vendor/phpunit/phpunit/phpunit                   # seluruh suite
php vendor/phpunit/phpunit/phpunit --filter Sites    # hanya server klien
composer lint:check                                  # Pint, termasuk modules/
php vendor/bin/phpstan analyse --memory-limit=1G
```

Dua penjaga di `tests/TestCase.php` berdiri karena suite pernah mengosongkan database kerja pada 12 September
2026: yang pertama menolak berjalan di luar koneksi test, yang kedua menolak database test yang namanya sama
dengan database kerja. Keduanya membaca keadaan yang **benar-benar dipakai koneksi**, bukan niat di berkas
konfigurasi — versi sebelumnya membaca `getenv()` dan tidak pernah bisa merah, karena `config/database.php`
memaku nilainya sendiri.

Seluruh test memakai `Http::fake()` beserta `preventStrayRequests()`, kecuali satu: kontrak perintah ke Core.
Alasannya pernah terbukti mahal — Core memeriksa `Authorization: Bearer` sementara konsol mengirim header lain,
dan **kedua suite tetap hijau**. Tiruan selalu setuju dengan yang menirukannya.

Cloudflare pun ditiru dengan tiruan **berkeadaan** (`tests/Feature/Sites/FakeCloudflare.php`): record yang
dibuat dapat dibaca dan dihapus lagi, sehingga urutan yang salah terlihat sebagai kegagalan, bukan sebagai
jawaban tetap yang selalu cocok.

## Yang belum ada

- **Database terpisah untuk sisi pusat.** Penandanya sudah berdiri, koneksinya belum.
- **Peran operator selain `provider_admin`.** Hari ini satu peran mengerjakan segalanya.
- **Halaman yang menampilkan jejak audit lintas situs.** Jejaknya tercatat; yang membacanya baru layar per
  situs dan SQL.

## Halaman terkait

- [Dari branch sampai server klien](29-alur-rilis-server-klien.md) — rilis, perakit, dan agen.
- [Registry Harbor](30-registry-harbor.md) — kredensial per operasi dan penarikan image.
- [SSO](32-sso.md) — cara operator dan pengguna tenant masuk.
- [Release dan on-prem](03-release-and-on-prem.md) — aturan migration kompatibel mundur.
- `docs/todo/on-prem-dikelola/`, `docs/todo/lisensi-mengunci/`, `docs/todo/pasang-satu-perintah/` — rancangan
  yang melahirkan layar-layar ini.
