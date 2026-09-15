# Registry Harbor

Harbor di server pertama (`https://registry.erp.grenery.xyz`) adalah tempat image rilis CoreERP disimpan dan
satu-satunya tempat server klien menariknya. Perakit mendorong image ke sana sekali per rilis; SaaS dev dan setiap
server klien menariknya lewat **digest** yang tertulis di manifest rilis bertanda tangan, dengan kredensial yang
diterbitkan admin.erp untuk satu operasi saja.

Halaman ini untuk developer yang menyentuh kode di sekitar registry: perakit, agen, kredensial di admin.erp, atau
skrip Harbor sendiri. Alur dari merge sampai server klien — dan siapa memegang rahasia apa — ada di
[Dari branch sampai server klien](29-alur-rilis-server-klien.md). Prosedur operator ada di
`deploy/registry/RUNBOOK.md`, dan hasil ukur yang mendasari hampir setiap aturan di bawah ada di
`deploy/registry/SPIKE.md`.

## Konsep yang mudah tertukar

| Yang ini | Bukan yang itu | Kenapa bedanya penting |
| --- | --- | --- |
| **Digest manifest** (`sha256:…` yang dicetak `docker push`) | **Tag** (`0.3.0`) | Tag dapat dipindah siapa pun yang memegang hak push. Digest adalah sidik jari isi. Server klien hanya menarik digest yang ditandatangani kunci rilis — robot perakit yang dicuri tetap tidak dapat membuat klien menjalankan image lain |
| **`RepoDigests`** image yang ditarik | **`.Id`** image | `.Id` adalah digest *config* pada store image klasik Docker, tetapi digest *manifest* pada store containerd. Hanya `RepoDigests` yang sama di keduanya. Manifest v2 karena itu mencatat `digest` dan `config_digest` terpisah |
| **Login ditolak** sesudah robot dihapus | **Token berhenti berlaku** | Harbor menolak login baru seketika, tetapi token bearer yang sudah terbit tetap diterima sampai umur token + ±60 detik. Menghapus robot menutup pintu, bukan mengusir yang sudah di dalam |
| **`registry_host`** (`registry.erp.grenery.xyz`) | **`registry_api_url`** (`http://harbor-registry-proxy:8080`) | Yang pertama diberikan kepada agen di setiap operasi. Yang kedua jalan konsol ke API Harbor lewat jaringan Docker internal, supaya jalur admin di internet dapat dibatasi daftar IP |
| **Tag rilis** di Harbor (`coreerp/core:0.3.0`) | **Tag lokal** di server (`coreerp.local/core:0.3.0`) | Compose di server hanya mengenal nama lokal dengan `pull_policy: never`. Host registry tidak pernah tertulis di server klien, sehingga registry dapat pindah tanpa menyentuh satu klien pun |
| **Robot situs** (satu per operasi) | **Kunci situs** (satu per server) | Robot hanya untuk pull dan mati bersama operasinya. Kunci situs menandatangani setiap permintaan agen ke admin.erp dan hidup sampai dicabut atau diganti |

## Isi Harbor

Satu project, `coreerp`, **privat**. Project `library` bawaan Harbor juga dibuat privat oleh `atur-harbor.sh`: ia
publik sejak pemasangan, dan apa pun yang tanpa sengaja didorong ke sana akan dapat ditarik siapa saja.

| Repository | Isi | Tag |
| --- | --- | --- |
| `coreerp/core` | Image aplikasi: Core dan seluruh modul, dari `deploy/perakit/Dockerfile` | Nomor rilis |
| `coreerp/konsol` | Image admin.erp, dari `apps/control-plane/Dockerfile`. Dipakai SaaS dev, tidak pernah dipasang di server klien | Nomor rilis |
| `coreerp/pendamping/<nama>` | Salinan image pihak ketiga yang dijalankan compose klien — PostgreSQL, Gotenberg, dan yang lain | Nomor rilis |

Daftar pendamping tidak ditulis di mana pun selain `deploy/compose.edition.yaml`: perakit membaca setiap baris
`image:` literal di sana dan menyalinnya. Menambah service berimage baru di compose itu otomatis menambah
pendamping pada rilis berikutnya.

## Robot

Nama di bawah adalah nama lengkap yang tertulis di log dan audit Harbor. Robot tingkat project diberi awalan
`robot$coreerp+`, robot tingkat sistem `robot$`.

| Robot | Tingkat | Izin | Dibuat oleh | Umur |
| --- | --- | --- | --- | --- |
| `robot$coreerp+perakit` | project | push dan pull | `atur-harbor.sh` | Tanpa tenggat, diputar lewat RUNBOOK |
| `robot$konsol` | sistem | membuat, menghapus, mendaftar, dan membaca robot di project `coreerp`, ditambah pull | `atur-harbor.sh`, disimpan konsol lewat `php artisan registry:robot-sistem` | Tanpa tenggat |
| `robot$coreerp+saas-dev` | project | pull | `atur-harbor.sh` | Tanpa tenggat |
| `robot$coreerp+situs-<operasi>-<acak>` | project | pull | admin.erp, saat agen meminta | Paling lama `sites.registry_robot_days` (satu hari), biasanya jauh lebih pendek |

**Robot sistem konsol wajib punya izin pull**, walaupun konsol sendiri tidak pernah menarik apa pun. Harbor menolak
robot membuat robot lain yang izinnya lebih luas dari miliknya, jadi tanpa pull ia tidak dapat membuat robot situs
pull-only.

**Harbor tidak mengenal izin `robot:update`.** Konsol tidak dapat memutar rahasia robot situs; permintaan kredensial
yang diulang menghapus robot lama lalu membuat yang baru.

## Kredensial untuk satu operasi

Agen yang memegang operasi `install` atau `upgrade` meminta kredensial tepat sebelum menarik image:

```
POST /api/agent/v1/registry-credential   {"operation_id": "<ULID operasi>"}
```

Permintaannya ditandatangani kunci situs seperti setiap permintaan agen lain. Kontraknya di
`apps/control-plane/contracts/openapi-agent.yaml` (skema `RegistryCredential`).

| Jawaban | Arti |
| --- | --- |
| `200` `{registry, username, password, expires_at}` | Robot baru diterbitkan. `Cache-Control: no-store` |
| `409` `{error: <sebab>}` | Situs ini tidak memegang operasi penarik dengan id itu — belum diklaim, sudah ditutup, tenggatnya habis, atau bukan `install`/`upgrade` |
| `422` | Badan permintaan bukan persis `{operation_id}` |
| `503` `registry_unavailable` | Robot sistem belum disetel, Harbor tidak menjawab, atau menolak |

### Keadaan yang disimpan

Robot situs dicatat di baris operasinya, bukan di situs: rahasia yang bocor dari server klien mati bersama operasi
itu.

| Kolom `site_operations` | Isi |
| --- | --- |
| `registry_robot_id` | Id robot di Harbor — yang dipakai untuk menghapusnya |
| `registry_robot_name` | Nama robot — yang tertulis di log dan audit Harbor |

Aturan yang ditegakkan database, bukan hanya kode:

- `site_operations_robot_hanya_penarik` — robot hanya boleh tercatat pada operasi `install` dan `upgrade`.
- `site_operations_robot_berpasangan` — id dan nama selalu terisi atau kosong bersama.
- Indeks parsial `site_operations_robot_tersisa` — membuat sapuan robot yang belum terhapus murah berapa pun umur
  tabelnya.

**Kolom itu sengaja tidak dikosongkan saat operasi ditutup.** Yang mengosongkannya hanya penghapusan robot yang
berhasil. Baris tertutup yang masih membawa id robot adalah robot yang belum terhapus, dan itu yang dicari sapuan
berikutnya.

### Lahir dan mati

- **Lahir saat diminta**, bukan saat operasi dibuat. Operasi yang tidak pernah diambil agen tidak meninggalkan robot.
- **Operasi dapat ditutup selama robot dibuat.** Sesudah Harbor menjawab, baris operasinya dikunci dan diperiksa
  ulang. Robot yang lahir untuk operasi yang sudah tidak dipegang dihapus sebelum rahasianya diberikan kepada siapa
  pun.
- **Dihapus lewat sapuan, bukan jadwal.** Konsol tidak punya penjadwal. `RegistryCredentials::releaseClosed()`
  dipanggil setiap kali agen menyambung dan setiap kali operator mencabut situs. Robot yang penghapusannya gagal
  dicoba lagi pada sapuan berikutnya, dan umur robot di Harbor menjadi penjaga terakhir.
- **Situs dicabut → robot operasi yang sedang berjalan pun ikut dihapus**, karena agennya tidak akan dilayani lagi.

Setiap penerbitan dan penghapusan tercatat di jejak audit: `site.registry_robot.issued` dan
`site.registry_robot.deleted` dengan alasan `diganti`, `operasi_ditutup`, atau `situs_dicabut`.

## Menarik di server klien

Urutannya di `tarik_image_rilis` (`deploy/agent/coreerp-agent`), untuk rilis bermanifest v2:

1. Kredensial diminta ke admin.erp. Jawabannya dibaca `jq` langsung dari berkas sementara 0700 lalu berkasnya
   dihapus; kata sandi tidak pernah lewat argumen proses.
2. `docker login` ke `DOCKER_CONFIG` sementara, kata sandi lewat stdin.
3. Setiap image ditarik **lewat digest** — core dan setiap pendamping — sambil agen terus memperpanjang lease
   operasinya. Pull pertama di koneksi lambat dapat berjalan berjam-jam, dan operasi yang tidak berdetak akan
   dianggap mati.
4. `RepoDigests` setiap image dicocokkan dengan digest di manifest. Image inti juga diperiksa `.Id`-nya terhadap
   `digest` dan `config_digest`.
5. **Baru sesudah semuanya cocok**, image diberi tag lokal: `coreerp.local/core:<rilis>` dan
   `coreerp.local/pendamping/<nama>:<20 huruf pertama digest>`.
6. `docker logout`, `DOCKER_CONFIG` sementara dihapus — pada setiap jalan keluar, termasuk yang gagal.

Pendamping bertag digest, bukan nomor rilis, supaya PostgreSQL yang digest-nya tidak berubah tetap bertag sama dan
Compose tidak membuat ulang container database pada setiap pembaruan. Aturan nama lokal itu ditulis di tiga tempat —
agen, `scripts/update.sh`, dan compose rilis yang dirakit perakit — dan ketiganya harus sama persis.

Pull 401 di tengah jalan membuat agen meminta kredensial lagi; robot lama diganti robot baru.

SaaS dev menarik dengan cara yang sama tetapi dari `deploy/saas/pasang-rilis.sh`, memakai robot `saas-dev` dari
`/etc/coreerp/saas-registry.env`, dan dirujuk langsung lewat digest registry tanpa tag lokal.

## Mendorong dari perakit

`deploy/perakit/rakit.sh` satu-satunya yang mendorong ke project `coreerp`. Aturannya:

- **Tag diperiksa sebelum build, dengan tiga keadaan.** Tag yang ada, tag yang tidak ada, dan kegagalan membaca
  dibedakan. Hanya "tidak ditemukan" — Harbor menjawab `NOT_FOUND`, registry lain `MANIFEST_UNKNOWN` atau
  `NAME_UNKNOWN` — yang berarti nomornya bebas. Kegagalan lain menghentikan perakit, bukan dianggap bebas.
- **Tag yang sudah ada dari commit yang sama dilanjutkan**, bukan ditolak. Perakit yang terputus sesudah push dapat
  dijalankan ulang dengan nomor yang sama. Commit dibaca dari label `org.opencontainers.image.revision` image.
- **Pendamping disalin lewat digest dengan `crane`, platform `linux/amd64`**, dan digest di Harbor dicocokkan dengan
  digest sumbernya.
- **Manifest ditulis sesudah push**, karena digest manifest baru diketahui setelah registry menerimanya.
- **Compose rilis menulis ulang setiap `image:` pendamping menjadi nama lokal**, lalu diperiksa: tidak ada `image:`
  yang tersisa selain `${EDITION_IMAGE}` dan `coreerp.local/*`, dan setiap service `pull_policy: never`.
- **Cache build wajib.** Tanpa cache, lapisan basis dianggap baru di setiap rilis — kira-kira sepuluh kali lipat
  lapisan kode — dan immutability membuatnya tidak pernah terhapus.
- **Kredensial robot hanya ada di `DOCKER_CONFIG` sementara** yang dihapus saat skrip selesai, termasuk saat gagal.

## Immutability, retensi, dan GC

| Setelan | Keadaan | Alasan |
| --- | --- | --- |
| Aturan immutability tag rilis (pola diawali angka) | Menyala | Tag rilis tidak dapat ditimpa. Tag `terpasang-*` sengaja tidak cocok polanya, supaya admin.erp dapat mencabutnya |
| Retensi project `coreerp` | Aturan terpasang, **tidak dijadwalkan** | Immutability memblok retensi: artifact bertag immutable tidak dapat dihapus siapa pun. Pemilik produk memutuskan rilis tidak dibuang dulu (SPIKE.md, temuan 1), ditinjau lagi bersama tag `terpasang-*` (CP-05) |
| GC | Terjadwal Sabtu 20.00 UTC, tanpa menghapus artifact tanpa tag | Artifact tanpa tag tidak dibuang sebelum terbukti setiap artifact yang dipakai bertag. GC tidak menyapu lapisan yang diunggah dalam dua jam terakhir |
| Umur token registry | 5 menit (`REGISTRY_TOKEN_MENIT`) | Batas atas jeda pencabutan robot |

Jadwal GC ditulis di dua tempat: `CRON_GC` di `atur-harbor.sh` dan `GC_HARI_UTC`/`GC_JAM_UTC` di `rakit.sh`. Perakit
menolak push dari satu jam sebelum sampai satu jam sesudah jadwal itu, supaya lapisan yang baru didorong tidak
bertemu GC yang sedang menyapu. Mengubah satu tanpa yang lain tidak akan ditangkap apa pun.

## Aturan yang dijaga, dan alasannya

- **Server klien tidak pernah menarik tag.** Tag dapat dipindah; digest di manifest bertanda tangan tidak. Inilah
  yang membuat robot perakit yang bocor tidak berbahaya bagi klien.
- **Tidak ada image yang diberi tag lokal sebelum seluruhnya lolos pemeriksaan.** Tag lokal yang menunjuk image benar
  dari rilis sebelumnya tidak boleh ditimpa penarikan yang lalu ditolak. Kalau tertimpa, pembaruan yang gagal tidak
  dapat mundur ke image yang benar.
- **Compose di server tidak pernah menarik.** `pull_policy: never` membuat tag lokal yang hilang menjadi kegagalan,
  bukan tarikan diam-diam dari `docker.io` — nama yang dapat didaftarkan siapa pun di Docker Hub dan tidak pernah
  diperiksa tanda tangannya.
- **Satu robot per operasi, bukan per situs.** Rahasia yang tercecer di server klien — log, riwayat shell, cadangan —
  tidak berguna begitu operasinya selesai.
- **Konsol menilai robot sistemnya dari isi jawaban, bukan status HTTP.** Harbor menjawab `GET /projects` dengan
  kredensial yang *salah* sebagai `200 []` — permintaannya diperlakukan anonim. Yang membuktikan kredensial diterima
  adalah project `coreerp` ada di jawaban (`HarborClient::verifyRobot()`).
- **Tidak ada akun manusia berkata sandi di Harbor ini.** Harbor menerima basic auth langsung di `/v2/`, sehingga batas
  laju di `/service/token` tidak menahan tebakan kata sandi lewat jalur itu. Aman selama setiap kredensial adalah
  rahasia acak buatan mesin.
- **Setelan statis Traefik tidak diubah untuk registry.** `readTimeout` Traefik 60 detik memutus upload lapisan besar
  dari koneksi lambat. Hanya perakit yang push, dan ia berjalan di server yang mengunggah cepat. Menaikkan batas itu
  berlaku untuk seluruh rute di mesin dan menuntut restart Traefik milik Dokploy.
- **Rahasia tidak pernah dicetak.** Skrip registry dan perakit membaca dan menulis rahasia lewat berkas 0600 dan
  stdin. Contoh perintah yang menyimpan rahasia ke konsol memakai `docker exec -u www-data`: perintah yang berjalan
  sebagai root di container meninggalkan log milik root, dan Apache lalu gagal menulis log tanpa jejak.

## Yang terukur dan mudah terulang

Setiap baris di bawah pernah memakan waktu sungguhan. Detail pengukurannya di `deploy/registry/SPIKE.md`.

| Gejala | Sebab | Yang dilakukan |
| --- | --- | --- |
| `/v2/` dijawab `401` tanpa header `Www-Authenticate`, `docker login` gagal tanpa pesan jelas | nginx di proxy Harbor me-resolve `core` sekali saat menyala, lalu memakai alamat lama sesudah core dibuat ulang | `pasang.sh` me-restart proxy setiap habis `up`. Tangan: `docker compose restart proxy` di `/opt/harbor/harbor` |
| `docker compose up --wait` Harbor gagal padahal pemasangan sehat | jobservice keluar dan di-restart beberapa kali selama core belum menjawab | `pasang.sh` menunggu keadaan akhir sendiri |
| Push lapisan besar putus tepat 60 detik, `Client Closed Request` | `readTimeout` bawaan Traefik v3 | Push dari mesin yang cepat. Pull tidak terkena |
| Perakit mendorong nomor yang ternyata sudah ada | `crane` berjalan bukan sebagai root dan tidak dapat membaca `config.json`; kegagalan membaca terbaca "tag tidak ada" | `crane` dijalankan dengan uid dan gid pemanggil, dan pemeriksaan tag membedakan tiga keadaan |
| Pemeriksaan kredensial konsol hijau dengan kata sandi salah | `200 []` untuk permintaan anonim | Periksa isi jawaban |
| Robot sudah dihapus, pull masih berhasil beberapa menit | Token yang sudah terbit berlaku sampai umurnya + ±60 detik | Terima sebagai batas; umur token dijaga pendek |
| Tag `terpasang-*` baru membuat rilis lain terbuang retensi `latestPushedK` | Harbor menaikkan `PushedTime` artifact yang diberi tag | Perhitungkan saat retensi dinyalakan (CP-05) |

## Menjalankan dan menguji

Kontrak registry dari luar, di server pertama:

```bash
sudo bash deploy/registry/uji-asap.sh
```

Keluar nol hanya bila rute, sertifikat, token, robot, dan immutability bekerja lewat Traefik.

Kredensial dan rilis v2 di admin.erp — `RegistryCredentialTest`, `ReleaseRegistrationTest`, dan bagian registry di
`SettingsScreenTest`, di bawah `apps/control-plane/tests/Feature/Sites/`. Harbor dipalsukan di sana; bentuk jawaban
tiruannya mengikuti yang terukur di SPIKE.md, bukan dokumentasi Harbor.

Penarikan di agen — bagian kredensial dan pull suite agen:

```bash
docker run --rm -v "$PWD":/repo -w /repo ubuntu:24.04 bash deploy/agent/tests/run-tests.sh
```

Di Git Bash Windows, pakai `$(pwd -W)` dan `MSYS_NO_PATHCONV=1`.

Perubahan pada perakit tidak punya suite otomatis. Buktikan dengan merakit rilis percobaan sungguhan lewat tombol
**rilis**, lalu periksa log `/var/log/coreerp-rilis/<rilis>.log`. Nomor percobaan tidak dapat dipakai ulang, dan
itu tidak merugikan siapa pun.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `deploy/registry/pasang.sh` | Memasang atau menyelaraskan Harbor: installer terkunci sha256, rahasia, compose, rute Traefik |
| `deploy/registry/atur-harbor.sh` | Isi Harbor lewat API: setelan sistem, project, immutability, retensi, GC, dan robot yang tidak berumur |
| `deploy/registry/uji-asap.sh` | Uji kontrak dari luar |
| `deploy/registry/putar-sandi-admin.sh` | Memutar kata sandi admin Harbor tanpa pernah meninggalkan mesin tanpa kata sandi yang berlaku |
| `deploy/registry/README.md`, `RUNBOOK.md`, `SPIKE.md` | Isi server, prosedur operator, hasil ukur |
| `deploy/perakit/rakit.sh` | Build, uji, push, pendamping, manifest v2, tanda tangan, pendaftaran |
| `deploy/perakit/uji-image.sh` | Uji image yang baru dibangun sebelum didorong |
| `deploy/perakit/coreerp-rilis`, `pasang-pemicu.sh` | Pembungkus sudo yang dipanggil tombol rilis, dan pemasangnya |
| `.github/workflows/rilis.yml`, `deploy-dev.yml` | Tombol rilis yang memanggil perakit lewat SSH, dan pemasangan rilis ke SaaS dev |
| `deploy/saas/pasang-rilis.sh` | Menarik rilis ke SaaS dev lewat digest |
| `apps/control-plane/app/Registry/` | `HarborClient`, `RegistryCredentials`, `RegistrySettings`, dan `RegistryUnavailable` untuk setiap kegagalan registry yang pesannya tidak pernah memuat rahasia |
| `apps/control-plane/app/Console/Commands/SetRegistryRobot.php` | `php artisan registry:robot-sistem` |
| `apps/control-plane/app/Sites/ReleaseRegistry.php` | Pendaftaran rilis, termasuk aturan manifest v2 |
| `apps/control-plane/app/Http/Controllers/Agent/AgentApi.php` | `registryCredential()` |
| `apps/core/database/migrations/2026_09_16_120000_add_registry_robot_to_site_operations.php` | Kolom robot di operasi dan constraint-nya |
| `deploy/agent/coreerp-agent` | `minta_kredensial`, `masuk_registry`, `tarik_satu`, `periksa_image_ditarik`, `tarik_image_rilis`, `lepas_registry` |
| `scripts/update.sh`, `deploy/compose.edition.yaml` | Pemeriksaan tag lokal sebelum compose menyala, dan compose sumber pendamping |

## Yang belum ada

- **Tag `terpasang-*` dan retensi terjadwal** (CP-05). Sampai itu ada, tidak ada rilis yang dibuang dari Harbor.
- **Cadangan Harbor terjadwal** (REG-08), menunggu keputusan tempat cadangan. Prosedur manual di RUNBOOK belum
  pernah diuji. Image yang terpasang di klien tidak dapat dibangun ulang dengan digest yang sama, jadi folder
  registry sama pentingnya dengan database-nya.
- **Metrik Harbor di SigNoz** (REG-09). Port metriknya menyala di jaringan Harbor, belum dikumpulkan.
- **Uji ujung-ke-ujung di server klien sungguhan** (E2E-01), termasuk GC yang berjalan saat pull.
- **Dua kunci rilis di agen** (AG-03), untuk memutar kunci rilis tanpa memasang ulang agen.

Rencana lengkap dan keputusan yang menunggu ada di [PRD registry Harbor](/todo/registry-harbor/).

## Halaman terkait

- [Dari branch sampai server klien](29-alur-rilis-server-klien.md) — alur rilis, nomor rilis, dan siapa memegang
  rahasia apa.
- [Release dan on-prem](03-release-and-on-prem.md) — migration yang kompatibel mundur dan pembaruan yang aman
  diulang.
- [CI/CD](22-ci-cd.md) — tombol `rilis` dan `deploy-dev`.
- `deploy/agent/README.md` — agen di server klien.
