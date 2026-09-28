# Perakit rilis

Merakit satu rilis CoreERP: image Core dan seluruh module (untuk SaaS dev dan server klien) serta image
konsol admin.erp (untuk SaaS dev saja), didorong ke Harbor, beserta manifest rilis v2 yang ditandatangani.
Sejak 15 September 2026 tidak ada yang di-deploy dari `main`; alurnya di
[Dari branch sampai server klien](../../docs/dev/29-alur-rilis-server-klien.md). Rancangannya di
[PRD registry Harbor](../../docs/todo/registry-harbor/README.md), butir IMG-01 dan PK-01 sampai PK-04.

## Isi folder

| Berkas | Untuk |
| --- | --- |
| `Dockerfile` | Image rilis ramping: Debian trixie slim, PHP dan Apache Debian, tanpa perkakas kompilasi |
| `uji-image.sh` | Syarat layak kirim: dpkg sehat, ekstensi termuat, `pg_dump` berjalan, hanya `apps/core`, aplikasi menyala terhadap PostgreSQL kosong |
| `rakit.sh` | Sumber pada commit → build → uji → push image core, konsol, dan pendamping → manifest v2 → tanda tangan → daftar ke admin.erp |
| `coreerp-rilis` | Pembungkus root yang dipanggil alur GitHub `rilis.yml` lewat sudo: hanya merakit dari commit yang sudah ada di `main` |
| `pasang-pemicu.sh` | Memasang pembungkus itu dan aturan sudoers-nya di server pertama, sekali |

## Merakit

Cara biasa: GitHub → Actions → **rilis** → Run workflow, dengan nomor rilis dan commit (`main` atau SHA di `main`).
Alur itu menjalankan pembungkus di bawah lalu memasang rilis ke SaaS dev lewat `deploy-dev.yml`.

Dari server pertama, sebagai root, hasilnya sama tanpa pemasangan ke SaaS dev:

```bash
sudo bash deploy/perakit/rakit.sh --rilis 0.2.1 --ref origin/main
```

Rilis langsung didaftarkan ke admin.erp dengan token dari `/etc/coreerp/perakit/konsol.env` (root 0600):

```text
KONSOL_URL='https://admin.erp.grenery.xyz'
KONSOL_TOKEN_RILIS='<nilai CONSOLE_RELEASE_TOKEN konsol>'
```

`--tanpa-daftar` melewati pendaftaran; `--daftar 0.2.1` mendaftarkan rilis yang sudah dirakit.

Hasilnya di `/var/lib/coreerp-perakit/rilis/<rilis>/`:

| Berkas | Isi |
| --- | --- |
| `manifest.json` | Manifest v2: `versi`, `rilis`, `commit`, `image`, `digest`, `config_digest`, `pendamping[]`, `dibangun_pada` |
| `compose.yaml` | `deploy/compose.edition.yaml` pada commit itu, dengan setiap image pendamping diganti nama lokalnya `coreerp.local/pendamping/<nama>:<20 heksa pertama digest>` |
| `update.sh` | Salinan `scripts/update.sh` pada commit itu |
| `SHA256SUMS`, `SHA256SUMS.sig` | Sidik ketiga berkas di atas, ditandatangani RSA SHA-256 dengan kunci rilis |
| `saas.json` | Commit dan digest image core serta konsol untuk `deploy/saas/pasang-rilis.sh`. Di luar SHA256SUMS: tidak pernah dikirim ke server klien |

Manifest v2 tidak menyebut host registry dan tidak menyebut edisi. `digest` adalah digest manifest di
registry — yang dicocokkan agen lewat `RepoDigests` — dan `config_digest` dicatat terpisah karena
`docker image inspect .Id` menunjuk digest config pada store klasik dan digest manifest pada store
containerd.

## Aturan

- **Nomor rilis diberikan operator** dan tidak dapat dipakai dua kali: tag bernomor rilis immutable di
  Harbor, dan `rakit.sh` menolaknya sebelum membangun apa pun.
- **Cache build Docker di server pertama wajib dipertahankan.** Tanpa cache, lapisan basis dianggap baru
  setiap rilis: ±112 MB per rilis alih-alih ±11 MB, dan tidak pernah terhapus selama immutability menyala.
  Jangan `docker builder prune` di mesin ini tanpa alasan.
- **Pendamping disalin lewat digest, `linux/amd64` saja**, ke `coreerp/pendamping/<nama>:<rilis>`, supaya
  server klien tidak pernah menarik dari Docker Hub. Daftarnya dibaca dari baris `image:` harfiah di
  `deploy/compose.edition.yaml`.
- **Perakit tidak push di sekitar GC Harbor**, Sabtu 19.00–21.59 UTC.
- **Commit yang compose-nya belum menolak menarik ditolak sebelum push.** Setiap service di
  `deploy/compose.edition.yaml` harus `pull_policy: never`; tag rilis immutable, jadi rilis dari commit yang
  belum siap tidak dapat diperbaiki dengan merakit ulang nomor yang sama.

## Yang dibutuhkan di server

| Jalur | Isi |
| --- | --- |
| `/etc/coreerp/perakit/registry-robot.env` | Robot perakit, dibuat `deploy/registry/atur-harbor.sh` |
| `/etc/coreerp/perakit/kunci-rilis-privat.pem` | Kunci privat rilis, root 0600 |
| `/etc/coreerp/kunci/rilis-publik.pem` | Pasangan publiknya; `rakit.sh` menolak jalan bila keduanya tidak berpasangan |
| `/var/lib/coreerp-perakit/sumber` | Salinan repo yang dibersihkan setiap putaran |

## Belum

- **Rilis 0.2.0 tidak didaftarkan.** Ia dirakit sebelum compose dan agen siap untuk registry sendiri; rilis
  pertama yang dapat dipasang dirakit dari commit sesudah AG-01 dan AG-02.
- **Penandatanganan di mesin terpisah** sebelum klien produksi pertama (Tahap 7).
