# Spike Tahap 0 — Harbor di server pertama

Dikerjakan 15 September 2026 di server pertama (`103.122.2.94`, Ubuntu 24.04, Docker 28.5.0, Compose
5.4.0) dengan server kedua (`103.122.2.72`, Docker 28.5.0) sebagai klien. Kriteria dari
[PRD registry Harbor](../../docs/todo/registry-harbor/README.md), Tahap 0. Seluruh uji memakai project
sekali pakai `spike` yang sudah dihapus; project `coreerp` tidak pernah berisi artifact uji.

## Ringkasan

| Kriteria Tahap 0 | Hasil |
| --- | --- |
| Harbor di `/opt/harbor`, data di `/var/lib/harbor`, proxy di `dokploy-network` tanpa port publik, rute Traefik melayani `docker login` dari server kedua | **Lulus** |
| Push image ±100 MB dari server kedua lewat Traefik | **Gagal** — batas waktu baca Traefik 60 detik bertemu uplink server kedua ±1 MB/s. Keputusan untuk OWN-03 di bawah |
| Robot situs lewat API, tenggat satu hari, pull berhasil, dihapus lalu ditolak; jeda diukur | **Lulus** — login ditolak seketika; token yang sudah terbit berlaku sampai **umur token + ±60 detik** |
| Tag `terpasang-*` lewat API, pratinjau retensi menyimpannya | **Lulus**, dengan dua temuan yang mengubah kontrak retensi |
| Push ulang tag rilis ditolak immutability | **Lulus** — dan immutability **memblok retensi**. Butuh keputusan |
| Digest sesudah push sama dengan `RepoDigests` sesudah pull, store klasik dan containerd | **Lulus** |
| Compose `coreerp.local/core:<rilis>` + `pull_policy: never` | **Lulus** |

Menunggu pemilik produk: **batas waktu baca Traefik** (OWN-03). **Immutability vs retensi** diputuskan
dibiarkan dulu — lihat temuan 1.

## Pemasangan

- Installer online **v2.15.2** (rilis stabil terbaru; v2.15.3 masih rc). Bawaannya PostgreSQL **18** dan
  **Valkey** sebagai cache.
- Tanda tangan installer v2.15.2 **bukan** berkas `.asc` GPG seperti di halaman unduh Harbor, melainkan
  bundle sigstore. Diperiksa dengan:

  ```bash
  cosign verify-blob --bundle harbor-online-installer-v2.15.2.tgz.sigstore.json \
    --certificate-identity "https://github.com/goharbor/harbor/.github/workflows/publish_release.yml@refs/tags/v2.15.2" \
    --certificate-oidc-issuer https://token.actions.githubusercontent.com \
    harbor-online-installer-v2.15.2.tgz
  ```

  Hasil `Verified OK`; sha256 installer `88f6a7436b31890e8e472972a7433d36b7d6a36de9adeb86337fdc9fe7fb5fa3`
  dikunci di `pasang.sh`.
- Pemakaian saat diam: 10 container, **±195 MiB RAM**, CPU di bawah 1%. Image Harbor ±2,5 GB di disk.
  Server pertama tetap RAM terpakai 5,5 GB dari 22 GB.
- OWN-01: `registry.erp.grenery.xyz` di-resolve `1.1.1.1` langsung ke `103.122.2.94`, bukan alamat
  Cloudflare — record-nya DNS-only.

### Yang tidak berjalan seperti dugaan, dan penjaganya

| Temuan | Akibat bila tidak dijaga | Penjaga |
| --- | --- | --- |
| `install.sh` Harbor selalu menjalankan `docker compose down -v` sebelum `up` | Setiap putaran mematikan registry; tidak dapat idempoten | `pasang.sh` menjalankan `prepare` dan compose sendiri |
| `prepare` membuat rahasia internal baru setiap kali | core, jobservice, dan registryctl dibuat ulang walau setelan sama | `prepare` hanya dijalankan bila sidik jari setelan berubah; putaran kedua terbukti tidak me-restart satu container pun |
| jobservice keluar dan di-restart **4 kali** selama core belum menjawab | `docker compose up --wait` gagal pada pemasangan yang sebenarnya sehat | `pasang.sh` menunggu keadaan akhir, bukan status pertama |
| nginx di proxy me-resolve `core` sekali saat menyala | Setelah core dibuat ulang, alamat lamanya dipakai **registryctl**; `/v2/` dijawab `401` tanpa header `Www-Authenticate` oleh container yang salah, dan `docker login` gagal tanpa pesan yang masuk akal | `pasang.sh` me-restart proxy setiap habis `up` |
| Nama container proxy bawaan `nginx` ikut terdaftar di `dokploy-network` | Nama umum di jaringan bersama | Tindihan mengganti jadi `harbor-proxy`; Traefik memakai alias `harbor-registry-proxy` |
| Menimpa `ports` di tindihan compose tidak menghapus port bawaan | Proxy menerbitkan 8080 dan 9090 di semua alamat | `ports: !reset []`, dan `pasang.sh` menolak Compose di bawah 2.24 |
| Project `library` bawaan Harbor **publik** | Apa pun yang tanpa sengaja didorong ke sana dapat ditarik siapa pun | `atur-harbor.sh` menjadikannya privat |

Port yang terbuka setelah pemasangan: hanya `127.0.0.1:1514` (penerima log Harbor). `127.0.0.1:8080`
yang sudah ada sebelumnya milik crowdsec, bukan Harbor.

## Rute dan akses

Diukur dari server kedua (bukan daftar izin) dan dari laptop pemilik produk (di daftar izin):

| Jalur | Laptop | Server kedua |
| --- | --- | --- |
| `/` (UI) | 200 | **403** |
| `/api/v2.0/systeminfo` | 200 | **403** |
| `/v2/` | 401 | 401 |
| `/service/token` | 400 | 400 |

- Sertifikat yang dilayani: wildcard Let's Encrypt yang sudah ada (`*.erp.grenery.xyz` dan tiga nama
  lain), tanpa penerbitan baru.
- Traefik melihat alamat asal yang sebenarnya, jadi `ipAllowList` bekerja tanpa `ipStrategy`.
- Batas laju `/service/token`: 40 permintaan beruntun dari server kedua → **21 × 401, 19 × 429**.
- **Temuan keamanan:** Harbor menerima basic auth **langsung di `/v2/`** — `tags/list` dengan kredensial
  robot yang benar dijawab 200 tanpa lewat `/service/token`. Batas laju token karena itu tidak menahan
  tebakan kata sandi lewat `/v2/`. Risikonya kecil selama setiap kredensial adalah rahasia acak buatan mesin
  (robot Harbor dan kata sandi admin 32 karakter); **jangan pernah membuat akun manusia berkata sandi di
  Harbor ini** tanpa menambah batas laju di `/v2/`.
- crowdsec di server pertama hanya membaca log sshd dan sistem, jadi 401 dari docker tidak memblokir klien.

## Push lewat Traefik

| Dari | Ukuran lapisan | Hasil |
| --- | --- | --- |
| Server kedua | 100 MiB acak (tidak terkompres) | **Gagal**. Setiap `PATCH` diputus Traefik tepat **60000 ms**; docker mengulang lima kali lalu `unknown: Client Closed Request` |
| Server kedua | 20 MiB acak | Berhasil, **19,0 detik** |
| Server pertama (lewat Traefik, bukan jaringan internal) | 100 MiB acak | Berhasil, **5,7 detik** (`PATCH` 4950 ms) |

Kecepatan terukur ke `speed.cloudflare.com` pada hari yang sama:

| | Unggah | Unduh |
| --- | --- | --- |
| Server pertama | 53,6 MB/s | 65,2 MB/s |
| Server kedua | **1,0 MB/s** | **0,13 MB/s** |

Penyebabnya bukan Harbor: entrypoint `websecure` di `/etc/dokploy/traefik/traefik.yml` tidak menyetel
`transport.respondingTimeouts`, jadi berlaku bawaan Traefik v3 — `readTimeout` **60 detik** untuk seluruh
permintaan termasuk badannya. Docker mengunggah satu lapisan sebagai satu `PATCH`, sehingga lapisan
yang butuh lebih dari 60 detik tidak pernah dapat didorong. Batasnya kira-kira **60 detik × kecepatan
unggah pengirim**.

Pull tidak terkena: `readTimeout` hanya berlaku untuk membaca permintaan. Pull 100 MB ke server kedua
selesai dalam **696 detik** (±145 kB/s, batas unduh server kedua) tanpa diputus.

**Usulan untuk OWN-03 — tidak menaikkan batasnya sekarang.** Satu-satunya pihak yang push adalah
perakit, dan ia berjalan di server pertama yang mengunggah 100 MB dalam enam detik. Server klien hanya
pull. Menaikkan `readTimeout` adalah setelan statis Traefik milik Dokploy yang berlaku untuk **seluruh**
rute di mesin itu dan menuntut restart Traefik. Tinjau ulang bila perakit dipindah ke mesin dengan uplink
lambat, atau bila Tahap 7 menaruh penandatanganan di mesin yang juga push.

**Catatan untuk E2E-01:** server kedua mengunduh ±0,13 MB/s. Pembaruan rutin ±11 MB akan butuh ±1,5
menit, tetapi pemasangan pertama image ±100 MB butuh **±12 menit**. Batas waktu operasi agen harus
menampung angka itu, dan server klien sungguhan mungkin lebih lambat lagi.

## Robot situs dan jeda pencabutan

- Robot `pull` saja dengan `duration: 1` dibuat lewat API; `expires_at` tepat satu hari kemudian.
- `docker login` dan `docker pull` lewat digest dari server kedua berhasil.
- Robot yang sama **tidak dapat push**: `unauthorized to access repository ... action: push`.
- Setelah robot dihapus, seketika:
  - `docker login` dengan rahasianya **ditolak**;
  - basic auth ke `/v2/` dijawab **401**;
  - `/service/token` dengan kredensial itu dijawab **200** — tetapi token yang diberikan adalah token anonim
    dengan `actions: []`, dan manifest dengan token itu dijawab 401. Bukan kebocoran, tetapi jangan
    menilai kredensial dari status `/service/token`.
- Token bearer yang **sudah terbit sebelum penghapusan** tetap diterima registry. Diukur dengan umur token
  5 menit (`token_expiration: 5`), token terbit tepat sebelum penghapusan, diperiksa setiap 10 detik:
  ditolak **368 detik** sesudah penghapusan, padahal `exp` token jatuh 300 detik sesudahnya. Registry
  memberi kelonggaran jam ±60 detik.

**Jeda pencabutan terpanjang = `token_expiration` + ±60 detik.** Dengan setelan `atur-harbor.sh`
(5 menit) jeda itu ±6 menit. Umur token tidak memutus pull panjang: token hanya diperiksa saat permintaan
dimulai. Pada pull di atas, lapisan 100 MB diunduh dalam **satu** `GET` selama 692 detik — lebih dari dua
kali umur token — dan selesai 200; token berikutnya baru diminta docker untuk operasi sesudahnya.

## Tag, immutability, dan retensi

Artifact di repo uji: `0.0.1` (juga diberi `terpasang-0.0.1` lewat API), `0.0.2`, `0.0.3`, `0.0.4`.
Aturan project `spike` sama dengan `coreerp`, kecuali `latestPushedK` = 2.

| Uji | Hasil |
| --- | --- |
| `POST .../artifacts/{digest}/tags` `terpasang-0.0.1` | 201 |
| Push ulang `0.0.1` dengan isi lain | Ditolak: `'uji-srv1:0.0.1' configured as immutable` |
| Pratinjau retensi | `0.0.1` (terpasang) dan `0.0.4` **RETAIN**; `0.0.2`, `0.0.3` **IMMUTABLE** |
| Retensi sungguhan | Tidak ada yang terhapus: `Retention error for artifact ... : Immutable tag` |
| `DELETE` artifact bertag immutable | **412** |
| `DELETE` tag `terpasang-0.0.1` dari artifact yang juga bertag immutable | 200 |
| Retensi sungguhan dengan aturan immutability dinonaktifkan | `0.0.2` dan `0.0.3` terhapus |

### Temuan 1 — immutability memblok retensi (butuh keputusan)

Artifact bertag immutable tidak dapat dihapus siapa pun, termasuk retensi. Dengan kontrak sekarang
(tag rilis immutable **dan** retensi membuang rilis lama), **tidak ada rilis yang pernah terbuang** dan disk
tumbuh tanpa batas. Pilihannya:

| Pilihan | Yang dibeli | Yang dibayar |
| --- | --- | --- |
| **A. Tanpa aturan immutability** (usulan) | Retensi dan GC bekerja apa adanya | Robot perakit yang dicuri dapat menimpa **tag**. Tidak ada klien yang terpengaruh: agen hanya menarik digest yang disebut manifest bertanda tangan, dan digest tidak dapat ditimpa |
| B. Immutability tetap, dinonaktifkan sebentar saat pensiun | Tag rilis terlindungi hampir selalu | admin.erp mematikan perlindungan **seluruh** rilis selama retensi berjalan; satu kegagalan di tengah meninggalkannya mati |
| C. Immutability tetap, retensi tidak pernah membuang rilis | Paling sederhana | Disk ±11 MB lapisan kode per rilis tumbuh selamanya, dan cadangan ikut membesar |

**Diputuskan pemilik produk 15 September 2026: C untuk sekarang**, ditinjau lagi di CP-05.
`atur-harbor.sh` tetap menyalakan immutability dan retensi **tidak dijadwalkan**. Perkiraan pertumbuhannya:
±11 MB per rilis selama lapisan basis tidak dibangun ulang, ±237 MB per rilis bila dibangun ulang. Perakit
karena itu **wajib memakai cache build**; tanpanya rilis harian menghabiskan ±86 GB per tahun.

### Temuan 2 — memberi tag menaikkan `PushedTime`

Setelah `terpasang-0.0.1` dipasang, `PushedTime` artifact `0.0.1` berpindah dari 02:29:59 ke 02:50:39
(Harbor v2.15.2: *Bump repository update_time on tag and artifact changes*). Aturan `latestPushedK`
karena itu menghitung artifact yang baru diberi tag sebagai rilis terbaru: dengan K = 2, rilis `0.0.3` —
rilis kedua termuda — ikut terbuang karena slotnya diambil `0.0.1` yang baru diberi tag. Setiap tag
`terpasang-*` baru dari admin.erp (CP-05) menggeser rilis lain keluar dari K. Pertimbangkan K sebagai
"rilis tidak terpasang yang disimpan" ditambah jumlah rilis terpasang, atau hitung dari sisi admin.erp.

## Digest

Image dengan satu lapisan 100 MiB didorong dari server pertama; digest manifest yang dicetak `docker push`:
`sha256:50b0d30d…6e07`.

| Penarik | Store | `Id` | `RepoDigests` |
| --- | --- | --- | --- |
| Server kedua, Docker 28.5.0 | overlay2 klasik | `sha256:3fd774d7…1446` — **digest config** | `…@sha256:50b0d30d…6e07` |
| Docker 29.8.0 (dind di server pertama) | containerd (`io.containerd.snapshotter.v1`) | `sha256:50b0d30d…6e07` — **digest manifest** | `…@sha256:50b0d30d…6e07` |

`RepoDigests` sama dengan digest sesudah push di kedua store; `Id` tidak. Manifest v2 memang harus mencatat
`digest` dan `config_digest` terpisah, dan agen harus mencocokkan lewat `RepoDigests`, bukan `Id`. Uji
containerd dijalankan di server pertama karena server kedua memakai store klasik dan mengunduh 100 MB
dalam 12 menit; hasilnya bergantung pada store, bukan mesin.

## Compose dengan tag lokal

Di server kedua, service `image: coreerp.local/core:0.0.1-spike` dengan `pull_policy: never`:

- image ada → container menyala, exit 0;
- image dihapus → `Error response from daemon: No such image: coreerp.local/core:0.0.1-spike`, exit 1,
  dan `docker events --filter event=pull` selama percobaan itu **kosong**.

## GC

GC manual sesudah project `spike` dihapus selesai `Success` dengan `no need to execute GC as there is no
non referenced artifacts`: lapisan uji diunggah kurang dari dua jam sebelumnya, dan lapisan semuda itu sengaja dilewati GC. ±450 MB
lapisan uji di `/var/lib/harbor/registry` tersapu GC terjadwal pertama, **Minggu 20 September 2026
03.00 WIB**.

## Yang belum diuji di Tahap 0

- GC yang berjalan **saat** pull — bagian E2E-01.
- Cadangan dan pemulihan — REG-08, menunggu OWN-02.
- Metrik Harbor ke SigNoz — REG-09. Port metrik aktif di jaringan Harbor tetapi belum dikumpulkan.
