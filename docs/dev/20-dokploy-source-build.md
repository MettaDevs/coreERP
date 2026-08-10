# Deploy CoreERP di Dokploy tanpa registry aplikasi

Panduan ini membangun image CoreERP, Human Resources, Management Aset, dan dokumentasi langsung di server Dokploy. Source code tetap berada di GitHub; image hasil build hanya tersimpan di Docker server. Tidak ada image aplikasi yang dikirim ke GHCR.

Panduan ini untuk operator deployment pada server Dokploy. Ini bukan installer
`onprem-perpetual` untuk server customer; lifecycle installation dan readiness
tetap harus dicatat oleh sumber kebenaran deployment/runtime yang sesuai.

Gunakan `compose.server.yaml`. Jangan gunakan `compose.yaml`, karena file itu adalah mode registry dan membutuhkan image yang sudah dipublikasikan.

## Yang dikelola Dokploy

1. Dokploy clone repository ini.
2. Dokploy clone tiga submodule source ke `.sources/`.
3. Docker build image lokal dari source tersebut.
4. Compose menjalankan tiga database, migration, API, UI, worker, scheduler, dan docs.

Source checkout berada di work directory Dokploy dan image hasil build berada di Docker server. GitHub hanya menyimpan source code dan pointer submodule.

## Prasyarat

- Docker, Dokploy, dan akses keluar server untuk GitHub, Docker Hub, Composer, dan npm sudah tersedia.
- Akun GitHub yang menerima SSH key Dokploy memiliki akses baca ke empat repo: `app-erp-deployment`, `coreERP`, `app-erp-hr`, dan `app-erp-management-asset`.
- Branch `master` pada repository ini berisi submodule yang menunjuk commit source yang akan dirilis.
- Port 80 dan 443 server terbuka untuk domain publik.

Base image PostgreSQL dan dependency build masih dapat diunduh dari internet. Yang dihindari oleh mode ini hanya registry image aplikasi seperti GHCR.

## 1. Siapkan SSH key Dokploy untuk GitHub

Di Dokploy, buka **Settings → SSH Keys**. Buat atau pakai satu key bernama misalnya `github-readonly`. Salin **Public Key**-nya saja.

Di GitHub, buka **Settings → SSH and GPG keys → New SSH key**, lalu tempel Public Key tersebut. Jangan pernah memasukkan Private Key Dokploy ke GitHub atau chat.

Key ini harus dipilih lagi di service Compose pada langkah berikutnya. Jika repository berada dalam organisasi, pastikan akun GitHub pemilik key adalah anggota organisasi dan dapat membuka ketiga repository source.

### Jika muncul `Host key verification failed`

Jalankan sekali dari SSH server untuk menambahkan host key GitHub ke container Dokploy:

```bash
docker ps --format '{{.Names}}\t{{.Image}}' | grep 'dokploy/dokploy'
docker exec -it <nama-container-dokploy> sh
mkdir -p /root/.ssh
chmod 700 /root/.ssh
ssh-keyscan -H -t ed25519 github.com >> /root/.ssh/known_hosts
chmod 600 /root/.ssh/known_hosts
exit
```

Jika muncul `Permission denied (publickey)`, jangan membuat key baru secara acak. Pastikan service memakai SSH key yang sama dengan public key yang sudah ditambahkan ke akun GitHub, lalu pastikan akun tersebut memang punya akses repo.

## 2. Buat service Compose

Di project dan environment Dokploy tujuan:

1. Klik **Create Service → Compose**.
2. Pilih provider **Git**.
3. Isi repository URL:

   ```text
   git@github.com:MettaDevs/app-erp-deployment.git
   ```

4. Pilih SSH key Dokploy dari langkah 1.
5. Isi branch `master`.
6. Isi Compose Path `./compose.server.yaml`.
7. Aktifkan **Enable Submodules**.
8. Pilih trigger **On Push** agar push ke `master` di repository ini memulai deployment baru.
9. Simpan.

Log deploy yang benar akan menunjukkan tiga checkout berikut selesai:

```text
Submodule path '.sources/core': checked out '...'
Submodule path '.sources/hr': checked out '...'
Submodule path '.sources/asset': checked out '...'
```

## 3. Isi environment service Compose

Buka service Compose tersebut → tab **Environment**. Tempel isi `.env.server.example`, kemudian ganti semua placeholder dengan nilai runtime sebenarnya.

Penting: nilai harus berada langsung di editor Environment service, misalnya:

```text
POSTGRES_IMAGE=postgres:16-alpine
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.example.test
```

Jangan menggunakan referensi seperti ini untuk deployment ini:

```text
POSTGRES_IMAGE=${{project.POSTGRES_IMAGE}}
```

Pada instalasi Dokploy yang digunakan saat panduan ini dibuat, resolver project variable tersebut gagal sebelum Compose dijalankan. Project Environment boleh dipakai sebagai salinan referensi, tetapi Environment service adalah sumber nilai Compose yang dipakai saat deploy.

Minimal semua nama berikut harus tersedia di Environment service:

```text
POSTGRES_IMAGE
APP_ENV
APP_DEBUG
APP_URL
CORE_APP_KEY
CORE_DB_PASSWORD
COREERP_DEPLOYMENT_PROFILE
COREERP_DEPLOYMENT_PLACEMENT
COREERP_PROVIDER_EMAIL
COREERP_PROVIDER_PASSWORD
COREERP_APP_CONTEXT_SIGNING_KEY
COREERP_TRUSTED_PROXIES
COREERP_EVENT_ENDPOINTS
HR_APP_KEY
HR_DB_PASSWORD
HR_SERVICE_TOKEN
ASSET_APP_KEY
ASSET_DB_PASSWORD
ASSET_SERVICE_TOKEN
```

Simpan. Jangan commit nilai tersebut ke repository. Variabel lama seperti `CORE_IMAGE`, `HR_API_IMAGE`, atau `ASSET_UI_IMAGE` tidak dipakai oleh `compose.server.yaml`.

## 4. Deploy dan verifikasi

Klik **Deploy**. Alur sukses adalah:

1. submodule ter-clone;
2. `docker compose ... -f ./compose.server.yaml up -d --build` berjalan;
3. database menjadi healthy;
4. migration selesai;
5. API dan UI berstatus healthy/running.

Jika muncul pesan berikut, Environment service belum terisi atau belum disimpan:

```text
The "POSTGRES_IMAGE" variable is not set.
service "human-resources-db" has neither an image nor a build context specified
```

Kembali ke tab **Environment service** (bukan Project Environment), isi `POSTGRES_IMAGE=postgres:16-alpine` dan seluruh nilai runtime lainnya, klik **Save**, lalu deploy ulang.

## 5. Domain tunggal ERP

Untuk satu URL ERP, tambahkan domain hanya ke service `core-app`:

| Pengaturan | Nilai |
| --- | --- |
| Host | domain ERP yang dipilih |
| Path / Internal Path | `/` |
| Container Port | `80` |
| HTTPS | aktifkan Let's Encrypt |

Pastikan `APP_URL` di Environment service sama persis dengan domain HTTPS tersebut. Human Resources dan Management Aset tetap berkomunikasi dengan nama service internal; jangan beri domain terpisah kecuali memang ingin membuka UI tersebut langsung.

Setelah mengubah domain, deploy ulang Compose service.

## 6. Rilis update source

Push ke `main` pada repo Core, HR, atau Aset **tidak langsung** menjalankan deploy Dokploy. Repository ini menyimpan pointer commit submodule. Naikkan pointer yang ingin dirilis, lalu push repository deployment:

```bash
git submodule update --remote .sources/core
git submodule update --remote .sources/hr
git submodule update --remote .sources/asset
git add .sources/core .sources/hr .sources/asset
git commit -m "chore: update deployment source revisions"
git push origin master
```

Untuk merilis satu repo saja, jalankan satu baris `git submodule update --remote` yang sesuai dan hanya stage pointer tersebut. Trigger **On Push** Dokploy lalu membangun ulang stack dari commit yang baru.

## Cutover dari Compose manual

Dokploy tidak mengambil alih container yang sebelumnya dijalankan manual dari `/opt/coreerp`. Kedua cara memakai project dan volume Compose yang berbeda.

Jika stack manual masih menyimpan data penting, backup database terlebih dahulu. Setelah deploy Dokploy lolos verifikasi, hentikan stack manual agar tidak ada dua stack ERP yang berjalan bersamaan. Jangan menghapus volume database tanpa backup yang sudah diuji restore-nya.

## Troubleshooting ringkas

| Pesan | Penyebab dan tindakan |
| --- | --- |
| `Host key verification failed` | Tambahkan GitHub ke `known_hosts` container Dokploy seperti langkah 1. |
| `Permission denied (publickey)` | Service tidak memakai key yang terdaftar di GitHub atau akun key tidak punya akses repo. |
| `Repository not found` | Periksa URL SSH repository dan hak akses organisasi. |
| `POSTGRES_IMAGE variable is not set` | Isi nilai langsung di Environment service dan simpan. |
| `manifest unknown` | Compose registry mode dipakai untuk image yang belum dipublish. Gunakan `compose.server.yaml` untuk source build. |
