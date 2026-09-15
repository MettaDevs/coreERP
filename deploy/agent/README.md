# Agen situs CoreERP

Agen untuk profil **on-prem dikelola vendor**: server milik klien, dikelola dari admin.erp tanpa SSH
dan tanpa port masuk. Agen yang menyambung keluar, menanyakan operasi, mengerjakannya, dan melaporkan
hasilnya. Rancangannya di `docs/todo/on-prem-dikelola/README.md`; bentuk HTTP-nya ditentukan
`apps/control-plane/contracts/openapi-agent.yaml`, bukan kode PHP admin.erp.

| Berkas | Isi |
| --- | --- |
| `coreerp-agent` | agen (bash, `curl`, `jq`, `openssl`) |
| `pasang.sh` | skrip pasang sekali jalan, disajikan admin.erp di `/pasang.sh` |
| `coreerp-agent.service`, `.timer` | satu putaran tiap menit lewat systemd |
| `env.template` | contoh `.env` yang diisi `pasang.sh` dengan rahasia yang lahir di server |
| `tests/` | pengujian di container Ubuntu bersih |

## Letak di server

```
/opt/coreerp/                      COREERP_HOME, sama dengan update.sh
  .env                             setelan compose; dibuat sekali, tidak pernah ditimpa
  kunci-rilis.pub                  kunci publik rilis — diambil sekali oleh pasang.sh, lalu dipaku
  update.sh                        salinan untuk dijalankan tangan
  bin/coreerp-agent
  keadaan/                         milik update.sh: versi-sehat, compose-sehat.yaml
  cadangan/                        COREERP_FOLDER_CADANGAN; sebaiknya disk lain
  agent/                           0700
    site.json                      {site_id, tenant_id, tenant_name, admin_url, interval_seconds, update_window}
    site-key.pem                   kunci privat situs, RSA 3072, 0600
    site-public.pem
    state.json                     edition, release, image, digest, last_backup, last_operation, last_install,
                                   current_operation (selama operasi berjalan; dibaca pasang.sh)
    agent.env                      opsional, 0600: setelan server, dibaca unit systemd dan agen (lihat di bawah)
    license/                       0755, di-mount hanya-baca ke Core di /run/coreerp-license
      license.json, license.json.sig, license-public.pem
    releases/<edisi>-<rilis>/      berkas rilis yang sudah lolos tanda tangan dan checksum
    log/operasi-<id>.log           keluaran update.sh dan Core per operasi
    log/pasang.log                 keluaran putaran agen yang dijalankan pasang.sh
```

## Perintah

| Perintah | Siapa yang menjalankan |
| --- | --- |
| `enroll --admin-url URL --token TOKEN` | `pasang.sh`. Menolak bila `site.json` atau `site-key.pem` sudah ada |
| `run [--now]` | timer systemd. Tanpa `--now`, putaran yang datang sebelum `interval_seconds` habis keluar tanpa bekerja |
| `bootstrap-tenant --admin-name NAMA --admin-email EMAIL` | manusia di terminal, sekali, sesudah rilis pertama terpasang. Tidak pernah dari timer: keluarannya memuat kata sandi sementara. Digantikan operasi `install`; dibuang sesudah `install` lulus uji di server kedua (PA-04) |

Operasi dari admin.erp — daftar tertutup, yang lain dilaporkan `failed` dengan "operasi tidak dikenal":
`upgrade`, `install`, `backup`, `install_license`, `rotate_key`, `send_diagnostics`.

`upgrade` menolak rilis yang tanda tangannya salah, checksum-nya tidak cocok, edisinya berbeda dari yang
terpasang, atau nomornya tidak lebih besar dari yang terpasang. Nomor yang dibandingkan adalah nomor di
`manifest.json` yang ditandatangani, dan nomor itu juga harus sama dengan yang diminta admin.erp.

`install` memasang rilis pertama lewat jalur yang sama dengan `upgrade` (`pasang_rilis`), lalu menjalankan
`tenant:bootstrap-site` di container Core dengan satu `--app` per app. Seluruh parameternya diperiksa
sebelum apa pun diunduh; aturannya di skema `ClaimedOperation` pada kontrak. Hash kata sandi admin pertama
hanya dialirkan lewat stdin, tidak pernah menjadi argumen proses, dan keluaran Core disaring darinya
sebelum ditulis ke log. Rilis yang sama yang sudah terpasang tidak dipasang ulang, jadi `install` yang
diulang — "Coba lagi" sesudah langkah tenant gagal — langsung mengulang langkah tenant.

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

Perintahnya disalin dari halaman lingkungan di admin.erp:

```sh
curl -fsSL https://admin.erp.contoh/pasang.sh | sudo bash -s -- --token <token>
```

admin.erp menanam alamatnya sendiri ke `pasang.sh` saat menyajikannya, dan `pasang.sh` mengambil agen,
unit systemd, `env.template`, `update.sh`, dan kunci publik rilis dari `<admin.erp>/agen/`. Salinan
`pasang.sh` dari repo menolak berjalan. Kunci rilis yang memuat kunci privat, yang bukan kunci publik PEM, atau
yang berbeda dari kunci yang sudah terpasang ditolak.

Sesudah mendaftar, `pasang.sh` menjalankan putaran agen dan mencetak progres operasi `install` dari
`state.json` sampai operasi itu selesai, gagal, atau 90 menit lewat, lalu menyalakan timer. Keluaran agen
masuk ke `agent/log/pasang.log`, bukan ke terminal.

Selain `--token`, `pasang.sh` hanya menerima:

| Pilihan | Isi |
| --- | --- |
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
curl -fsSL https://admin.erp.contoh/pasang.sh | sudo COREERP_FOLDER_CADANGAN=/mnt/cadangan/coreerp bash -s -- --token <token>
```

## Data yang keluar dari server

Hanya kunci skema `Report` di kontrak, disusun satu per satu di `susun_laporan`. Daftarnya dan alasannya
di bagian "Data yang boleh keluar dari server klien" pada rancangan. Log, trace, isi tabel, dump, dan
setelan rahasia tidak pernah dikirim. Pengujian `02` membuktikannya dari muatan yang diterima admin.erp
tiruan, yang menolak kunci di luar skema dengan 422.

## Yang perlu diketahui sebelum dipakai

- **Kunci publik rilis dipercaya pada pemasangan pertama.** Setelah repo privat tidak ada jalur kedua
  yang independen untuk mengantarkannya; ia diambil dari admin.erp saat teknisi di lokasi, lalu dipaku.
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
signature base RFC 9421 dan memeriksanya dengan `openssl` serta membaca skemanya dari kontrak. admin.erp
tiruan juga menyajikan `/pasang.sh` dan `/agen/*` dari berkas yang diuji, dan pengujian `pasang.sh`
mengambilnya lewat `curl ... | bash -s` persis seperti di server klien.
`tests/klien-bertanda.py` menandatangani permintaan terpisah dari agen, supaya agen dan server tiruan
tidak dapat lulus bersama karena salah dengan cara yang sama. Kunci rilis dan lisensi dibuat baru di
setiap putaran.

`COREERP_AGENT_BIN`, `COREERP_UPDATE_SH_BIN`, dan `COREERP_PASANG_BIN` menunjuk salinan lain untuk
diuji — dipakai untuk membuktikan setiap pengujian merah ketika penjaga yang diujinya dicabut. Salinan
itu pula yang disajikan admin.erp tiruan. `COREERP_UJI_SARING` menjalankan hanya pengujian yang nama
fungsinya cocok dengan polanya.
