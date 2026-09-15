# Agen situs CoreERP

Agen untuk profil **on-prem dikelola vendor**: server milik klien, dikelola dari admin.erp tanpa SSH
dan tanpa port masuk. Agen yang menyambung keluar, menanyakan operasi, mengerjakannya, dan melaporkan
hasilnya. Rancangannya di `docs/todo/on-prem-dikelola/README.md`; bentuk HTTP-nya ditentukan
`apps/control-plane/contracts/openapi-agent.yaml`, bukan kode PHP admin.erp.

| Berkas | Isi |
| --- | --- |
| `coreerp-agent` | agen (bash, `curl`, `jq`, `openssl`) |
| `pasang.sh` | skrip pasang sekali jalan, online atau offline |
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
    site.json                      {site_id, tenant_id, tenant_name, admin_url, channel, interval_seconds,
                                    update_window, enrolled, enrollment_token (offline, sebelum terikat)}
    site-key.pem                   kunci privat situs, RSA 3072, 0600
    site-public.pem
    state.json                     edition, release, image, digest, last_backup, last_operation
    agent.env                      opsional, 0600: setelan server, dibaca unit systemd dan agen (lihat di bawah)
    license/                       0755, di-mount hanya-baca ke Core di /run/coreerp-license
      license.json, license.json.sig, license-public.pem
    releases/<edisi>-<rilis>/      berkas rilis yang sudah lolos tanda tangan dan checksum
    outbox/                        file laporan offline
    log/operasi-<id>.log           keluaran update.sh per operasi
```

## Perintah

| Perintah | Siapa yang menjalankan |
| --- | --- |
| `enroll --admin-url URL --token TOKEN` | `pasang.sh`, jalur online |
| `run [--now]` | timer systemd. Tanpa `--now`, putaran yang datang sebelum `interval_seconds` habis keluar tanpa bekerja |
| `write-report [FOLDER]` | operator situs offline |
| `import-package FOLDER` | `pasang.sh`, jalur offline. Menolak bila `site-key.pem` sudah ada |
| `confirm-enrollment` | operator, sesudah admin.erp menerima laporan pertama situs offline |
| `install-bundle FOLDER` | `pasang.sh` atau operator situs offline |
| `install-license BERKAS` | operator situs offline |
| `bootstrap-tenant --admin-name NAMA --admin-email EMAIL` | manusia di terminal, sekali, sesudah rilis pertama terpasang. Tidak pernah dari timer: keluarannya memuat kata sandi sementara |

Operasi dari admin.erp — daftar tertutup, yang lain dilaporkan `failed` dengan "operasi tidak dikenal":
`upgrade`, `backup`, `install_license`, `rotate_key`, `send_diagnostics`.

`upgrade` dan `install-bundle` menolak rilis yang tanda tangannya salah, checksum-nya tidak cocok,
edisinya berbeda dari yang terpasang, atau nomornya tidak lebih besar dari yang terpasang. Nomor yang
dibandingkan adalah nomor di `manifest.json` yang ditandatangani, dan untuk `upgrade` nomor itu juga
harus sama dengan yang diminta admin.erp.

## Memasang

Selain jalur online (`--admin-url`, `--token`, `--ref`) atau offline (`--paket`, `--bundle`), `pasang.sh`
menerima:

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
membacanya sendiri, supaya perintah yang dijalankan tangan — `install-bundle`, `bootstrap-tenant` —
memakai setelan yang sama dengan timer. Agen meneruskannya ke `update.sh`.

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

## Berkas offline

**Paket pendaftaran** — folder berisi `site.json` dari admin.erp dan `kunci-rilis.pub`:

```json
{
  "site_id": "01J…", "tenant_id": "01J…", "tenant_name": "…",
  "admin_url": "https://admin.erp.contoh", "channel": "offline",
  "enrollment_token": "…", "update_window": {"start": "22:00", "end": "23:59", "timezone": "Asia/Makassar"},
  "license_public_key": "-----BEGIN PUBLIC KEY-----…" ,
  "license": {"license": "<string JSON persis yang ditandatangani>", "signature": "<base64>"}
}
```

`license_public_key` dan `license` boleh `null`.

**File laporan** — `laporan-<site_id>-<UTC>.json`:

```json
{"format": "coreerp-site-report-v1", "content": "<base64 byte JSON>", "signature": "<base64 RSA-SHA256 atas byte itu>"}
```

`content` terurai menjadi `{"report": <Report di kontrak>, "enrollment": null | {"token": "…", "public_key": "PEM"}}`.
`enrollment` hanya ikut selama `site.json` bernilai `"enrolled": false`.

**Berkas lisensi** untuk `install-license` — bentuk yang sama dengan parameter operasi `install_license`:
`{"license": "…", "signature": "…"}`.

**Bundle** untuk `install-bundle` — keluaran `scripts/build-bundle.sh`, wajib membawa `images.tar.gz`.

## Data yang keluar dari server

Hanya kunci skema `Report` di kontrak, disusun satu per satu di `susun_laporan`. Daftarnya dan alasannya
di bagian "Data yang boleh keluar dari server klien" pada rancangan. Log, trace, isi tabel, dump, dan
setelan rahasia tidak pernah dikirim. Pengujian `02` membuktikannya dari muatan yang diterima admin.erp
tiruan, yang menolak kunci di luar skema dengan 422.

## Yang perlu diketahui sebelum dipakai

- **`deploy/agent/kunci-rilis.pub` belum ada di repo.** Jalur online `pasang.sh` mengambilnya dari
  GitHub pada `--ref` yang disebut; sampai kunci publik rilis di-commit, gunakan `--release-key`.
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

Tanpa Docker sungguhan dan tanpa admin.erp sungguhan: `docker` diganti `tests/shim/docker`, update.sh
diganti `tests/fake-update.sh`, dan admin.erp diganti `tests/fake-admin.py`, yang membangun ulang
signature base RFC 9421 dan memeriksanya dengan `openssl`, serta membaca skemanya dari kontrak.
`tests/klien-bertanda.py` menandatangani permintaan terpisah dari agen, supaya agen dan server tiruan
tidak dapat lulus bersama karena salah dengan cara yang sama. Kunci rilis dan lisensi dibuat baru di
setiap putaran.

`COREERP_AGENT_BIN`, `COREERP_UPDATE_SH_BIN`, dan `COREERP_PASANG_BIN` menunjuk salinan lain untuk
diuji — dipakai untuk membuktikan setiap pengujian merah ketika penjaga yang diujinya dicabut.
