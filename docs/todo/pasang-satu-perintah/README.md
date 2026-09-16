# Pemasangan satu perintah dan panel "Server klien"

Rencana kerja, bukan desain kanonik. Ditulis 15 September 2026 dari keputusan pemilik produk
tanggal 14 dan 15 September: **operator bekerja hanya di admin.erp**, dan di server klien satu-satunya
langkah manusia adalah **menempel satu perintah, sekali**. Pola rujukannya pemasangan agen Portainer,
Rancher, Coolify, dan Tailscale.

Halaman ini mengikat tiga halaman lain:

| Halaman | Yang diambil dari sana |
| --- | --- |
| [On-prem yang dikelola vendor](/todo/on-prem-dikelola/) | agen yang menarik, daftar operasi tertutup, kunci situs, tanda tangan permintaan |
| [Registry image sendiri dengan Harbor](/todo/registry-harbor/) | dari mana image ditarik — CP-03 kredensial registry, AG-01 pull lewat digest, PK-02 rilis terdaftar |
| [Lisensi yang mengunci](/todo/lisensi-mengunci/) | app yang boleh dibuka, perpanjangan lewat laporan agen |

## Alur yang dituju

**Di admin.erp**

1. **Tenant baru** → lingkungan pertama **"Produksi di server klien"**. Lingkungan itu tercatat dengan
   `hosting = client_server`, dan server kita tidak pernah melayaninya.
2. Halaman lingkungan → panel **Server klien** → **Siapkan server klien**. Tidak ada isian wajib: nama
   situs otomatis, app mengikuti yang dibeli tenant. Alamat aplikasi dan jam pembaruan ada di *Lanjutan*
   dan boleh diisi belakangan.
3. **Buat perintah pasang** → tampil **sekali**:
   - `curl -fsSL https://admin.erp.grenery.xyz/pasang.sh | sudo bash -s -- --token <token>`;
   - kata sandi sementara admin klien beserta emailnya.

**Di server klien**

4. Perintah itu ditempel. Ia memasang Docker bila belum ada, memasang agen, mendaftar ke admin.erp,
   lalu menunggu sampai rilis terpasang dan tenant beserta admin pertamanya lahir.

**Kembali di admin.erp, halaman yang sama**

5. Progres tampil sendiri: **Menunggu perintah dijalankan → Server tersambung → Memasang → Siap**.
   Kegagalan menampilkan langkah dan sebabnya, dengan tombol **Coba lagi**.
6. Sesudahnya: Perbarui, Cadangkan, Lisensi, Ganti kunci, Diagnosa, Cabut — tombol.

## Keputusan

| Keputusan | Diambil | Kenapa |
| --- | --- | --- |
| Tempat panel | Halaman **lingkungan produksi**, bukan formulir situs berdiri sendiri | Formulir lama bertanya hal yang sudah diketahui sistem dan tidak menyebut apakah sesuatu sudah terpasang |
| Kata sandi admin klien | Dibuat admin.erp, ditampilkan sekali ke operator, **hanya hash bcrypt** yang dikirim ke agen, wajib diganti saat masuk pertama | Sama dengan pembuatan tenant SaaS oleh operator; kata sandi tidak pernah melewati argumen proses atau log agen |
| Biaya hash | Mengikuti `BCRYPT_ROUNDS` admin.erp; Core di server klien menolak biaya yang lebih tinggi dari setelannya | Keduanya memakai bawaan 12; perbedaan setelan ditolak dengan pesan, bukan akun yang tidak dapat dimasuki |
| Asal berkas pemasang | **Disajikan admin.erp** (`/pasang.sh`, `/agen/*`), dibakar ke image konsol dari commit yang sama | Repo kembali privat; GitHub raw tidak dapat dijangkau server klien |
| Kepercayaan kunci rilis saat memasang | Diambil dari admin.erp saat pemasangan pertama, lalu dipaku agen | Tidak ada jalur kedua yang independen setelah repo privat; mengganti kunci yang sudah dipaku tetap ditolak (AG-03 menambah kunci cadangan) |
| Operasi `install` | Boleh diklaim kapan saja, tidak menunggu jendela pembaruan, tetapi **hanya bila rilisnya sudah ditentukan** | Pemasangan pertama dilakukan saat teknisi ada di lokasi |
| Hash kata sandi di database | Dihapus dari `site_operations.parameters` begitu operasi `install` selesai, gagal, kedaluwarsa, atau dibatalkan | Hash yang tidak lagi dibutuhkan hanya menunggu dibocorkan |

## Kontrak

### Core — sudah ada di PR #124

- `environments.hosting` (`provider` / `client_server`), dan API internal pembuatan tenant menerima
  `first_environment_hosting`.
- `sites.environment_id` — satu situs per lingkungan.
- Operasi `install` di constraint `site_operations`.
- `tenant:bootstrap-site --tenant-id --name --admin-name --admin-email --admin-password-hash-stdin --app=...`:
  hash dibaca dari stdin, aman diulang untuk owner yang sama.

### admin.erp

| ID | Pekerjaan | Selesai bila |
| --- | --- | --- |
| PS-01 | Pilihan "Produksi di server klien" saat membuat tenant, diteruskan sebagai `first_environment_hosting` | Tenant lahir dengan lingkungan produksi `client_server`; test |
| PS-02 | Panel **Server klien** di halaman lingkungan produksi `client_server`: belum disiapkan → **Siapkan server klien** (membuat situs: `environment_id`, nama `<tenant> — Produksi`, profil `managed_on_prem`), diaudit | Situs tidak dapat dibuat dua kali untuk lingkungan yang sama; test |
| PS-03 | **Buat perintah pasang**: token pendaftaran baru (token lama yang belum dipakai dibatalkan), operasi `install` sebelumnya yang masih `requested` dibatalkan, kata sandi sementara dibuat dan di-hash, operasi `install` dibuat dengan `{release, tenant_id, tenant_name, app_ids, admin_name, admin_email, admin_password_hash}`; perintah dan kata sandi ditampilkan sekali | Kata sandi tidak tersimpan dalam bentuk teks di mana pun; `release` diisi rilis terdaftar terbaru, atau kosong dengan pesan "Belum ada rilis" |
| PS-04 | Progres langsung (muat ulang parsial berkala) dan tombol **Coba lagi** | Keadaan tampil benar di setiap tahap, termasuk gagal |
| PS-05 | Status yang sama di daftar lingkungan dan ringkasan situs: Belum disiapkan / Menunggu perintah dijalankan / Memasang / Jalan (rilis X) / Tertinggal / Gagal / Dicabut. Formulir "Situs baru" berdiri sendiri dibuang | Daftar situs menaut ke halaman lingkungannya |
| PS-06 | Aturan klaim: `install` tidak terikat jendela pembaruan tetapi butuh `release`; hash kata sandi dihapus dari parameter saat operasinya ditutup | Test untuk setiap jalur penutupan |
| PS-07 | Berkas pemasang disajikan tanpa login, dibatasi laju: `GET /pasang.sh` (alamat admin.erp disisipkan), `GET /agen/{coreerp-agent,coreerp-agent.service,coreerp-agent.timer,env.template,update.sh}`, `GET /agen/kunci-rilis.pub`; disalin ke image konsol oleh `apps/control-plane/Dockerfile` | Berkas yang disajikan sama byte dengan yang di repo pada commit image; test |
| PS-08 | Halaman **Pengaturan** untuk operator: sidik jari kunci publik rilis, keadaan kunci lisensi, dan tempat bagian Harbor (CP-06 milik PRD Harbor) | Kunci yang hilang tampil sebagai kesalahan yang jelas |

### Agen

| ID | Pekerjaan | Selesai bila |
| --- | --- | --- |
| PA-01 | `pasang.sh` hanya menerima `--token` (plus `--app-port`, `--app-bind`); alamat admin.erp tertanam di berkas yang disajikan; seluruh berkas agen diambil dari admin.erp | Suite agen menjalankan pemasangan terhadap admin.erp tiruan tanpa GitHub |
| PA-02 | Sesudah mendaftar, `pasang.sh` menjalankan putaran agen sambil mencetak progres yang dapat dibaca manusia, sampai operasi `install` selesai atau 90 menit, lalu menyalakan timer | Terminal teknisi menunjukkan kapan selesai dan apa yang harus dibuka |
| PA-03 | Operasi `install`: ambil rilis → jalankan pembaruan pertama → `tenant:bootstrap-site` dengan hash lewat stdin dan `--app` per app → laporkan langkah | Kata sandi dan hash tidak pernah tampil di log agen; operasi diulang aman |
| PA-04 | Subperintah manual `bootstrap-tenant` dibuang setelah PA-03 lulus uji di server kedua | Grep bersih |

**Titik singgung dengan PRD Harbor.** Langkah "ambil rilis" di PA-03 memakai jalur yang sama dengan
operasi `upgrade`. Sampai AG-01 selesai, PA-03 memakai jalur yang ada di `main`; begitu AG-01 masuk,
keduanya menarik dari Harbor tanpa perubahan di PA-03. PA-01 dan AG-03 sama-sama menyentuh
`kunci-rilis.pub` — yang menyelesaikan lebih dahulu memberi tahu yang lain.

## Keadaan 15 September 2026

PS-01 sampai PS-08 dan PA-01 sampai PA-03 berdiri di cabang `feat/pasang-satu-perintah` dan lulus
suite konsol, Core, dan agen. **Belum pernah dijalankan di server sungguhan.** PA-04 menunggu uji di server
kedua.

Yang ditemukan saat bagian-bagiannya dipertemukan:

- `tenant:bootstrap-site` tanpa `--app` memberi **seluruh** modul di image. Pada operasi `install` itu
  berarti tenant yang hanya membeli Core diberi semuanya. Kini jalur `--admin-password-hash-stdin` membaca
  daftar `--app` persis, termasuk bila kosong (PR #124).
- Parameter operasi `install` membawa `edition` (konstanta image tunggal `coreerp`), karena berkas rilis
  diambil lewat `releases/{edition}/{release}` yang sama dengan `upgrade`.
- Agen menolak alamat admin.erp yang memuat bagian pengguna (`http://127.0.0.1:1@host`): pemotong host
  membacanya localhost, padahal permintaannya dikirim ke host lain tanpa TLS.
- Hash kata sandi sementara dijaga constraint PostgreSQL: operasi yang sudah ditutup tidak boleh lagi
  membawanya.

### Sesudah dicoba pemilik produk dari admin.erp

Tiga celah layar ditemukan saat pemilik produk menyiapkan uji di server kedua, dan ditutup di cabang
`feat/konsol-hosting-situs`:

- **Produksi di server klien hanya dapat lahir bersama tenant baru.** Dialog "Buat lingkungan" tidak
  menanyakan tempat berjalan, jadi tenant yang produksinya dibuat belakangan tidak punya jalan ke server
  klien. Kini dialog itu menawarkan **Server kita** atau **Server klien** untuk produksi, dan server menolak
  server klien untuk jenis lain.
- **Layar Situs tidak menjawab untuk apa ia ada.** Ia berganti kata menjadi **Server klien** (alamatnya tetap
  `/situs`) dan menjadi daftar setiap VPS klien: alamat mesin, keadaan pemasangan, rilis terpasang terhadap
  rilis terbaru, masa lisensi, dan kapan serta dari IP mana agen terakhir melapor. Tombol **Tambah server
  klien** memilih produksi server klien yang belum punya server, lalu memakai pintu yang sama dengan panel
  lingkungan — bukan formulir "Situs baru" yang dibuang PS-05.
- **Alamat mesin tidak tercatat di mana pun.** `sites.address` adalah alamat aplikasi. Kolom baru
  `sites.server_address` menyimpan IP atau nama host yang dicatat operator, dan `sites.last_seen_ip` asal
  laporan agen terakhir. Keduanya boleh kosong, jadi kriteria "tanpa isian wajib" di bawah tetap berlaku.

Satu penyesuaian ikut: keadaan `stale` berbunyi "Tidak melapor" alih-alih "Tertinggal" dari PS-05, karena
daftar server klien kini juga menandai rilis yang tertinggal.

### Alamat aplikasi otomatis, record DNS, dan HTTPS di server klien

Sebelum uji di server kedua, pemeriksaan menemukan bahwa pemasangan akan "Jalan" tetapi tidak dapat dibuka:

- `pasang.sh` menulis `APP_URL=https://$(hostname -f)` — di server kedua itu domain pribadi pemilik mesinnya;
- kolom "Alamat aplikasi" di admin.erp hanya catatan yang tidak pernah sampai ke server klien;
- `<tenant>.erp.grenery.xyz` jatuh ke wildcard server kita, dan Core di sana tidak merutekan lingkungan server klien;
- aplikasi hanya mendengar di `127.0.0.1` dan wajib HTTPS, sedangkan agen tidak menyiapkan domain maupun sertifikat.

Keputusan pemilik produk: alamat dibentuk sistem (alamat milik klien belum didukung), record DNS dibuat otomatis
lewat Cloudflare, dan agen memasang proxy HTTPS sendiri.

- **admin.erp** (cabang `feat/alamat-otomatis-server-klien`): produksi server klien memakai bentuk alamat
  produksi yang sama, `https://<tenant>.<domain dasar>`. Kolom alamat aplikasi dibuang dari setelan. "Buat
  perintah pasang" kini mewajibkan alamat server (IP VPS), membuat atau memindahkan record `<tenant>.<domain
  dasar>` ke alamat itu (`SiteDns`, tidak lewat proxy Cloudflare, hanya record berkomentar
  `coreerp-site:<id>` yang pernah disentuh), lalu mengirim `app_url` di parameter operasi `install`.
  Pencabutan menghapus record itu. Status token dan zonanya tampil di Pengaturan.
- **Agen** (cabang `feat/agen-proxy-https`): operasi `install` menulis `APP_URL` dan nama host proxy ke `.env`
  sebelum stack dinyalakan; compose klien punya service proxy HTTPS di profil `proxy` yang dinyalakan
  `pasang.sh` secara bawaan; mesin yang port 80/443-nya sudah dipakai proxy lain dipasang dengan
  `--proxy-luar`.

Token Cloudflare disimpan pemilik produk di server pertama — izin **Zone → DNS → Edit** untuk zona domain dasar
saja:

```
read -rsp 'Token Cloudflare: ' T; echo
printf '%s' "$T" | sudo docker exec -i -u www-data coreerp-saas-core-console-1 php artisan dns:token-cloudflare; unset T
```

Perintah itu menolak menyimpan token yang tidak melihat zonanya, dan tidak pernah mencetak tokennya.

## Urutan

1. PR #122 (jalur offline dibuang) dan PR #124 (fondasi Core) masuk.
2. Lisensi yang mengunci masuk.
3. PS-01..08 dan PA-01..04.
4. Uji di server kedua bersama E2E-01 PRD Harbor: dari tenant baru sampai admin klien masuk dengan kata
   sandi sementara dan diminta menggantinya.

## Kriteria terima

- Operator membuat tenant, menyiapkan server klien, dan menyalin perintah pasang **tanpa satu isian
  pun yang wajib diketik** selain data tenant.
- Satu perintah di server Ubuntu kosong menghasilkan aplikasi yang menyala, tenant dengan id yang sama
  dengan admin.erp, dan admin pertama yang dapat masuk dengan kata sandi sementara lalu wajib
  menggantinya.
- Kata sandi sementara tidak pernah tersimpan dalam bentuk teks, dan hash-nya hilang dari database
  setelah operasi ditutup.
- Server klien tidak pernah menghubungi GitHub.
- Setiap penjaga baru dibuktikan dapat merah.
