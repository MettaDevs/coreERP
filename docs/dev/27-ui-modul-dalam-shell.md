# UI modul di dalam shell

Halaman ini untuk developer yang menulis atau mengubah layar sebuah module. Sejak module bisnis pindah ke dalam runtime Core, layarnya bukan lagi aplikasi React tersendiri di dalam iframe: ia halaman Inertia di dalam build shell, berbagi satu salinan React, satu tema, dan satu riwayat peramban dengan Core. Yang dijelaskan di sini adalah aturan yang menjaga perpindahan itu tetap benar, beserta alasan tiap aturan ada — bukan bentuk visual layarnya, yang diatur [standar app dan addon app](02-module-standard.md) beserta pola UI bersama.

## Bentuk lama yang sudah tidak ada

Sampai 10 September 2026 dua bentuk hidup berdampingan, dan hampir semua kesalahan di area ini berasal dari menilai yang satu dengan aturan yang lain. Bentuk app berkontainer dibuang pada hari itu; tabel di bawah disimpan supaya layar lama yang ditemukan orang berikutnya dapat dikenali.

| | Module di dalam runtime Core | App berkontainer (dibuang) |
| --- | --- | --- |
| Layarnya | halaman Inertia di build shell | aplikasi Vite sendiri di dalam iframe |
| Alamat layar | rute module, `/<id module>/<id entri menu>` | `/apps/<id>?view=<id entri menu>` lalu hash di dalam bingkai |
| Salinan React | satu, milik shell | dua, satu di shell dan satu di dalam bingkai |
| Token konteks | tidak ada | ada, beserta muat ulang berkalanya |

Yang **tidak** berubah oleh pembuangan itu: menu tetap datang dari blok `ui.navigation` pada manifest lewat `App\Support\LaunchableAppCatalog`, tetap disaring permission, dan `/apps/<id>` tetap menjadi tautan peluncur produk. Rute `apps/{app}` di `apps/control-plane/routes/web.php` sekarang hanya mengalihkan ke entri menu pertama yang boleh dilihat pengguna. Satu tautan peluncur yang tetap benar lebih murah daripada peluncur yang harus tahu entri menu mana yang pertama boleh dilihat tiap pengguna.

## Nama halaman dan satu tuan rumah Inertia

Controller module memanggil `Inertia::render` dengan nama berformat `Modul::Halaman`, misalnya `management-aset::Modul`. Pemilih halaman di `apps/control-plane/resources/js/app.tsx` mengenali tanda `::`, mencari berkasnya di antara halaman module, lalu **membungkusnya** dengan tuan rumah di `apps/control-plane/resources/js/lib/halaman-module.tsx`. Jadi satu komponen itulah satu-satunya halaman Inertia yang benar-benar dirender untuk seluruh module.

Penerbit sengaja tidak ikut disebut pada nama halaman. Id module unik di seluruh runtime, jadi menuliskan penerbitnya pada setiap `Inertia::render` hanya menambah satu hal lagi yang bisa salah ketik tanpa menambah ketepatan.

Tuan rumah itu **wajib** memiliki pembatas penangguhan dan pembatas kesalahan, dan keduanya bukan hiasan:

- Komponen module dimuat malas, karena kode module yang tidak dipakai tenant ini tidak boleh ikut turun bersama shell. `React.lazy` melempar sebuah promise selama potongannya belum selesai diunduh; tanpa `Suspense` di atasnya, lemparan itu naik sampai ke akar dan seluruh layar kosong.
- Unduhan potongan bisa gagal setelah halaman terpasang — jaringan putus, atau berkas potongan lama sudah tidak ada sesudah penyebaran baru. Kegagalan sesudah pemasangan tidak bisa ditangkap `try`/`catch` di sekitar render; hanya komponen kelas dengan `componentDidCatch` yang menangkapnya.

Komponen malasnya disimpan per nama halaman di berkas yang sama. `lazy()` menghasilkan tipe komponen baru setiap kali dipanggil, dan tipe baru berarti React membongkar lalu memasang ulang pohon di bawahnya; tanpa simpanan itu setiap kunjungan ulang — termasuk muat ulang sebagian milik Inertia — membuang state layar dan menampilkan penangguhan sekali lagi.

Berkas tuan rumah itu tinggal di `lib/`, bukan di `pages/`. Pola glob halaman shell menyapu seluruh isi `pages/` sebagai titik masuk malas, sehingga selama ia di sana `app.tsx` mengimpornya statis sekaligus menyapunya dinamis, dan setiap build mencetak `INEFFECTIVE_DYNAMIC_IMPORT`. Peringatan yang selalu muncul adalah peringatan yang berhenti dibaca orang.

## Tautan menu adalah aturan tetap, bukan kolom manifest

Tautan entri menu module adalah `/<id module>/<id entri menu>`, disusun `LaunchableAppCatalog::tautanMenu()` dari manifest. Sebuah kolom manifest kedua yang berisi jalur pernah dipertimbangkan dan ditolak: kolom seperti itu akan menyimpang dari berkas rute module cepat atau lambat, dan penyimpangannya tidak terlihat sampai ada yang mengklik menunya. Dengan aturan tetap, berkas rute module adalah satu-satunya sumber kebenaran, dan `apps/control-plane/tests/Feature/Modules/HalamanModuleShellTest.php` membuktikan setiap tautan menu mendarat pada rute yang terdaftar.

## Rute layar dimiliki module, bukan Core

Module mendaftarkan rute layarnya sendiri di `modules/<penerbit>/<module>/routes/web.php`, dimuat penyedia layanan module itu. Core hanya menyusun tautan sidebar. Menaruh rute layar module di `routes/web.php` Core berarti Core harus tahu nama halaman Inertia tiap module — satu hal lagi yang bisa menyimpang tanpa ada yang gagal.

Bentuknya satu rute per module, seperti pada `modules/apperp/management-aset/routes/web.php` dan `modules/apperp/human-resources/routes/web.php`:

```php
Route::middleware(['web', 'auth', 'konteks-module:management-aset'])
    ->prefix('management-aset')
    ->name('management-aset.')
    ->group(function (): void {
        Route::get('{view}/{sisa?}', HalamanModulController::class)
            ->where('sisa', '.*')
            ->name('layar');
    });
```

`where('sisa', '.*')` bukan soal gaya. Tanpanya Laravel berhenti pada garis miring pertama, dan alamat seperti `/management-aset/pemeliharaan-aset/<id>/ubah` tidak pernah sampai ke controller. Ruas sesudah id menu inilah yang dulu ditulis sesudah tanda pagar oleh perutean hash di dalam iframe; sekarang ia alamat biasa, sehingga tombol kembali peramban, muat ulang, dan tautan yang disalin semuanya mendarat di record yang sama.

`konteks-module` — alias middleware yang didaftarkan `apps/control-plane/bootstrap/app.php` untuk `App\Http\Middleware\ResolveModuleContext` — menerima id module sebagai parameter, jadi ia dipasang di grup rute module dan bukan sebagai middleware global: izin bersifat per app, dan middleware global tidak tahu ia sedang melayani module yang mana.

## Manifest adalah satu-satunya daftar id menu

`HalamanModulController` membaca id entri menu dan permission-nya dari `app.yaml` apa adanya. Menuliskan ulang daftarnya di controller berarti dua daftar yang akan menyimpang, dan penyimpangannya muncul sebagai menu yang mendarat di 404 — atau lebih buruk, sebagai layar yang terbuka tanpa izin yang seharusnya menjaganya.

Dua keputusan pada controller itu perlu diingat karena keduanya mudah dibalik oleh orang yang tidak tahu alasannya:

- **Id yang tidak ada di manifest dijawab 404, bukan 403.** Id seperti itu bukan layar yang tidak boleh dibuka melainkan layar yang tidak pernah ada. Bedanya penting justru saat menu dan rute sedang tidak sejalan: 403 terbaca sebagai masalah hak akses dan menghabiskan waktu orang di tempat yang salah.
- **Entri tanpa `permission` pada manifest ditutup, bukan dibuka.** Ia dianggap salah tulis, dan layarnya tetap tertutup sampai manifestnya dibetulkan. Gagal ke arah yang aman, karena manifest yang lupa menulis satu baris jauh lebih sering terjadi daripada layar yang memang untuk semua orang.

Halaman module tidak memeriksa ulang keduanya di sisi React. Pemeriksaan kedua di sana hanya akan menjadi daftar ketiga yang bisa menyimpang dari manifest.

## Yang boleh disebut UI module

Kode di dalam `modules/<penerbit>/<module>/ui` hanya boleh menyebut `@apperp/ui` dan React. Batas ini yang membuat sebuah module tetap bisa dicabut ke repo lain.

Bahayanya justru karena melanggarnya berhasil: impor `@/lib/...` dari folder module akan dibangun tanpa keluhan — keduanya satu build — dan tidak ada satu pun pemeriksaan otomatis yang berubah merah saat itu terjadi. Build hijau di sini bukan bukti; batas ini dijaga saat menulis dan saat mereview, bukan oleh alat.

Karena itu jalan ke shell adalah event peramban. Layar module melempar `CustomEvent('coreerp:print')` pada `window`; komponen shell `apps/control-plane/resources/js/components/jembatan-cetak-module.tsx`, yang dipasang pada `app-layout.tsx`, menampungnya dan meneruskannya ke dialog cetak. Isi `detail` datang dari kode module dan ikut berubah tanpa perubahan di sisi shell, jadi **pemeriksa bentuk pesan** di `apps/control-plane/resources/js/lib/print-requests.ts` tetap dipakai apa adanya: ia yang menahan bentuk yang menyimpang supaya tidak sampai ke dialog cetak sebagai parameter yang setengah benar. Penanda jenis disisipkan sisi shell, bukan dituntut dari module — sebuah event bernama `coreerp:print` sudah menyebutkan jenisnya pada namanya.

## Panggilan API dari layar module

Layar module memanggil endpoint module-nya dengan sesi Core, bukan token pembawa. Bentuknya ada di `modules/apperp/management-aset/ui/api.ts`:

- Tidak ada header `Authorization`. Permintaannya same-origin, jadi cookie sesi ikut sendiri.
- Metode yang tidak dilewati pemeriksa CSRF membawa `X-XSRF-TOKEN` berisi isi cookie `XSRF-TOKEN` **apa adanya**, hanya dikembalikan dari bentuk ter-encode. `PreventRequestForgery` mendekripsi sendiri nilai header itu, karena cookie tersebut terenkripsi seperti cookie lain. `X-CSRF-TOKEN` bukan padanannya: header itu menunggu token sesi mentah, yang tidak pernah sampai ke sisi peramban.
- Izin datang sebagai properti halaman Inertia dari controller module, bukan dari sebuah endpoint konteks. Layar karena itu tidak menunggu perjalanan jaringan kedua sebelum tahu tombol mana yang boleh tampil, dan tidak ada daftar izin kedua yang bisa berbeda dari yang dipakai rutenya.

## Yang tidak dimiliki halaman module

Halaman module **tidak** punya token konteks dan **tidak** punya muat ulang berkala. Keduanya milik jalur app berkontainer, yang dibuang pada 10 September 2026 bersama halaman tuan rumah beriframe-nya: muat ulang berkala di sana ada semata-mata untuk menyegarkan token berumur pendek sebelum kedaluwarsa. Halaman module tidak punya token yang perlu disegarkan, jadi menambahkan pemuatan ulang berkala padanya hanya menambah lalu lintas tanpa satu pun masalah yang diselesaikan.

Kriteria yang berlaku adalah "tidak ada elemen `iframe` pada halaman **module**", dan `apps/control-plane/tests/Feature/Modules/LayarManagementAsetTest.php` membuktikannya pada modul produk yang sungguhan, bukan pada module contoh. Kendali positif pemeriksaan itu dulu berkas halaman iframe yang lama; setelah berkas itu dibuang, kendalinya berpindah ke penanda yang wajib ada pada berkas yang diperiksa sendiri.

## Ukuran bundel: dipecah per entri menu, satu React

Halaman module dipecah per entri menu — di `modules/apperp/management-aset/ui/App.tsx` tiap layar diimpor lewat `lazy()` — sehingga tenant yang membuka satu layar tidak ikut mengunduh kode layar lain. Ukurannya jangan disalin ke halaman ini: angka bundel berubah setiap kali layar berubah, dan angka yang disalin akan basi tanpa berbunyi. Cara mengukurnya bisa diulang siapa pun — bangun dengan halaman module, bangun sekali lagi tanpanya, lalu selisihkan seluruh isi `apps/control-plane/public/build/assets`.

React hanya boleh termuat sekali, dan itu dijaga alat, bukan ingatan. `npm run bundle:check` menjalankan `apps/control-plane/scripts/periksa-bundel.mjs` atas hasil build, dan langkah yang sama berjalan di `.github/workflows/tests.yml` tepat sesudah pembangunan aset. Dua salinan React membuat build tetap hijau dan halaman tetap tampil, lalu setiap hook melempar "Invalid hook call" hanya pada komponen yang kebetulan melintasi batas salinan — kegagalan yang muncul jauh dari sebabnya.

Cara pemeriksa itu bekerja layak diketahui sebelum ada yang menyederhanakannya:

- Ia membaca **isi** potongan, bukan namanya. Nama potongan disusun alat pembangun dan berubah kapan saja. Yang dicari adalah penanda implementasi React di dalam isinya; di antara potongan pembawa penanda itu, tepat satu yang tidak mengimpor potongan React lain — itulah salinan React yang sesungguhnya.
- Ia **gagal juga bila penandanya tidak ditemukan sama sekali**, dan bila folder hasil build kosong. Pemeriksa yang tidak menemukan apa pun tidak boleh dianggap hijau; penanda React sudah pernah berganti nama antar versi mayor, dan pemeriksa yang hanya mengenal satu bentuk akan diam pada versi berikutnya.

Satu salinan React sendiri dijaga `resolve.dedupe` pada `apps/control-plane/vite.config.ts`, bukan alias. Alias akan melewati peta `exports` paket dan menuntut jalur `dist/` ditulis tangan.

## Pemindaian Tailwind dan pengemasan `@apperp/ui`

Sumber pemindaian Tailwind untuk UI module ditulis sebagai **satu pola** di `apps/control-plane/resources/css/app.css`:

```css
@source '../../../../modules/*/*/ui';
```

Bukan satu baris per module. Daftar yang harus ditambah setiap kali module baru mendarat adalah daftar yang akan terlupa, dan lupanya tidak berbunyi: CSS tetap hijau dan hanya kelas milik layar module itu yang hilang.

`@apperp/ui` berhenti didistribusikan sebagai berkas `.tgz` yang disalin ke tiap repo. Ia kini paket di dalam repo — `packages/ui`, terdaftar sebagai workspace pada `package.json` akar dan disebut `apps/control-plane/package.json` — yang diimpor langsung. Ketidakcocokan versi antara paket yang dibangun dan paket yang disalin, kegagalan yang dulu terlihat sebagai berkas `.tgz` bernomor lama tergeletak di samping paket versi yang lebih baru, tidak bisa terjadi lagi karena hanya ada satu salinan sumbernya.

Akar repo menjadi akar workspace npm, dan itu yang membuat pencarian `node_modules` dari berkas di `modules/<penerbit>/<module>/ui` berakhir di tempat yang benar. Izin membaca folder di luar akar proyek shell diberikan `server.fs.allow` pada `vite.config.ts`; tanpa itu server pengembangan menolak menyajikan berkasnya dengan 403 sementara `npm run build` tetap berhasil — kegagalan yang hanya muncul di satu dari dua jalur adalah yang paling lama dicari.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `apps/control-plane/resources/js/app.tsx` | Pemilih halaman: mengenali nama `Modul::Halaman` dan memindai folder UI module |
| `apps/control-plane/resources/js/lib/halaman-module.tsx` | Tuan rumah module: pembatas penangguhan, pembatas kesalahan, simpanan komponen malas |
| `apps/control-plane/app/Support/LaunchableAppCatalog.php` | Menu dari manifest, penyaringan permission, aturan tautan `/<id module>/<id entri menu>` |
| `apps/control-plane/routes/web.php` | Rute `apps/{app}`: peluncur produk untuk kedua bentuk |
| `apps/control-plane/app/Http/Middleware/ResolveModuleContext.php` | Konteks module per permintaan, dipasang lewat alias `konteks-module` |
| `apps/control-plane/resources/js/components/jembatan-cetak-module.tsx` | Penampung `CustomEvent('coreerp:print')` dari layar module |
| `apps/control-plane/scripts/periksa-bundel.mjs` | Pemeriksa React tunggal, dijalankan `npm run bundle:check` |
| `apps/control-plane/vite.config.ts` | `resolve.dedupe`, alias `@modules`, `server.fs.allow` |
| `apps/control-plane/resources/css/app.css` | Sumber pemindaian Tailwind, termasuk pola satu baris untuk seluruh module |
| `modules/apperp/management-aset/routes/web.php` | Contoh rute layar module beserta alasan tiap bagiannya |
| `modules/apperp/management-aset/src/Http/Controllers/HalamanModulController.php` | Pembacaan id menu dan permission dari manifest, 404 dan 403 |
| `modules/apperp/management-aset/ui/Pages/Modul.tsx` | Titik masuk potongan UI module |
| `modules/apperp/management-aset/ui/api.ts` | Panggilan API dengan sesi dan CSRF |
| `apps/control-plane/tests/Feature/Modules/HalamanModuleShellTest.php` | Tautan menu mendarat pada rute yang terdaftar |
| `apps/control-plane/tests/Feature/Modules/LayarManagementAsetTest.php` | Layar modul produk: rute, ruas alamat, 404, 403, sidebar, tanpa `iframe` |

Perintah yang berlaku untuk berkas di folder UI module, dijalankan dari `apps/control-plane`: `npm run lint:check`, `npm run types:check`, `npm run format:check`, `npm run build`, dan `npm run bundle:check` sesudah build.

## Lihat juga

- [Standar app dan addon app](02-module-standard.md) — manifest, blok `ui.navigation`, dan kontrak navigasi
- [Identity, responsibility-based security, dan organization scope](09-identity-and-access.md) — asal permission yang menyaring menu dan menjaga layar
- [Dokumen cetak, layout, dan ekspor](23-document-rendering.md) — sisi shell dari permintaan cetak yang dikirim layar module
- [CI/CD polyrepo](22-ci-cd.md) — tempat langkah build dan pemeriksaan bundel berjalan
- [Jalur membangun modul baru](../apps/membangun-app-baru.md) — urutan mengerjakannya, dengan aturan di halaman ini sebagai isinya
