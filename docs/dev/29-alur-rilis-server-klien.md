# Dari branch sampai server klien

Halaman ini menjawab satu pertanyaan: **bagaimana kode yang saya merge sampai ke server klien on-prem yang
dikelola vendor?** Alurnya lahir 15 September 2026 bersama registry image sendiri, dan berbeda cukup jauh dari
alur rilis sebelumnya, jadi bacalah bagian "Anggapan yang keliru" lebih dulu.

Rancangan lengkapnya ada di tiga PRD: [registry Harbor](/todo/registry-harbor/),
[pemasangan satu perintah](/todo/pasang-satu-perintah/), dan [lisensi yang mengunci](/todo/lisensi-mengunci/).
Halaman ini merangkum bagaimana ketiganya bertemu.

> **Keadaan per 15 September 2026.** Seluruh bagiannya sudah di `main` dan terpasang di server pertama, tetapi
> belum pernah diuji ujung-ke-ujung di server klien sungguhan (E2E-01 di PRD registry). Lihat
> [Yang belum ada](#yang-belum-ada).

## Anggapan yang keliru

| Anggapan | Yang sebenarnya |
| --- | --- |
| Merge ke `main` adalah rilis | Merge ke `main` hanya memperbarui **SaaS dev** di server pertama. Rilis untuk server klien lahir ketika operator menjalankan **perakit** dengan nomor rilis. |
| Rilis ditandai tag git atau GitHub Release | Tidak ada tag git. Nomor rilis diberikan ke perakit dan menjadi tag di Harbor yang tidak dapat ditimpa. |
| Agen mengambil tag rilis dari Harbor | Agen menarik image **lewat digest** yang tertulis di manifest rilis bertanda tangan. Tag hanya untuk dibaca manusia dan untuk retensi. |
| Agen yang mendistribusikan rilis | **admin.erp** yang memutuskan server klien mana memasang rilis mana, dan kapan. Agen di setiap server klien hanya menjemput operasi miliknya sendiri. |
| Server klien memegang akun registry | Server klien tidak pernah memegang kredensial tetap. Setiap operasi mendapat robot **pull-only** dari admin.erp yang mati bersama operasinya. |

## Gambaran besar

```mermaid
flowchart LR
    subgraph Tim["Tim pengembang"]
        BR[Branch dan PR] --> CI{CI hijau?}
        CI -->|ya| MAIN[main]
    end

    subgraph S1["Server pertama"]
        MAIN -->|deploy-dev, otomatis| SAAS[SaaS dev dan admin.erp]
        MAIN -. operator menjalankan perakit .-> RAKIT[Perakit: build, uji, tanda tangan]
        RAKIT -->|push image dan pendamping| HARBOR[(Harbor<br/>registry.erp.grenery.xyz)]
        RAKIT -->|daftarkan manifest v2| SAAS
    end

    subgraph Klien["Server klien"]
        AGEN[Agen, setiap menit] -->|jemput operasi, berkas rilis, kredensial| SAAS
        AGEN -->|pull lewat digest| HARBOR
        AGEN --> UPDATE[update.sh: cadangan, migrasi, nyalakan, periksa, mundur bila gagal]
    end

    OP[Operator di admin.erp] -->|pilih rilis untuk situs| SAAS
```

Tiga garis yang sering tertukar:

- **Garis putus-putus dari `main` ke perakit bukan otomatis.** Tidak setiap merge menjadi rilis.
- **Tidak ada panah dari server pertama ke server klien.** Server pertama tidak pernah menghubungi server
  klien; agenlah yang menghubungi admin.erp dan Harbor.
- **Operator memilih rilis di admin.erp, bukan di Harbor.** Harbor hanya menyimpan image.

## Tahap demi tahap

| Tahap | Siapa | Di mana | Yang dikerjakan | Penjaga |
| --- | --- | --- | --- | --- |
| 1. Pengembangan | Pengembang | Branch dan PR | Mengubah kode, menulis test | CI: `quality`, `ci`, `konsol`, `agen`, `edisi` |
| 2. Merge | Pengembang atau reviewer | GitHub | Merge ke `main` | Cabang utama dikunci; lihat [CI/CD](22-ci-cd.md) |
| 3. SaaS dev | Otomatis | Server pertama | `deploy-dev` memasang `main` ke SaaS dev dan admin.erp | Kesehatan container sesudah deploy |
| 4. Merakit rilis | Operator dengan akses root server pertama | Server pertama | `sudo bash deploy/perakit/rakit.sh --rilis 0.2.1 --ref <commit>` | `uji-image.sh`, tag immutable, compose wajib `pull_policy: never`, tanda tangan diperiksa ulang |
| 5. Pendaftaran | Perakit | admin.erp | Manifest dan berkas rilis dikirim ke `POST /api/releases/v1` | admin.erp memeriksa tanda tangan dengan kunci publik rilisnya sendiri |
| 6. Memilih rilis | Operator | admin.erp | Pemasangan pertama: **Buat perintah pasang** di panel Server klien, memakai rilis terbaru. Pembaruan: minta operasi `upgrade` di halaman situs | Rilis harus terdaftar dan lebih baru dari yang terpasang |
| 7. Pemasangan pertama | Teknisi di lokasi klien | Server klien | Menempel perintah `curl … \| sudo bash` sekali | `pasang.sh` menolak folder dan proyek compose milik stack lain |
| 8. Menjemput dan memasang | Agen | Server klien | Lihat diagram di bawah | Tanda tangan, digest, kredensial per operasi, jendela pembaruan |
| 9. Selesai | Agen dan admin.erp | Keduanya | Hasil dilaporkan, robot registry dihapus, jejak audit tercatat | Robot tersapu juga bila operasi gagal atau tenggatnya habis |

`upgrade` hanya diserahkan kepada agen **di dalam jendela pembaruan situs**. `install` kapan saja, karena
belum ada yang sedang dilayani di server itu.

## Satu pembaruan, langkah demi langkah

```mermaid
sequenceDiagram
    autonumber
    actor OP as Operator
    participant A as admin.erp
    participant G as Agen (server klien)
    participant H as Harbor
    participant U as update.sh

    OP->>A: minta upgrade ke rilis 0.2.1
    Note over G: timer systemd setiap menit
    G->>A: klaim operasi (bertanda tangan kunci situs)
    A-->>G: upgrade 0.2.1, lease 15 menit
    G->>A: unduh manifest.json, compose.yaml, update.sh, SHA256SUMS, .sig
    G->>G: periksa tanda tangan dengan kunci publik rilis yang dipaku saat pasang
    G->>A: minta kredensial registry untuk operasi ini
    A->>H: buat robot pull-only, umur maksimum 1 hari
    A-->>G: registry, username, password
    G->>H: login ke DOCKER_CONFIG sementara, pull core dan pendamping lewat digest
    G->>G: RepoDigests harus sama dengan manifest, lalu beri tag coreerp.local/*
    G->>H: logout, hapus DOCKER_CONFIG
    G->>U: jalankan update.sh dari rilis
    U->>U: cadangan database, migrasi, nyalakan, periksa kesehatan
    alt gagal
        U->>U: pulihkan image dan database sebelumnya
    end
    G->>A: laporkan langkah dan hasil akhir
    A->>H: hapus robot operasi ini
```

Selama pull yang panjang agen terus melaporkan langkahnya, sehingga lease tidak habis. Pemasangan pertama di
server dengan unduhan lambat bisa memakan waktu berjam-jam, karena image pendamping gotenberg saja ratusan
megabyte. Pembaruan rutin hanya menarik lapisan kode yang berubah, sekitar 11 MB.

## Siapa memegang apa

| Rahasia atau identitas | Tempatnya | Gunanya | Kalau bocor |
| --- | --- | --- | --- |
| Kunci privat rilis | Server pertama, root 0600 | Menandatangani berkas rilis | Rilis palsu dapat ditandatangani. Dipindah ke mesin terpisah sebelum klien produksi pertama. |
| Kunci publik rilis | admin.erp dan setiap server klien | Memeriksa tanda tangan rilis | Tidak rahasia |
| Robot perakit | Server pertama, root 0600 | Push image rilis ke Harbor | Tag rilis tidak dapat ditimpa, dan image tanpa tanda tangan sah tidak pernah dijalankan agen |
| Token rilis | Konsol dan perakit | Mendaftarkan rilis ke admin.erp | admin.erp tetap menolak berkas yang tanda tangannya tidak sah |
| Robot sistem konsol | Database admin.erp, terenkripsi | Membuat dan menghapus robot situs | Robot baru hanya dapat pull, tidak dapat push |
| Robot situs | Memori agen selama satu operasi | Pull image | Mati bersama operasinya, paling lama satu hari |
| Kunci situs | Server klien, root 0600 | Menandatangani setiap permintaan agen ke admin.erp | Dicabut dari admin.erp; situs yang dicabut tidak dapat menarik apa pun lagi |

## Kenapa bentuknya begini

- **Rilis tidak otomatis setiap merge.** `main` berubah beberapa kali sehari, sedangkan klinik diperbarui di
  jendela pembaruannya. Setiap rilis juga menambah lapisan yang tidak dapat ditimpa di Harbor. Rilis adalah
  keputusan, dan nomornya dipilih orang.
- **Digest, bukan tag.** Tag dapat dipindahkan siapa pun yang memegang hak push; digest adalah sidik jari isi
  image. Digest itu tertulis di manifest yang ditandatangani kunci rilis, dan kunci itu tidak dipegang robot
  mana pun — jadi robot perakit yang dicuri tetap tidak dapat membuat server klien menjalankan image lain.
- **Tarik, bukan dorong.** Server klien tidak dibuka dari internet. Agen yang menghubungi keluar; admin.erp
  hanya menaruh operasi di antrean.
- **Host registry tidak pernah tersimpan di server klien.** Alamat dan kredensial datang di setiap operasi,
  dan compose hanya mengenal nama lokal `coreerp.local/*` dengan `pull_policy: never`. Registry dapat pindah
  tanpa menyentuh satu pun server klien, dan tag lokal yang hilang membuat compose gagal alih-alih diam-diam
  menarik dari Docker Hub.
- **Satu image untuk semua klien.** Image berisi Core dan seluruh modul. App yang boleh dibuka tenant diatur
  dari admin.erp dan dijaga lisensi yang mengunci, bukan dengan membuang modul dari image.

## Nomor rilis

Nomor rilis dipilih orang yang menjalankan perakit. Kode hanya menegakkan **bentuk dan urutannya**. Arti
setiap angka adalah kesepakatan tim yang ditulis di bagian ini, dan tidak ada pemeriksa yang menegakkannya.

Yang ditegakkan kode:

- **Satu nomor untuk satu isi.** Nomor yang sudah ada di Harbor dari commit lain ditolak perakit sebelum
  membangun, dan ditolak admin.erp bila isinya berbeda. Isi yang berubah berarti nomor berikutnya.
- **Rilis hanya boleh maju.** admin.erp dan agen sama-sama menolak pembaruan ke rilis yang sama atau lebih
  lama; mundur hanya terjadi di dalam `update.sh` ketika pembaruan gagal.
- **Migration harus tetap dapat dipakai kode rilis sebelumnya**, karena yang dimundurkan saat rilis bermasalah
  adalah image, bukan database. Aturannya di [Release dan on-prem](03-release-and-on-prem.md#perubahan-skema-dan-mundur).

### Selalu tiga angka: `MAYOR.MINOR.PATCH`

Tulis `0.2.1`, `1.0.0`, `1.4.2`. Tanpa akhiran seperti `-rc1` atau `-beta`, tanpa nol di depan seperti
`01.2.0`, dan tanpa angka keempat.

Kode menerima bentuk yang lebih longgar, dan tidak sama longgarnya di setiap tempat:

| Tempat | Yang diterima |
| --- | --- |
| Perakit (`deploy/perakit/rakit.sh`) dan admin.erp (`apps/control-plane/app/Sites/ReleaseRegistry.php`) | Dua sampai empat angka; nol di depan lolos |
| Agen (`rilis_sah` di `deploy/agent/coreerp-agent`) | Sampai enam angka; nol di depan ditolak |

Selisih itu punya dua akibat, dan keduanya hilang bila nomornya selalu tiga angka tanpa nol di depan:

- **Nomor yang lolos perakit tetapi ditolak agen tetap hangus.** `0.02.1` terdorong ke Harbor dengan tag yang
  tidak dapat ditimpa dan terdaftar di admin.erp, lalu setiap agen menolak memasangnya.
- **admin.erp dan agen tidak sepakat soal nol di ujung.** admin.erp membandingkan dengan `version_compare`
  PHP, yang menilai `0.2.0` lebih baru dari `0.2`. Agen membuang `.0` di ujung lebih dahulu, sehingga baginya
  keduanya rilis yang sama.

Keduanya membandingkan angka sebagai angka, bukan sebagai teks: `0.10.0` lebih baru dari `0.9.3`.

### Angka mana yang dinaikkan

| Naikkan | Bila rilis ini berisi | Contoh |
| --- | --- | --- |
| **PATCH** `1.4.2 → 1.4.3` | Hanya perbaikan, **tanpa migration** | Total faktur salah hitung, tombol yang galat |
| **MINOR** `1.4.3 → 1.5.0` | Fitur atau modul baru, atau migration apa pun yang lolos aturan N-1, termasuk langkah `@kontrak` | Laporan baru, modul baru, kolom baru |
| **MAYOR** `1.5.0 → 2.0.0` | Perubahan yang menuntut pihak di luar kode ikut bertindak | Lihat di bawah |

Angka di sebelah kanan yang dinaikkan kembali ke nol: `1.4.3 → 1.5.0`, `1.5.0 → 2.0.0`.

**PATCH tidak membawa migration.** Rilis perbaikan biasanya dikirim di luar ritme biasa, ketika ada yang rusak
di klinik. Tanpa migration, mundur darinya cukup dengan menjalankan image sebelumnya, dan tidak ada langkah
skema yang dapat gagal di tengah jendela pembaruan. Perbaikan yang butuh migration dikirim sebagai MINOR.

Periksa sebelum merakit. Commit rilis sebelumnya tertulis di manifest-nya di server pertama:

```bash
lama="$(jq -r .commit /var/lib/coreerp-perakit/rilis/<rilis-sebelumnya>/manifest.json)"
git diff --name-only "$lama" <commit-yang-akan-dirakit> -- \
    apps/core/database/migrations ':(glob)modules/*/*/database/migrations/**'
```

Keluaran kosong berarti PATCH boleh. Awalan `:(glob)` dan `/**` wajib: tanpa keduanya, pola `modules/*/*/…`
tidak mencocokkan berkas di dalam foldernya, dan perintah itu diam-diam melaporkan tidak ada migration modul.

**MAYOR** bila salah satunya terjadi:

- fitur dibuang, atau perilakunya berubah sampai pengguna harus diberi tahu sebelum pembaruan;
- endpoint atau event yang dipakai pihak di luar CoreERP berubah secara tidak kompatibel;
- server klien butuh sesuatu yang tidak dapat dipasang agen sendiri, seperti versi Docker, sumber daya, port,
  atau sistem operasi.

**Langkah `@kontrak` bukan alasan MAYOR.** Ia hanya menghapus yang sudah tidak dibaca rilis sebelumnya, jadi
mundur satu rilis tetap aman. Yang tidak aman adalah mundur melewati langkah expand-nya. Batas itu dihitung mesin
dari manifest ([MK-02](/todo/rilis-kompatibel-mundur/)), bukan dibaca operator dari nomor rilis.

### `0.x` sampai klien produksi pertama

Selama belum ada server klien produksi, nomornya `0.MINOR.PATCH`. Rilis pertama yang dipasang di klien
produksi adalah `1.0.0`, dan sejak itu arti MAYOR berlaku penuh. Janji "MAYOR berarti ada yang putus" baru
berguna bila sudah ada pihak yang dapat diputus; [Semantic Versioning](https://semver.org/#spec-item-4)
memakai batas yang sama.

### Tanpa nomor build

Sebagian produk memakai empat angka, misalnya `1.2.3.14421`. Angka keempat adalah hitungan build, karena di sana
versi yang sama dibangun berkali-kali. Di sini satu nomor hanya punya satu isi, jadi hitungan build tidak
membedakan apa pun.

"Rilis ini dari kode yang mana" dijawab manifest, bukan nomor. Commit sumbernya tertulis di `commit` manifest
v2, yang wajib ada supaya admin.erp mau mendaftarkannya, dan di label `org.opencontainers.image.revision`
image-nya.

Build percobaan untuk server uji memakai nomor berikutnya sesuai isinya. Nomor itu murah, dan nomor yang
hangus tidak merugikan siapa pun.

## Yang belum ada

- **Uji ujung-ke-ujung di server klien sungguhan** (E2E-01). Setiap bagian diuji sendiri-sendiri, dan
  kredensial registry diuji terhadap Harbor sungguhan, tetapi belum ada server klien yang dipasang dari awal
  sampai akhir lewat alur ini.
- **Penandatanganan di mesin terpisah** sebelum klien produksi pertama.
- **Tag `terpasang-*` dan retensi otomatis** (CP-05). Sampai itu ada, rilis lama tidak dibuang dari Harbor.
- **Jalur lama masih berjalan.** `.github/workflows/release.yml` masih membangun image per edisi dan
  mendorongnya ke GHCR pada setiap merge ke `main` (PK-05). Server klien yang dikelola tidak memakainya.
- **Mundur ke rilis sebelumnya atas perintah operator.** Hari ini mundur hanya terjadi otomatis saat
  pembaruan gagal.

## Lihat juga

- [Release dan on-prem](03-release-and-on-prem.md) — edisi, migration yang kompatibel mundur, dan on-prem perpetual.
- [CI/CD](22-ci-cd.md) — pemeriksaan yang harus hijau sebelum merge.
- `deploy/perakit/README.md` — cara merakit dan mendaftarkan rilis.
- `deploy/registry/README.md` dan `deploy/registry/RUNBOOK.md` — Harbor, robot, dan prosedur operator.
- `deploy/agent/README.md` — agen di server klien.
