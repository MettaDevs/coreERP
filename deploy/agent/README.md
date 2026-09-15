# Agen situs CoreERP

Agen untuk profil **on-prem dikelola vendor**: server milik klien, dikelola dari admin.erp tanpa SSH
dan tanpa port masuk. Agen yang menyambung keluar, menanyakan operasi, mengerjakannya, dan melaporkan
hasilnya. Rancangannya di `docs/todo/on-prem-dikelola/README.md`; bentuk HTTP-nya ditentukan
`apps/control-plane/contracts/openapi-agent.yaml`, bukan kode PHP admin.erp.

| Berkas | Isi |
| --- | --- |
| `coreerp-agent` | agen (bash, `curl`, `jq`, `openssl`) |
| `pasang.sh` | skrip pasang sekali jalan |
| `coreerp-agent.service`, `.timer` | satu putaran tiap menit lewat systemd |
| `env.template` | contoh `.env` yang diisi `pasang.sh` dengan rahasia yang lahir di server |
| `tests/` | pengujian di container Ubuntu bersih |

## Letak di server

```
/opt/coreerp/                      COREERP_HOME, sama dengan update.sh
  .env                             setelan compose; dibuat sekali, tidak pernah ditimpa
  kunci-rilis.pub                  kunci publik rilis — TIDAK PERNAH diambil dari admin.erp
  update.sh                        salinan untuk dijalankan tangan
  bin/coreerp-agent
  keadaan/                         milik update.sh: versi-sehat, compose-sehat.yaml
  cadangan/                        COREERP_FOLDER_CADANGAN; sebaiknya disk lain
  agent/                           0700
    site.json                      {site_id, tenant_id, tenant_name, admin_url, interval_seconds, update_window}
    site-key.pem                   kunci privat situs, RSA 3072, 0600
    site-public.pem
    state.json                     edition, release, image, digest, last_backup, last_operation
    agent.env                      opsional, 0600: setelan server, dibaca unit systemd dan agen (lihat di bawah)
    license/                       0755, di-mount hanya-baca ke Core di /run/coreerp-license
      license.json, license.json.sig, license-public.pem
    releases/<edisi>-<rilis>/      berkas rilis yang sudah lolos tanda tangan dan checksum
    log/operasi-<id>.log           keluaran update.sh per operasi
```

## Perintah

| Perintah | Siapa yang menjalankan |
| --- | --- |
| `enroll --admin-url URL --token TOKEN` | `pasang.sh`. Menolak bila `site.json` atau `site-key.pem` sudah ada |
| `run [--now]` | timer systemd. Tanpa `--now`, putaran yang datang sebelum `interval_seconds` habis keluar tanpa bekerja |
| `bootstrap-tenant --admin-name NAMA --admin-email EMAIL` | manusia di terminal, sekali, sesudah rilis pertama terpasang. Tidak pernah dari timer: keluarannya memuat kata sandi sementara |

Operasi dari admin.erp — daftar tertutup, yang lain dilaporkan `failed` dengan "operasi tidak dikenal":
`upgrade`, `backup`, `install_license`, `rotate_key`, `send_diagnostics`.

`upgrade` menolak rilis yang tanda tangannya salah, checksum-nya tidak cocok, edisinya berbeda dari yang
terpasang, atau nomornya tidak lebih besar dari yang terpasang. Nomor yang dibandingkan adalah nomor di
`manifest.json` yang ditandatangani, dan nomor itu juga harus sama dengan yang diminta admin.erp.

## Lisensi

Lisensi format versi 2 mengunci Core bila `.env` menyetel `COREERP_LICENSE_REQUIRED=true`, yang ditulis
`env.template`. Rancangannya di `docs/todo/lisensi-mengunci/README.md`.

Lisensi datang lewat dua jalan: operasi `install_license`, dan jawaban laporan yang membawa `license` saat
admin.erp menilai perpanjangan jatuh tempo. Keduanya lewat `pasang_lisensi`, yang menolak tanda tangan
yang tidak sah terhadap `license/license-public.pem`, `site_id` yang bukan situs ini, `version` selain `2`,
`apps` yang bukan larik id app, dan `valid_until` yang bukan tanggal kalender. Lisensi yang ditolak tidak
menyentuh yang terpasang. Penolakan dari jawaban laporan dicatat di keluaran agen dan tidak menggagalkan
putaran; admin.erp melihatnya sebagai `license_expires_at` yang tidak bergerak.

Laporan membawa `license_required`: `true` hanya bila baris terakhir untuk kunci itu di `.env` tertulis
persis `COREERP_LICENSE_REQUIRED=true`, `false` untuk bentuk lain, `null` bila `.env` tidak terbaca.

## Memasang

Selain `--admin-url`, `--token`, dan `--ref`, `pasang.sh` menerima:

| Pilihan | Isi |
| --- | --- |
| `--release-key BERKAS` | kunci publik rilis dari berkas ini, bukan dari jalur bawaannya |
| `--app-url URL` | alamat CoreERP yang dibuka pengguna; bawaan `https://<nama host>` |
| `--provider-email EMAIL` | akun admin provider di Core |
| `--app-port PORT` | port host aplikasi; bawaan `CORE_APP_PORT` di `env.template` |
| `--app-bind ALAMAT` | alamat IPv4 tempat port itu diikat; bawaan `CORE_APP_BIND` di `env.template`, yaitu `127.0.0.1` |

`--app-port` dan `--app-bind` hanya berlaku pada pemasangan pertama, karena `.env` yang sudah ada tidak
pernah ditimpa. Pada pemasangan pertama itu juga `pasang.sh` menolak, sebelum menulis apa pun, bila port
yang dipilih sudah didengar layanan lain: server klien lazim sudah melayani situs lain, dan tabrakannya
lebih murah ditemukan oleh orang yang sedang memasang daripada oleh rilis pertama di jendela pembaruan.

Port aplikasi diikat ke loopback karena CoreERP dilayani lewat reverse proxy klien, dan port yang
diterbitkan Docker melewati firewall host — aturan UFW tidak berlaku untuknya. Reverse proxy yang berjalan
di dalam Docker tidak menjangkau loopback host; untuk bentuk itu sebut alamat gateway bridge Docker lewat
`--app-bind`. `compose.edition.yaml` sendiri berbawaan `0.0.0.0`, supaya pemasangan beli-putus yang
menjangkau port itu langsung tidak terputus saat diperbarui.

## Setelan server: agent.env

`agent/agent.env` memuat setelan yang tidak dibawa rilis, misalnya `COREERP_PROYEK` atau
`COREERP_FOLDER_CADANGAN` ke disk kedua. Unit systemd membacanya lewat `EnvironmentFile=`, dan agen
membacanya sendiri, supaya perintah yang dijalankan tangan — `bootstrap-tenant`, `run --now` — memakai
setelan yang sama dengan timer. Agen meneruskannya ke `update.sh`.

Yang menang, berurutan: variabel yang disebut di lingkungan perintah, lalu `agent.env`, lalu bawaan.

Berkasnya tidak dijalankan sebagai skrip. Yang diterima hanya baris kosong, komentar yang diawali `#` atau
`;`, dan `KUNCI=nilai` untuk kunci yang benar-benar dibaca agen atau `update.sh`; daftarnya
`SETELAN_DIIZINKAN` di `coreerp-agent`, beserta alasan kunci yang sengaja tidak ada di sana. Nilai hanya
huruf, angka, dan `. _ / : @ + -`, tanpa kutip atau spasi, supaya systemd dan agen membacanya sama. Baris
lain membuat agen berhenti dengan menyebut nomor barisnya.

`COREERP_PROYEK` dan `COREERP_FOLDER_CADANGAN` yang disebut saat menjalankan `pasang.sh` ditulis ke berkas
ini bila ia belum ada. Bila sudah ada dan nilainya berbeda, `pasang.sh` menolak alih-alih menimpanya:

```sh
COREERP_FOLDER_CADANGAN=/mnt/cadangan/coreerp bash pasang.sh --admin-url https://admin.erp.contoh --token <token>
```

## Data yang keluar dari server

Hanya kunci skema `Report` di kontrak, disusun satu per satu di `susun_laporan`. Daftarnya dan alasannya
di bagian "Data yang boleh keluar dari server klien" pada rancangan. Log, trace, isi tabel, dump, dan
setelan rahasia tidak pernah dikirim. Pengujian `02` membuktikannya dari muatan yang diterima admin.erp
tiruan, yang menolak kunci di luar skema dengan 422.

## Yang perlu diketahui sebelum dipakai

- **`deploy/agent/kunci-rilis.pub` belum ada di repo.** `pasang.sh` mengambilnya dari GitHub pada
  `--ref` yang disebut; sampai kunci publik rilis di-commit, gunakan `--release-key`.
- **Pemasangan pertama di server bersih** tidak membandingkan lokasi cadangan dengan data database:
  volumenya belum ada, dan belum ada data yang dapat hilang. `update.sh` menunda pemeriksaan itu ke
  pembaruan berikutnya, yang pertama kali benar-benar mencadangkan. Arahkan `COREERP_FOLDER_CADANGAN`
  ke disk lain sejak awal supaya pembaruan itu tidak ditolak. Disebut saat menjalankan `pasang.sh`,
  nilainya tersimpan di `agent.env`.

## Pengujian

```sh
docker run --rm -v "$PWD":/repo -w /repo ubuntu:24.04 bash deploy/agent/tests/run-tests.sh
```

Dari Git Bash di Windows, awali dengan `MSYS_NO_PATHCONV=1` dan sebut jalurnya `-v "D:/Kerja/CoreERP:/repo"`.

Tanpa Docker sungguhan, tanpa admin.erp sungguhan, dan tanpa GitHub: `docker` diganti `tests/shim/docker`,
update.sh diganti `tests/fake-update.sh`, admin.erp diganti `tests/fake-admin.py`, yang membangun ulang
signature base RFC 9421 dan memeriksanya dengan `openssl` serta membaca skemanya dari kontrak, dan
`pasang.sh` mengambil berkas agen dari repo ini lewat `COREERP_REPO_DIR`.
`tests/klien-bertanda.py` menandatangani permintaan terpisah dari agen, supaya agen dan server tiruan
tidak dapat lulus bersama karena salah dengan cara yang sama. Kunci rilis dan lisensi dibuat baru di
setiap putaran.

`COREERP_AGENT_BIN`, `COREERP_UPDATE_SH_BIN`, dan `COREERP_PASANG_BIN` menunjuk salinan lain untuk
diuji — dipakai untuk membuktikan setiap pengujian merah ketika penjaga yang diujinya dicabut.
