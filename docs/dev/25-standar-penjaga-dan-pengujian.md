# Standar penjaga dan pengujian

Halaman ini mengumpulkan aturan yang mengatur **penjaga** — test dan pemeriksaan otomatis yang menegakkan sebuah batas arsitektur, bukan yang menguji satu fitur. Semuanya lahir dari kegagalan yang benar-benar terjadi di repository ini selama modul dipindahkan ke satu runtime, dan hampir semuanya lahir dari bentuk kegagalan yang sama: sebuah pemeriksa melaporkan hijau padahal tidak memeriksa apa pun.

Penjaga yang berlaku hari ini ada di `apps/core/tests/Feature/Boundary/`. Halaman ini menjelaskan aturan yang harus dipenuhi penjaga baru sebelum ia boleh dipercaya, dan kenapa aturannya begitu.

## Penjaga dulu, pemindahan kemudian

Test yang menegakkan sebuah batas harus sudah ada **dan sudah terbukti bisa gagal** sebelum batas itu diandalkan. Penjaga yang ditambahkan belakangan tidak pernah menangkap pelanggaran yang sudah terjadi: ia akan dibuat hijau terhadap keadaan yang ada, termasuk terhadap pelanggaran yang sudah telanjur masuk.

Konsekuensi keduanya lebih halus. Batas modul di sini — satu modul hanya membuat tabel berawalan namanya, tidak menyebut namespace modul lain, tidak melewati penyaringan tenant — **tidak** ditegakkan mesin database. Ia ditegakkan pemeriksaan otomatis, dan itu harus disebut apa adanya. Menulis bahwa "database menjaganya" membuat orang merasa aman tanpa alasan, dan orang yang merasa aman berhenti membaca diff.

## Sebuah kriteria harus punya keadaan yang membuatnya merah

Kalau tidak ada keadaan yang membuat sebuah kriteria berwarna merah, ia bukan kriteria. Sebelum menulis kriteria selesai untuk sebuah pekerjaan, sebutkan satu keadaan yang membuatnya gagal. Kalau tidak ada, ganti kriterianya.

Bentuk yang tampak seperti pemeriksaan padahal bukan, semuanya pernah lolos di sini:

| Terlihat seperti pemeriksaan | Kenapa tidak menguji apa pun |
| --- | --- |
| Mencari jalur lama di repo utama | Rujukannya ada di repo lain, jadi pencariannya selalu hijau sementara stack pengembangan rusak |
| Membangun situs dokumentasi untuk membuktikan folder mati sudah hilang | Rujukannya bukan tautan, jadi pembangunan tidak pernah gagal karenanya |
| Menghitung tabel pada database yang baru dibuat | Nol memang jawaban yang selalu benar di sana |

Ketiganya punya pola yang sama: pemeriksanya benar, tetapi subjeknya tidak pernah ada di tempat yang diperiksa.

## Pemeriksa yang belum pernah terlihat gagal tidak bisa dipercaya

Repository ini punya catatannya sendiri: sebuah pemeriksa cakupan tabel pernah melaporkan sukses justru **karena** ia rusak. Laporan sukses palsu lebih berbahaya daripada tidak ada laporan sama sekali, karena ia menghentikan pencarian lebih cepat.

Karena itu setiap penjaga harus pernah dilihat merah, dan pesan merahnya disimpan. Cara memperbaruinya: ulangi percobaan yang membuatnya merah, tempelkan pesannya apa adanya, lalu pastikan pemeriksanya hijau kembali. **Jangan menyunting pesannya supaya rapi** — pesan yang sudah dirapikan bukan lagi bukti, ia jadi ringkasan yang ditulis orang.

Dua cara membuktikannya, keduanya dipakai di repository ini:

- **Percobaan sekali jalan.** Rusakkan sesuatu dengan sengaja, catat pesannya, kembalikan. Dipakai untuk penjaga yang subjeknya modul sungguhan.
- **Test pendamping yang permanen.** Penjaga membaca daftar pelanggaran; test kedua memberi daftar itu subjek buatan dan menuntutnya merah. `ModulTanpaAnalisaTipeTest::test_tenggat_yang_lewat_terdeteksi` di `apps/core/tests/Feature/Boundary/ModulTanpaAnalisaTipeTest.php` adalah bentuknya — tanpa test itu, fungsi pemeriksanya bisa saja selalu memulangkan daftar kosong.

Bentuk kedua lebih baik bila memungkinkan, karena percobaan sekali jalan hanya membuktikan penjaga bisa gagal **pada hari itu**.

## Dua lapisan yang menjawab pertanyaan sama membuat lapisan pertama tak terukur

Ini pola yang paling sering terulang, dan ia terlihat seperti test yang baik.

Rute modul dilindungi dua lapis: middleware konteks menolak pengguna tanpa izin, dan controller modul memeriksa izin lagi. Test "pengguna tanpa izin mendapat 403" pada rute modul sungguhan terlihat seperti test middleware. Ia bukan. Penolakan di middleware pernah dihapus untuk melihat test itu merah, dan **test itu tetap hijau** — controller modul ikut menjawab 403. Artinya kriteria itu akan menerima middleware yang perlindungannya sudah lenyap seluruhnya.

Yang benar-benar bisa gagal adalah test yang memasang penutup **tidak ikut menjaga** di belakang middleware: sebuah closure yang selalu menjawab 200. Kalau middleware-nya lenyap, jawabannya 200 dan testnya merah.

Bentuknya ada di `apps/core/tests/Feature/Boundary/ModuleRequestContextTest.php` pada `test_middleware_menolak_sebelum_controller_module_sempat_berjalan`, yang berdiri tepat di sebelah test rute modul biasa dengan komentar yang menjelaskan kenapa keduanya diperlukan.

Dua lapis memang disengaja. Yang tidak boleh adalah lapis pertama yang hilangnya tidak terlihat sampai ada satu modul yang lupa memeriksa di controllernya.

## Batas yang dipalsukan tidak menguji sisi seberangnya

`Http::fake` memulangkan jawaban yang disusun test itu sendiri. Jawaban palsu tidak memvalidasi apa pun, jadi permintaan yang salah bentuk terlihat persis seperti yang benar. Ini bukan kelemahan `Http::fake`, melainkan sifatnya — dan pelajarannya sudah muncul berkali-kali di sini, dua kali dengan cacat yang tidak akan tertangkap kontrak OpenAPI mana pun karena cacatnya ada di SQL, bukan di bentuk permintaan.

Bentuk terburuknya: sebuah test siklus hidup aset memalsukan Core dua kali — id instance karangan untuk pengajuan, lalu amplop keputusan yang disusun test itu sendiri dan dikirim ke rute panggilan balik modul. Yang dibuktikannya cuma satu, bahwa modul bisa membaca amplop yang ditulisnya sendiri.

Sejak Core dan modul berjalan dalam satu runtime, aturannya: **jangan memalsukan Core.** Tempuh jalur sungguhan, dan buat kalimat "tanpa satu pun permintaan HTTP" bisa gagal dengan `Http::preventStrayRequests()`.

`apps/core/tests/Feature/Boundary/NoInternalHttpTest.php` menunjukkan bahwa satu lapis saja tidak cukup, dan alasannya layak dibaca sebelum menulis test serupa:

- `Http::fake()` telanjang memulangkan 200 kosong, yang justru membuat lompatan yang tersisa terlihat berhasil. Penangannya harus **melempar**.
- `Http::preventStrayRequests()` menangkap permintaan yang lolos dari pemalsuan itu.
- Daftar permintaan yang dicatat penangan sendiri, diperiksa di akhir. `Http::assertNothingSent()` dipakai lebih dulu dan tidak bekerja: pencatatan baru terjadi setelah penangan memulangkan sesuatu, jadi permintaan yang penanganya melempar tidak pernah tercatat. Karena itu penangannya mencatat lebih dulu, baru melempar.

## Pesan gagal menyebut nama yang salah dan bentuk yang sah

Penjaga batas dijalankan orang yang belum pernah membuka kode pemeriksanya. Pesan "ada yang salah" memaksa mereka membaca pemeriksa itu untuk tahu apa yang harus diubah.

`ModuleTableBoundaryTest` menyusun pesannya begini:

```
Migration module "contoh-a" membuat tabel yang bukan miliknya: contoh_b_m_curian.
Awalan yang sah: "contoh_a_".
```

Nama yang salah dan bentuk yang sah, keduanya di baris yang sama. Penjaga namespace di `apps/core/tests/Feature/Boundary/ModuleNamespaceBoundaryTest.php` mengikuti bentuk yang sama: ia menyebut berkas yang melanggar, namespace yang disebutnya, lalu apa yang sebenarnya boleh disebut modul — kelas Core, kerangka kerja, dan kelasnya sendiri.

Penjaga yang daftarnya boleh berisi pengecualian menambahkan satu hal lagi: pengecualian ditulis di berkas test itu sendiri, beserta alasan dan task yang membereskannya, supaya "sengaja belum" bisa dibedakan dari "terlupakan". `apps/core/tests/Feature/Boundary/RuteModuleTerlindungiTest.php` menyimpan daftarnya sebagai konstanta dengan komentar itu.

## Penjaga namespace membaca berkas, bukan menganalisa tipe

Aturan PHPStan untuk batas namespace sempat ditulis lebih dulu di sini, lalu dibuang karena berlubang: baris `use` dan pemanggilan statis tidak pernah sampai ke aturannya. Analisa tipe melihat ekspresi yang punya tipe; sebuah `use` yang tidak pernah dipakai dan sebuah `Kelas::metode()` tidak selalu jadi ekspresi yang lewat.

Penjaga batas namespace karena itu memindai teks berkas. Ini bukan kompromi kualitas — untuk pertanyaan "apakah berkas ini menyebut nama itu", pembacaan berkas justru yang lengkap.

PHPStan tetap dipakai, untuk pertanyaan lain: kebenaran tipe. Setelannya ada di `apps/core/phpstan.neon`.

## Daftar pengecualian yang boleh kosong tetap harus membuktikan aturannya

`App\Support\Modules\ModulTanpaAnalisaTipe` di `apps/core/app/Support/Modules/ModulTanpaAnalisaTipe.php` mendaftar modul yang belum ikut analisa tipe. Daftar itu sekarang kosong, dan itu hasil yang diinginkan.

Daftar kosong membuat setiap pemeriksaan yang membacanya hijau **tanpa subjek**. Penjaga tanpa subjek tidak boleh dianggap hijau: ia tidak membuktikan aturannya berlaku, ia hanya membuktikan tidak ada yang diperiksa. Karena itu syarat "alasan menyebut angka terukur" dan "tenggat ditulis `YYYY-MM-DD`" dibuktikan pada entri buatan di dalam test, lewat penyedia data yang memberi entri cacat dan menuntut pemeriksanya merah.

Aturan yang sama berlaku untuk daftar pengecualian mana pun yang boleh kosong. Kalau daftarnya bisa kosong, aturannya harus punya subjek buatan.

## Aturan yang batasnya belum jelas tidak dipasang sebagai penjaga

Salah satu aturan rencana — "kontrak ada bila memang ada permukaan yang dipanggil dari luar runtime" — sengaja tidak dipasang sebagai penjaga, karena sesudah semua modul masuk satu runtime hampir tidak ada lagi permukaan yang dipanggil dari luar proses, dan batasnya jadi tidak jelas.

Aturan yang batasnya belum jelas lebih berguna sebagai catatan daripada sebagai penjaga. Dipasang sebagai penjaga, ia akan merah pada hal yang benar, dan orang yang menemuinya belajar melonggarkannya. Kebiasaan melonggarkan itu tidak berhenti di penjaga yang salah.

## Bahan uji tidak boleh bocor ke tempat produksi

Modul palsu untuk pengujian dibuat di folder sementara, bukan di `modules/`. Nama acak per jalan tidak cukup: satu jalan yang mati di tengah meninggalkan sisa yang lalu terbaca `module:list`, penjaga lain, `pint ../../modules`, dan PHPStan yang memang memindai folder itu. Bahan uji yang bocor ke tempat produksi merusak alat lain, bukan hanya dirinya sendiri.

Polanya sudah ada di beberapa tempat dan tinggal diikuti:

| Berkas | Yang dibuat di folder sementara |
| --- | --- |
| `apps/core/tests/Feature/Boundary/PemindaiModul.php` | Modul palsu untuk penjaga batas |
| `apps/core/tests/Feature/Boundary/ModulSedangDipindahTest.php` | Sepasang modul yang identik sampai ke barisnya, beda hanya nama folder |
| `apps/core/tests/Feature/Boundary/SusunanManifestModulTest.php` | Manifest palsu yang membuktikan aturannya bisa merah |
| `apps/core/tests/Feature/ControlPlane/EditionResolverTest.php` | Folder modul yang dibaca resolver edisi |

## Setiap tabel yang diisi migrasi terdaftar sebagai pengecualian pemangkasan

`NumberSequenceConcurrencyTest` memakai `DatabaseTruncation` karena ia butuh data yang benar-benar ter-commit. Daftar pengecualiannya pernah ketinggalan dua tabel referensi yang diisi migrasi. Ia mengosongkan keduanya, lalu test pada berkas lain gagal dengan pesan yang terbaca seperti bug berkas itu sendiri. Kedua tabel ditambahkan berminggu-minggu sebelumnya, jadi jebakan ini menunggu lama tanpa terlihat.

Aturannya: **setiap tabel yang diisi migrasi harus terdaftar sebagai pengecualian pemangkasan.** Tidak ada yang memulihkan isinya di antara test, jadi memangkasnya merusak suite lain yang berjalan sesudahnya — dan kerusakannya muncul di tempat yang salah.

Daftarnya ada di `apps/core/tests/Feature/ControlPlane/NumberSequenceConcurrencyTest.php` pada `$exceptTables`, beserta perintah untuk memeriksanya ulang:

```bash
grep -rho "DB::table('[a-z_]*')->insert" database/migrations/ | sort -u
```

Modul yang masuk pada fase berikutnya akan membawa tabel referensinya sendiri, jadi aturan ini akan diuji lagi.

## Fixture test Core tidak meminjam id produk sungguhan

Fixture katalog di `apps/core/tests/TestCase.php` dulu memakai id modul aset yang sungguhan. Ia bukan salinan buruk dari modul itu; ia bahan uji rantai izin milik Core yang kebetulan meminjam idnya. Selama modul itu belum dilayani runtime, pinjaman itu tidak berakibat apa-apa. Begitu modulnya dilayani, satu id menunjuk dua hal, dan pendaftaran bisnis memilih yang salah.

Perbaikannya satu baris makna: fixture memakai id `app-uji`, yang tidak akan pernah menjadi folder di `modules/`. **Dua sumber kebenaran untuk satu katalog adalah dua sumber yang akan menyimpang** — pertanyaannya kapan, bukan apakah.

Sisi lain aturan yang sama: fixture katalog yang memang harus mewakili modul sungguhan mendaftarkan manifest modul itu apa adanya, lewat perintah `app:register-manifest` di `apps/core/app/Console/Commands/RegisterAppManifestCommand.php` — perintah yang sama dengan yang dijalankan admin on-prem. Daftar kecil yang ditulis tangan di dalam test adalah sumber kebenaran kedua, dan itu yang menyimpang.

## Menjalankan suitenya

Tiga jebakan lingkungan yang pesannya tidak pernah menyebut sebabnya. Ketiganya sudah menghabiskan waktu di sini.

**Test paralel wajib memberi tiap agen `DB_TEST_SCHEMA` sendiri.** Beberapa proses yang menjalankan `RefreshDatabase` bersamaan pada satu schema PostgreSQL saling menghancurkan: `deadlock detected`, `relation "tenants" does not exist`, dan duplikat `pg_class` — puluhan kegagalan yang tidak satu pun menyebut kode yang sedang dikerjakan. Alur CI sudah menyediakan jalan keluarnya; lihat `DB_TEST_SCHEMA` di `.github/workflows/tests.yml`. Kerja paralel yang menjalankan test harus memberi tiap agen schema sendiri, atau menjalankan verifikasinya serial setelah semua agen selesai.

**Batas memori dinaikkan lebih dulu, bukan setelah menemui kegagalan.**

| Perintah | Batas |
| --- | --- |
| `composer types:check` | `--memory-limit=1G`, sudah ter-commit di `apps/core/composer.json` |
| Suite penuh dalam satu proses secara lokal | `php -d memory_limit=1G vendor/bin/phpunit` |

Angka lama PHPStan cukup hanya selama modul dikecualikan dari analisa; begitu seluruh isi `modules/` beserta grafik tipe Eloquent ikut masuk, analisanya berhenti dengan pesan yang tidak menyebut modul sama sekali. Untuk PHPUnit, `memory_limit` bawaan CLI berakhir `Fatal error: Premature end of PHP process` pada test yang tidak ada hubungannya dengan sebabnya — pesannya menyebut test berikutnya. CI tidak terkena karena `setup-php` melepas batasnya dan run-nya paralel, jadi jebakan ini hanya menggigit secara lokal.

**Angka PHPStan hanya sah diukur sesudah simpanannya dibatalkan.** Simpanan hasil analisa menyembunyikan temuan: sebuah berkas pernah dilaporkan dengan temuan yang merujuk keadaan yang sudah tidak ada di disk, dan begitu simpanannya dibatalkan, temuan yang sebenarnya muncul. Setiap angka yang ditulis ke `apps/core/phpstan-baseline.neon` atau ke catatan pekerjaan diukur sesudah `phpstan clear-result-cache`.

Ada satu lapis lagi yang lebih jahat: **mengubah berkas aturan PHPStan buatan sendiri tidak membatalkan simpanan itu.** Selama menulis penjaga namespace, aturannya sudah berjalan sejak awal, tetapi setiap perubahan kodenya disajikan hasil lama dengan nol temuan — terbaca persis seperti "aturannya tidak jalan". Memanggil `clear-result-cache` saja tidak cukup:

```bash
rm -rf "$TEMP/phpstan"
```

Ini berlaku untuk aturan PHPStan apa pun yang ditulis sendiri, bukan hanya penjaga batas.

## Lihat juga

- [Standar app dan addon app](02-module-standard.md) — batas yang dijaga penjaga-penjaga ini, dari sisi bentuk modul
- [Schema dan query scope](08-query-scopes-and-schema.md) — scope tenant dan organisasi yang dijaga penjaga ketiga
- [Gate fondasi Core](10-core-foundation-gates.md) — gate yang harus hijau sebelum modul masuk
- [Load dan concurrency testing](20-load-and-concurrency-testing.md) — pengujian yang subjeknya beban, bukan batas
- [CI/CD polyrepo](22-ci-cd.md) — di mana penjaga ini dijalankan dan apa yang menahannya
- [Rantai keamanan modul transaksi](19-transaction-security-chain.md) — lapisan izin yang jadi contoh "dua lapisan menjawab pertanyaan sama"
- [Membangun app baru](../apps/membangun-app-baru.md) — tahapan membangun app, termasuk gate dokumentasinya
