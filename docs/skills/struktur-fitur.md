# Skill: struktur fitur

Sebelum membuat halaman atau fitur baru, buat folder fiturnya pada API dan UI. Controller dan model khusus fitur tidak boleh diletakkan pada root `Controllers` atau `Models`; page, form, dan komponen khusus fitur tidak boleh diletakkan pada root `ui/src`.

Pertahankan kode shared tetap kecil. Pindahkan ke shared hanya setelah benar-benar dipakai minimal dua fitur.

## Dropdown bertingkat

Saat nilai induk berubah, kosongkan semua nilai turunannya pada handler yang sama dan saring pilihan anak berdasarkan induk aktif. Jangan kirim nilai anak lama ke API.

Pastikan komponen pilihan benar-benar controlled ketika dikosongkan. Pada `@apperp/ui/Select`, `undefined` berarti memakai state internal; gunakan `null` untuk menampilkan pilihan kosong secara eksplisit. Perubahan group harus mereset kategori dan jenis; perubahan kategori harus mereset jenis.

## Dropdown bertingkat

Untuk pilihan yang bergantung pada induk, misalnya group → kategori → jenis:

- Saat nilai induk berubah, kosongkan seluruh nilai turunannya pada event handler yang sama.
- Saring pilihan anak berdasarkan induk aktif; jangan kirim nilai anak lama ke API.
- Pastikan komponen pilihan benar-benar menampilkan keadaan kosong. Bila komponen menyimpan nilai internal saat prop `value` menjadi `undefined`, remount komponen anak dengan `key` yang berasal dari ID induknya, atau gunakan nilai kosong yang tetap controlled.
- Terapkan urutan yang sama sampai tingkat paling bawah: perubahan group mereset kategori dan jenis; perubahan kategori mereset jenis.
