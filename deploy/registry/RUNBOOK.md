# RUNBOOK registry Harbor

Prosedur operator untuk Harbor di server pertama. Setiap bagian menyebut apakah ia **teruji** — pernah
dijalankan sampai selesai di server itu — atau **belum teruji**. Yang belum teruji dibaca sebagai rencana,
bukan jaminan.

Aturan untuk seluruh bagian:

- Semua perintah dijalankan sebagai root di server pertama, dari salinan repo.
- Rahasia tidak pernah dicetak ke terminal, log, atau percakapan. Skrip di folder ini membaca dan menulis
  rahasia lewat berkas 0600 dan stdin.
- Tidak ada `docker system prune --volumes`, `docker compose down -v`, atau `install.sh` bawaan Harbor di
  mesin ini.

## Memeriksa keadaan — teruji

```bash
docker ps --filter label=com.docker.compose.project=harbor --format '{{.Names}} {{.Status}}'
sudo bash deploy/registry/uji-asap.sh
```

Sepuluh container, sembilan `(healthy)` dan `harbor-exporter` yang tidak punya healthcheck. `uji-asap.sh`
yang hijau berarti rute, sertifikat, token, robot, dan immutability bekerja dari luar.

## Harbor mati — sebagian teruji

**Yang terjadi di klien:** aplikasi klien tetap melayani — container yang berjalan tidak butuh registry.
Yang gagal hanya operasi `install` dan `upgrade` yang sedang menarik image. Tidak ada yang perlu dilakukan
di server klien.

**Yang dilakukan operator:**

1. `sudo bash deploy/registry/pasang.sh`. Skrip menyalakan container yang mati dan me-restart proxy tanpa
   menjalankan `prepare` bila setelan tidak berubah.
2. Bila `/v2/` dijawab `401` **tanpa** header `Www-Authenticate`, proxy memegang alamat lama container
   yang dibuat ulang (SPIKE.md): `cd /opt/harbor/harbor && docker compose restart proxy`.
3. Log per komponen ada di `/var/log/harbor/<komponen>.log`; `core.log` dan `jobservice.log` biasanya
   yang bercerita.
4. Container jobservice yang keluar beberapa kali saat core belum menjawab adalah normal pada setiap
   penyalaan.

## Memutar rahasia robot perakit — teruji

```bash
sudo rm /etc/coreerp/perakit/registry-robot.env
sudo bash deploy/registry/atur-harbor.sh
```

`atur-harbor.sh` menemukan robot `coreerp+perakit` tanpa berkas rahasianya dan memutar rahasianya, bukan
membuat robot kedua. Rahasia lama ditolak seketika untuk login baru; token yang sudah terbit masih berlaku
sampai `REGISTRY_TOKEN_MENIT` + ±60 detik. Push yang sedang berjalan saat itu dapat gagal dan diulang.

## Memutar kata sandi admin Harbor — teruji

```bash
sudo bash deploy/registry/putar-sandi-admin.sh
sudo bash deploy/registry/pasang.sh
```

Yang pertama mengganti kata sandi lewat API lalu `rahasia.env`. Yang kedua menulis ulang `harbor.yml`
dengan kata sandi baru — sekali `prepare` dan restart core, jobservice, registryctl, dan proxy, ±1 menit
registry tidak melayani.

Bila skrip berhenti dengan `rahasia.env.baru` tertinggal, kata sandi yang berlaku ada di berkas itu:
coba masuk dengannya, lalu pindahkan ke `rahasia.env`.

## Memutar rahasia robot sistem admin.erp — belum ada

Robot sistem dibuat bersama CP-01. Bagian ini ditulis saat itu.

## Mengubah daftar alamat yang boleh membuka UI — teruji

```bash
sudo bash deploy/registry/pasang.sh --izin-ui "203.0.113.7/32 198.51.100.0/24"
```

Hanya rute Traefik yang ditulis ulang; Harbor tidak di-restart. Traefik membacanya dalam hitungan detik.

Sejak 15 September 2026 daftarnya `0.0.0.0/0 ::/0` — UI terbuka untuk semua alamat atas keputusan pemilik
produk. Untuk menutupnya lagi, jalankan perintah di atas dengan alamat yang diizinkan.
`/v2/` dan `/service/token` tetap terbuka untuk semua alamat — itu jalur docker di server klien.

## Retensi dan GC — sebagian teruji

- GC terjadwal Minggu 03.00 WIB (`0 0 20 * * 6` UTC), tanpa menghapus artifact tanpa tag. GC tidak
  menyapu lapisan yang diunggah dalam dua jam terakhir.
- GC manual: UI → Administration → Clean Up → *GC Now*.
- Retensi project `coreerp` **tidak dijadwalkan**. Pratinjau: UI → project `coreerp` → Policy → Tag
  Retention → *Dry Run*. Selama aturan immutability tag rilis menyala, retensi tidak dapat menghapus satu
  rilis pun (SPIKE.md, temuan 1).

## Menaikkan versi Harbor — belum teruji

Pembaruan Harbor dapat memigrasikan database saat container menyala. v2.15.2 sendiri menaikkan PostgreSQL
bawaan dari 15 ke 18 lewat `pg_upgrade`.

1. Baca catatan rilis versi tujuan dan setiap versi di antaranya.
2. Jadwalkan di luar jam sibuk SaaS dan di luar jadwal push perakit.
3. Unduh installer online versi baru beserta `.sigstore.json`-nya, periksa dengan `cosign verify-blob`
   (perintahnya di SPIKE.md dengan tag versi baru), lalu hitung sha256-nya.
4. Bandingkan `harbor.yml.tmpl` bawaan versi baru dengan `deploy/registry/harbor.yml.tmpl`; bawa kunci baru
   dan `_version` yang berubah.
5. Ubah `HARBOR_VERSI` dan `HARBOR_SHA256` di `pasang.sh` lewat PR.
6. Cadangkan sesuai bagian cadangan di bawah, dan pastikan berkasnya ada di luar server pertama.
7. `sudo bash deploy/registry/pasang.sh --naikkan-versi`
8. `sudo bash deploy/registry/atur-harbor.sh` lalu `sudo bash deploy/registry/uji-asap.sh`.
9. Catatan rilis v2.15.2 menyarankan `reindexdb --all-databases -U postgres` setelah perubahan versi mayor
   PostgreSQL bila robot kehilangan izin atau daftar di UI kosong.

## Cadangan manual — belum teruji

Pengganti sementara REG-08 sampai OWN-02 menentukan tujuan cadangan. **Image yang terpasang di klien tidak
dapat dibangun ulang dengan digest yang sama**, jadi folder registry sama pentingnya dengan database.

Yang dicadangkan, seluruhnya dari server pertama:

| Isi | Kenapa |
| --- | --- |
| Dump database `registry` dari container `harbor-db` | Project, robot, aturan, dan pemetaan tag → digest |
| `/var/lib/harbor/registry` | Lapisan dan manifest image |
| `/var/lib/harbor/secret` | `keys/secretkey` mengenkripsi kolom rahasia di database; `core/private_key.pem` menandatangani token. Database tanpa folder ini tidak dapat dipakai |
| `/etc/coreerp/registry/` | Kata sandi admin dan database, daftar izin UI |
| `/etc/coreerp/perakit/registry-robot.env` | Kredensial robot perakit |

Dump database:

```bash
umask 077
docker exec harbor-db pg_dump -U postgres -Fc registry > /root/harbor-registry-$(date +%F).dump
```

Arsip berkas, lalu dienkripsi sebelum meninggalkan server:

```bash
tar -C / -czf /root/harbor-berkas-$(date +%F).tgz \
    var/lib/harbor/registry var/lib/harbor/secret etc/coreerp/registry etc/coreerp/perakit/registry-robot.env
```

Dump dan arsip diambil pada waktu yang sama dan tanpa push perakit di antaranya; lapisan yang didorong
sesudah dump tetapi sebelum arsip tidak dikenal database hasil pulih, dan sebaliknya.

## Memulihkan dan pindah ke server lain — belum teruji

Server klien tidak menyimpan alamat registry, jadi pindah server hanya menyentuh sisi kita.

1. Di mesin baru: Docker, Traefik dengan sertifikat wildcard yang sama, jaringan setara `dokploy-network`.
2. Pulihkan `/etc/coreerp/registry/`, `/var/lib/harbor/registry`, dan `/var/lib/harbor/secret` dari arsip.
3. Pasang installer tanpa menyalakan database lama: `pasang.sh` menolak jalan bila `/var/lib/harbor/database`
   sudah ada tanpa installer — itu disengaja, jadi database dipulihkan **sesudah** Harbor pertama kali
   menyala dengan database kosong:
   1. `sudo bash deploy/registry/pasang.sh --izin-ui "..."` dengan `rahasia.env` hasil pulih;
   2. `cd /opt/harbor/harbor && docker compose stop core jobservice registryctl registry exporter`;
   3. `docker exec -i harbor-db pg_restore -U postgres --clean --if-exists -d registry < harbor-registry-<tanggal>.dump`;
   4. `sudo bash deploy/registry/pasang.sh`.
4. `sudo bash deploy/registry/uji-asap.sh`, lalu `docker pull` lewat digest salah satu rilis yang terpasang di
   klien — keberhasilan pull itulah bukti pemulihannya.
5. Ubah arah DNS `registry.erp.grenery.xyz`. Tidak ada satu pun server klien yang disentuh.
