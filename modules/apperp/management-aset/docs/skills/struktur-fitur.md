# Skill: struktur fitur

Sebelum membuat halaman atau fitur baru, buat folder fiturnya pada API dan UI. Controller dan model khusus fitur tidak boleh diletakkan pada root `Controllers` atau `Models`; page, form, dan komponen khusus fitur tidak boleh diletakkan pada root `ui/src`.

Pertahankan kode shared tetap kecil. Pindahkan ke shared hanya setelah benar-benar dipakai minimal dua fitur.

## Beberapa induk: sejajar dulu, bertingkat hanya bila memang bergantung

Pertanyaan pertama bukan "bagaimana membuat cascade", melainkan **apakah induknya benar-benar saling bergantung**.

Sebagian besar tidak. Master klasifikasi aset datar dan saling lepas: `model-aset` punya dua induk (pabrikan dan jenis), tetapi memilih pabrikan tidak menyaring pilihan jenis. Untuk kasus seperti ini render **N dropdown sejajar** — tanpa reset, tanpa urutan, tanpa `key` remount. Menambahkan cascade di sini justru memaksa pengguna mengisi urutan yang tidak ada aturannya, dan itulah bentuk kesalahan yang paling sering terjadi.

`MasterForm` dan `MasterPage` sudah menangani ini lewat `parents: MasterParentConfig[]` di `ui/src/master/masters.ts`; tambahkan induk di konfigurasi, jangan menulis dropdown khusus.

Cascade hanya dipakai ketika pilihan anak **memang** merupakan himpunan bagian dari induknya. Bila demikian:

- Saat nilai induk berubah, kosongkan seluruh nilai turunannya pada event handler yang sama.
- Saring pilihan anak berdasarkan induk aktif; jangan kirim nilai anak lama ke API.
- Pastikan komponen pilihan benar-benar menampilkan keadaan kosong. Pada `@apperp/ui/Select`, `undefined` berarti memakai state internal; gunakan `null` untuk pilihan kosong yang eksplisit, atau remount komponen anak dengan `key` yang berasal dari ID induknya.
- Saring di sisi server lewat `?<induk>_id=`, bukan dengan memuat seluruh daftar lalu menyaring di browser — daftar yang dimuat selalu terbatas `per_page` dan pilihan yang tersisa akan hilang diam-diam.
