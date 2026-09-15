# Registry image CoreERP — Harbor

Harbor di server pertama, `https://registry.erp.grenery.xyz`. Tempat perakit mendorong image rilis dan
tempat server klien menariknya lewat digest. Acuan developer — robot, kredensial per operasi, penarikan, dan
aturan beserta alasannya — di [docs/dev/30-registry-harbor.md](../../docs/dev/30-registry-harbor.md). Rancangan,
kontrak, dan urutan kerjanya di [PRD registry Harbor](../../docs/todo/registry-harbor/README.md); hasil ukur
pemasangan pertama di [SPIKE.md](SPIKE.md); prosedur operator di [RUNBOOK.md](RUNBOOK.md).

## Isi folder

| Berkas | Untuk |
| --- | --- |
| `pasang.sh` | Memasang atau menyelaraskan Harbor: installer terkunci sha256, rahasia, `harbor.yml`, tindihan compose, rute Traefik, pemeriksaan. Idempoten |
| `atur-harbor.sh` | Menyelaraskan isi Harbor lewat API: setelan sistem, project `coreerp`, immutability, retensi, jadwal GC, robot perakit. Idempoten |
| `uji-asap.sh` | Membuktikan kontrak dari luar lewat Traefik dengan `crane`; keluar nol hanya bila seluruh syarat terpenuhi |
| `putar-sandi-admin.sh` | Memutar kata sandi akun `admin` Harbor tanpa pernah meninggalkan mesin tanpa kata sandi yang berlaku |
| `harbor.yml.tmpl` | Setelan Harbor; dua rahasianya diisi dari server |
| `compose.dokploy.yml` | Tindihan compose: proxy tanpa port publik, masuk `dokploy-network` |
| `traefik/coreerp-registry.yml.tmpl` | Tiga router Traefik: token (dibatasi laju), `/v2` (terbuka), selebihnya (daftar IP) |

## Memasang

Di server pertama, dari salinan repo:

```bash
sudo bash deploy/registry/pasang.sh --izin-ui "203.0.113.7/32"
sudo bash deploy/registry/atur-harbor.sh
sudo bash deploy/registry/uji-asap.sh
```

`--izin-ui` hanya wajib pada pemasangan pertama. Ketiganya aman dijalankan ulang kapan saja; putaran
tanpa perubahan setelan tidak menulis berkas dan tidak me-restart container.

Prasyarat mesin: Docker dengan Compose 2.24 atau lebih baru, Traefik milik Dokploy dengan folder rute
dinamis `/etc/dokploy/traefik/dynamic`, jaringan `dokploy-network` yang attachable, sertifikat wildcard
`*.erp.grenery.xyz` lewat resolver `cloudflare`, dan `jq`, `envsubst`, `openssl` di host.

## Yang ada di server

| Jalur | Isi | Pemilik |
| --- | --- | --- |
| `/opt/harbor/harbor/` | Installer, `harbor.yml` hasil render (0600), `docker-compose.yml` hasil `prepare`, tindihan, `.versi`, `.sidik-terpasang` | root |
| `/var/lib/harbor/` | Data: `database/pg18`, `registry`, `secret` (kunci enkripsi dan tanda tangan token), `redis`, `job_logs` | root dan uid container |
| `/var/log/harbor/` | Log seluruh container Harbor | root |
| `/etc/coreerp/registry/rahasia.env` | `HARBOR_ADMIN_PASSWORD`, `HARBOR_DB_PASSWORD` (0600) | root |
| `/etc/coreerp/registry/setelan.env` | `REGISTRY_IZIN_UI`, dan opsional `REGISTRY_SIMPAN_RILIS`, `REGISTRY_TOKEN_MENIT` | root |
| `/etc/coreerp/perakit/registry-robot.env` | Kredensial robot perakit (0600). Nilainya bertanda kutip tunggal karena nama robot memuat `$` | root |
| `/etc/coreerp/registry/robot-konsol.env` | Kredensial robot sistem admin.erp (0600), untuk dipasang ke konsol lewat `registry:robot-sistem` | root |
| `/etc/coreerp/saas-registry.env` | Kredensial robot pull SaaS dev (0640), dibaca `deploy/saas/pasang-rilis.sh` sebagai user `deploy` | root:coreerp |
| `/etc/dokploy/traefik/dynamic/coreerp-registry.yml` | Rute hasil render | root |

Stack compose bernama `harbor`, terpisah dari `coreerp-saas`. `deploy-dev` tidak pernah menyentuhnya.

## Keadaan per 15 September 2026

| TODO | Keadaan |
| --- | --- |
| CORE-01 | Selesai: `registry` masuk `coreerp.reserved_labels`, dan pendaftaran usaha tidak pernah memberi slug dari daftar itu |
| REG-01 | Selesai, [SPIKE.md](SPIKE.md). OWN-03 menunggu pemilik produk; immutability diputuskan tetap menyala dulu |
| REG-02, REG-03, REG-04, REG-05 | Selesai dan terpasang di server pertama. UI dibuka untuk semua alamat atas keputusan pemilik produk (`REGISTRY_IZIN_UI=0.0.0.0/0 ::/0`) |
| REG-06 | Selesai; dibuktikan merah dengan dua salinan yang dirusak |
| REG-07 | Terpasang sesuai kontrak, **retensi belum dijadwalkan** sampai CP-05 |
| REG-08 | Belum: menunggu OWN-02 (tujuan cadangan). Prosedur manual di RUNBOOK |
| REG-09 | Belum: metrik menyala di jaringan Harbor, belum dikumpulkan SigNoz |
| REG-10 | Sebagian: README dan RUNBOOK ada, bagian cadangan dan pemulihan belum teruji |
