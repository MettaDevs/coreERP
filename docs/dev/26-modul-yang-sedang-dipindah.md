# Modul yang sedang dipindah masuk

Sebuah repo modul yang selama ini berdiri sendiri (`app-erp-*`) ditarik ke dalam `modules/apperp/`
lewat `git subtree add`, apa adanya, tanpa satu berkas pun di dalamnya diubah — supaya `git log` dan
`git blame` ikut pindah, dan supaya pull request pemindahannya bisa ditinjau sebagai "hanya berpindah
tempat".

Akibatnya bisa ditebak: begitu foldernya mendarat, penjaga batas Core merah sekaligus. Kodenya masih
ber-namespace `App\`, masih memanggil `DB::table(`, manifestnya belum menyatakan awalan tabel, dan
modelnya belum memakai `MilikTenant`. Penjaganya benar; yang belum lengkap adalah rencananya.

Halaman ini menjelaskan cara Core menampung keadaan setengah jalan itu tanpa membuka lubang permanen —
aturannya, dan kenapa aturannya begitu.

## Keadaan sekarang: daftarnya kosong

Daftar modul yang sedang dipindah ada di
`apps/core/app/Support/Modules/ModulSedangDipindah.php`, dan **isinya sekarang kosong**.
Begitu juga daftar modul yang belum ikut analisa tipe PHP,
`apps/core/app/Support/Modules/ModulTanpaAnalisaTipe.php`, dan ketiga berkas pengecualian yang
mengikutinya (`apps/core/.prettierignore`, `exclude` pada
`apps/core/tsconfig.json`, `excludePaths` pada `apps/core/phpstan.neon`).

Kosong adalah keadaan yang sehat, dan layak disebut apa adanya: setiap modul yang ada di `modules/`
hari ini dipindai penuh oleh seluruh penjaga, tanpa satu pun kelonggaran.

Kelasnya tetap ada karena modul berikutnya akan mendarat dengan keadaan yang sama —
`app-erp-procurement` belum dipindah. Dan karena daftarnya kosong, aturan-aturan di bawah dibuktikan
pada entri buatan; alasannya dijelaskan di [bagian tersendiri](#daftar-kosong-tetap-harus-membuktikan-aturannya).

## Dua hal yang mudah tertukar

**"Belum boleh dilayani" bukan "kodenya tidak boleh dimuat".** Modul yang sedang dipindah belum boleh
muncul di katalog, belum boleh dipasang lewat `InstallModule`, dan belum boleh menerima data tenant.
Tetapi kodenya harus tetap dimuat — modul yang kodenya tidak dimuat tidak punya satu pun test yang bisa
berjalan, dan pemindahannya jadi dikerjakan tanpa jaring pengaman sampai hari terakhir. Pembedaan ini
dijaga `ModuleRegistry`; lihat [Registry membedakan dilayani dari dimuat](#registry-membedakan-dilayani-dari-dimuat).

**"Bersih menurut batas" bukan "bersih menurut tipe".** Sampai 9 September 2026 keduanya satu daftar,
dengan anggapan seluruh pengecualian sebuah modul berakhir bersamaan. Anggapan itu terbukti salah pada
hari modul aset selesai dipindah: ia lulus seluruh penjaga batas, lulus pemeriksaan tipe frontend, dan
lulus pemeriksaan gaya, sambil masih menyisakan ratusan temuan analisa tipe PHP. Menyatukan keduanya
berarti tonggak yang lebih lambat menyandera yang lebih cepat — modul harus tetap dianggap sedang
dipindah, dengan seluruh penjaga batasnya ikut mati, hanya karena anotasi tipenya belum ditulis. Karena
itu `ModulTanpaAnalisaTipe` berdiri sendiri, dengan tenggat dan penjaganya sendiri.

## Aturan pengecualian penjaga

Empat bagian, dan semuanya saling mengunci. Membuang salah satunya membuat tiga sisanya kehilangan
gunanya.

### Pengecualian ditulis di kode, bukan di berkas setelan

Daftarnya ditulis sebagai konstanta di dalam kelas PHP, mengikuti pola `PENGECUALIAN` yang sudah dipakai
penjaga tabel. Alasannya satu dan cukup: **pengecualian baru wajib terlihat pada diff pull request.**
Pengecualian yang bersembunyi di berkas setelan bisa bertambah tanpa ada yang menyadarinya, dan
pengecualian yang tidak ditinjau siapa pun hanya diwarisi.

### Penandanya hidup di sisi CoreERP, bukan di dalam folder modul

Tempat yang paling wajar untuk menandai "modul ini belum siap" tampaknya `app.yaml` milik modul itu
sendiri. Itu justru tempat yang salah, dan alasannya tidak bisa ditawar: `app.yaml` berada **di dalam**
subtree, jadi penanda di sana akan terhapus setiap kali subtree ditarik ulang dari repo asalnya — repo
yang tidak tahu apa-apa tentang CoreERP dan tidak punya alasan untuk menjaga baris itu tetap ada.

Daftarnya juga dikunci pada **nama folder**, bukan `id` manifest. Penjaga namespace tidak pernah membaca
`app.yaml` sama sekali, dan modul yang belum dibentuk ulang mungkin manifestnya belum terbaca dengan
bentuk yang diharapkan. Nama folder adalah satu-satunya penanda yang dipegang semua penjaga tanpa syarat.

Konsekuensinya jujur, dan perlu ditulis di sini supaya tidak ditemukan orang lain sebagai kejutan:
**mengganti nama folder modul membuat pengecualiannya diam-diam tidak berlaku.**

### Pengecualian wajib punya dua cara berakhir

Keduanya harus ada, karena masing-masing menjawab pertanyaan yang berbeda.

| Cara berakhir | Pertanyaan yang dijawabnya | Bentuknya di kode |
| --- | --- | --- |
| Tenggat | "Kapan ini harus selesai?" | `tenggat` pada tiap entri; setelah lewat, alur menjadi merah |
| Pemeriksaan basi | "Bagaimana orang tahu ini sudah boleh dibuang?" | Modul yang dikecualikan tetap dipindai penuh; bila ia tidak lagi melanggar apa pun, **entrinya** yang gagal |

Yang kedua adalah bentuk yang membuat seluruh mekanisme ini bekerja tanpa mengandalkan ingatan siapa pun.
**Pengecualian di sini pembalik, bukan pelewat**: ia tidak melewatkan pemindaian, ia hanya membalik arti
hasilnya. Modul yang ditandai tetap dipindai selengkap modul lain; yang berubah hanyalah apa yang dianggap
kegagalan.

Tenggat pun tidak bisa diperpanjang diam-diam. Memperpanjang berarti mengubah baris di berkas itu, dan
baris itu terlihat pada diff.

### Pemeriksaan basi dinilai utuh per modul, bukan per dimensi

Godaannya adalah menilai tiap dimensi sendiri-sendiri: namespace sudah bersih, jadi buang kelonggaran
namespace-nya. Itu jebakan. Modul yang namespace-nya sudah dibereskan tetapi penyaringan tenantnya belum
akan dituntut membuang entrinya, dan penjaga berikutnya langsung merah. Perincian yang terlalu halus di
sini berubah menjadi pekerjaan yang tidak mungkin diselesaikan berurutan.

Karena itu penilaiannya utuh: sebuah entri baru dianggap basi ketika modulnya bersih menurut **seluruh**
pemindaian, bukan menurut salah satunya.

## Setiap berkas pengecualian Core wajib mendaftar modul yang sama persis

Penjaga batas bukan satu-satunya pemeriksaan Core yang menjangkau `modules/`. Pemeriksaan gaya frontend,
pemeriksaan tipe frontend, dan analisa statis PHP juga — masing-masing benar sendiri-sendiri, dan
masing-masing mulai menjangkau modul yang belum siap dijangkau begitu foldernya mendarat.

Yang menyatukannya satu daftar. `ModulSedangDipindahTest` menjaganya lewat satu test bertabel: setiap
berkas pengecualian Core wajib mendaftar modul yang **sama persis** dengan daftar modul yang sedang
dipindah. Menambahkan pemeriksaan berikutnya ke tabel itu satu baris.

Dua arah sama pentingnya, dan yang kedua justru lebih berbahaya:

- Entri yang **kurang** membuat alur merah pada berkas yang memang belum dibentuk ulang. Mengganggu,
  tapi terlihat.
- Entri yang **tertinggal** setelah modulnya selesai dipindah membiarkan modul jadi lolos pemeriksaan
  selamanya — dan itu tidak terlihat siapa pun, karena tidak ada yang gagal.

Berkas mana saja yang masuk tabel itu dibaca dari `berkasPengecualian()` pada
`apps/core/tests/Feature/Boundary/ModulSedangDipindahTest.php`. `phpstan.neon` sengaja tidak di
sana: pengecualian analisa tipe PHP punya daftar dan penjaganya sendiri, karena kedua pengecualian itu
memang berakhir pada waktu yang berbeda.

## `table_prefix` wajib dinyatakan, dan melewatkannya tanpa suara dilarang

Modul yang tidak menyatakan `table_prefix` pada manifestnya belum bisa dilayani: tabelnya akan memakai
nama apa adanya dan bertabrakan dengan milik Core. `ModuleRegistry` karena itu melewatkan manifest tanpa
awalan tabel.

Melewatkan tidak boleh berarti menghilang tanpa suara. Sebuah folder yang manifestnya tanpa awalan tabel
**dan** tidak terdaftar sedang dipindah membuat alur merah dengan pesan yang menyebut namanya. Tanpa
aturan ini, modul semacam itu tidak akan ditemukan siapa pun dan tidak ada yang gagal karenanya — persis
kegagalan diam yang paling mahal ditemukan belakangan.

Perlu dicatat urutan sejarahnya, karena ia menjelaskan kenapa daftarnya ada. `table_prefix` yang belum
dinyatakan sempat dipakai sebagai tanda "modul ini belum siap dilayani". Tanda itu bekerja tepat sampai
task yang justru memberi awalan tabel: begitu awalannya dinyatakan, registry menyalakan modul yang
`tenant_id`-nya belum ada dan query mentahnya belum diganti. **Tanda kesiapan yang ikut berubah karena
pekerjaan setengah jalan bukan tanda kesiapan.** Sekarang tandanya daftar yang ditulis sengaja, dan
`table_prefix` kembali menjadi apa adanya: syarat yang berlaku untuk modul yang tidak sedang dipindah.

## Bentuk sebuah entri

Setiap entri wajib menyebut tiga hal, dan ketiganya dijaga test:

| Bagian | Syarat | Kenapa |
| --- | --- | --- |
| `alasan` | Menyebut angka yang benar-benar diukur pada repo modul sebelum pemindahan, bukan perkiraan | Alasan yang tidak bisa diperiksa tidak bisa ditinjau, hanya bisa diwarisi |
| `tenggat` | Ditulis `YYYY-MM-DD` | Format bebas membuat tenggatnya tidak bisa dibandingkan mesin, dan tenggat yang tidak diperiksa mesin bukan tenggat |
| `pemblokir` | Opsional, tetapi bila diisi wajib menyebut nomor task yang membuangnya | Tanpa syarat ini `pemblokir` menjadi pintu keluar bebas: satu kalimat apa pun akan membuat pemeriksaan basi diam selamanya |

`pemblokir` sebaiknya tetap kosong. Ia diisi hanya ketika modulnya sudah bersih menurut pemindaian
berkas tetapi entrinya masih belum boleh dibuang karena sesuatu yang tidak dapat dilihat pemindai mana
pun. Premis semula — "bersih berarti entrinya basi" — terbukti salah sekali: sebuah modul lulus seluruh
pemindaian, tetapi membuang entrinya membuatnya **dilayani**, dan itu menjatuhkan puluhan test Core yang
fixture katalognya belum menggambarkannya sebagai app yang dapat dipasang. Tenggat tetap berlaku penuh
walau `pemblokir` diisi.

## Daftar kosong tetap harus membuktikan aturannya

Daftar yang kosong membuat setiap pemeriksaan yang membacanya hijau **tanpa subjek** — hijau tanpa
menguji apa pun. Itu persis keadaan yang berulang kali dilarang berkas-berkas ini sendiri, dan ia jauh
lebih berbahaya daripada merah, karena ia terlihat seperti keberhasilan.

Karena itu kedua kelas daftar menyediakan pintu untuk daftar buatan (`ModulSedangDipindah::buatan()` dan
`ModulTanpaAnalisaTipe::dariDaftar()`), dan testnya membuktikan aturannya pada entri buatan: penghalang
tanpa nomor task ditolak, tenggat berformat salah ditolak, entri yang menyatakan penghalang tidak
dihitung basi walau modulnya sudah bersih, dan melonggarkan untuk satu modul tidak melonggarkan untuk
modul lain.

Pintu daftar buatan juga menyelesaikan masalah kedua: tanpanya, satu-satunya cara menguji perilaku daftar
adalah menambah modul sungguhan ke daftar sungguhan, dan itu berarti testnya ikut berubah setiap kali
daftarnya berubah.

## Registry membedakan dilayani dari dimuat

`apps/core/app/Support/Modules/ModuleRegistry.php` punya dua pintu, dan bedanya bukan
kenyamanan:

| Pintu | Untuk apa | Modul yang sedang dipindah |
| --- | --- | --- |
| `semua()` | Katalog, pemasangan, dan apa pun yang menyentuh data tenant | Tidak ikut |
| `semuaTermasukYangSedangDipindah()` | Memuat kode, yaitu mendaftarkan penyedia layanan modul | Ikut |

Pintu kedua dipakai **hanya** untuk mendaftarkan penyedia layanan modul. Memakainya untuk katalog,
pemasangan, atau apa pun yang menyentuh data tenant membatalkan seluruh gunanya.

Migration mengikuti pembedaan yang sama, dan bentuknya berbeda dari modul yang sudah jadi:

- **Modul yang sedang dipindah** — migrationnya dimuat bersama migration Core, di `boot()` pada
  `apps/core/app/Providers/ModuleServiceProvider.php`. Modul itu belum boleh dipasang untuk
  tenant mana pun, jadi tabelnya tidak punya cara lain untuk ada; dan tanpa tabel, tidak satu pun
  testnya bisa berjalan.
- **Modul yang sudah jadi** — migrationnya dijalankan `ModuleMigrator` saat modul dipasang untuk sebuah
  tenant, dan dicatat per modul supaya pencabutan bisa dilacak.

Keduanya berakhir sendiri: begitu modul keluar dari daftar, ia lewat jalur yang sama seperti modul lain
dan baris di `boot()` berhenti berlaku untuknya.

## Yang tidak pernah dikecualikan

Tidak semua penjaga menerima daftar ini. Tiga di antaranya berlaku untuk **semua** modul tanpa kecuali,
termasuk yang sedang dipindah, dan masing-masing punya alasannya sendiri.

**Bentuk folder modul.** Sebuah folder modul tidak boleh membawa kerangka aplikasi sendiri: `artisan`,
`bootstrap/app.php`, `public/index.php`, dan migration yang membuat tabel milik Core (`users`, `jobs`,
`job_batches`, `failed_jobs`, `cache`, `cache_locks`). Membuang kerangka adalah **langkah pertama**
pemindahan, jadi tidak ada keadaan sah di mana sebuah folder modul boleh membawanya. Yang paling
berbahaya bukan berkas kerangkanya melainkan migrationnya: tabrakannya pasti, dan ia muncul saat
pemasangan modul di tenant sungguhan — bukan saat ada yang sedang memperhatikan. Susunan folder yang
berlaku ada di [standar app dan addon app](02-module-standard.md).

**`MilikTenant` pada tiap kelas yang `extends Model`.** Penyaringan tenant bukan hal yang boleh menunggu:
modul yang sudah dipasang di tenant sungguhan sambil menunggu dibereskan adalah modul yang sudah
membocorkan data. Penjaganya membaca berkas, bukan mengandalkan pewarisan model dasar — model dasar hanya
menjaga yang mewarisinya, dan pada satu modul ada tiga model yang tidak mewarisi model dasarnya, termasuk
yang memegang data terpentingnya.

**Lompatan HTTP dari modul ke Core.** Penjaga ini berbeda jenisnya dari yang lain: ia bukan pagar yang
menunggu modul menyusul, ia adalah **kriteria selesai** dari pekerjaan pemindahan itu sendiri.
Mengecualikan modulnya berarti penjaga ini hijau justru pada satu-satunya modul yang ia dimaksudkan untuk
menilai — hijau tanpa pernah bisa merah, yang tidak menjaga apa pun. Konsekuensinya juga disengaja:
seandainya sebuah modul mendarat besok dengan klien HTTP-nya masih utuh, penjaga ini merah sejak hari
pertama dan pemindahannya tidak bisa digabung sebelum jalurnya diganti. Itu urutan yang benar — jalur
HTTP adalah hal yang paling mahal ditinggalkan setengah jadi, karena ia tetap bekerja di lingkungan
pengembangan dan baru gagal saat Core dan modul tidak lagi saling melihat.

## Sebelum sebuah repo modul ditarik masuk

Menarik repo modul masuk lewat `git subtree add` adalah titik yang tidak bisa dibalik dengan mudah. Sejak
saat itu pekerjaan bercabang, dan repo lama menjadi basi walau masih bisa ditulis. Mengarsipkan repo lama
belakangan hanya membuat keadaan itu terlihat, bukan menciptakannya.

Karena itu, **sebelum** subtree ditarik, repo modulnya wajib dalam keadaan bersih dan seluruh commit-nya
sudah terdorong ke remote. Pemeriksaan itu adalah **langkah pertama** pekerjaan pemindahan, bukan
anggapan — commit yang hanya ada di mesin seseorang tidak akan ikut pindah, dan hilangnya baru ketahuan
setelah repo lama tidak lagi dipakai siapa pun.

Aturan ini masih berlaku: `app-erp-procurement` belum dipindah.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `apps/core/app/Support/Modules/ModulSedangDipindah.php` | Daftar modul yang sedang dipindah, beserta alasan, tenggat, dan penghalangnya |
| `apps/core/app/Support/Modules/ModulTanpaAnalisaTipe.php` | Daftar modul yang belum ikut analisa tipe PHP |
| `apps/core/app/Support/Modules/ModuleRegistry.php` | Pembedaan `semua()` dan `semuaTermasukYangSedangDipindah()` |
| `apps/core/app/Providers/ModuleServiceProvider.php` | Pendaftaran penyedia layanan modul dan pemuatan migration modul yang sedang dipindah |
| `apps/core/tests/Feature/Boundary/ModulSedangDipindahTest.php` | Tenggat, pemeriksaan basi, syarat entri, dan kesamaan berkas pengecualian |
| `apps/core/tests/Feature/Boundary/ModulTanpaAnalisaTipeTest.php` | Kesamaan daftar dengan `excludePaths` pada `phpstan.neon`, dan tenggatnya |
| `apps/core/tests/Feature/Boundary/PemindaiModul.php` | Pemindaian folder modul yang dipakai bersama penjaga dan pemeriksaan basi |
| `apps/core/tests/Feature/Boundary/` | Seluruh penjaga batas; docblock masing-masing menyebut apakah ia membaca daftar ini |

## Lihat juga

- [Standar app dan addon app](02-module-standard.md) — susunan folder modul, nama tabel, dan `MilikTenant`
- [Schema dan query scope](08-query-scopes-and-schema.md) — penyaringan tenant yang dijaga `MilikTenant`
- [Grand design](01-grand-design.md) — dua bentuk yang hidup berdampingan, modul di dalam runtime Core dan app yang belum dipindah
- [Kondisi repository sekarang dan target pemisahan app](06-worktree-target.md) — arah pemindahan repo modul
- [CI/CD](22-ci-cd.md) — alur yang menjalankan pemeriksaan gaya, tipe, dan analisa statis di atas
