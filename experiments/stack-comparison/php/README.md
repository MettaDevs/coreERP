# Siapkan fixture pembanding PHP

Jalankan alat ini hanya pada project Docker `coreerp-stack-comparison` milik eksperimen. Alat menolak koneksi selain PostgreSQL `database/erp_compare` dengan pengguna `compare_admin`. Password akun dibaca dari `COMPARE_PASSWORD` yang sudah tersedia pada environment privat container.

1. Salin folder ini ke tools container, lalu jalankan setup:

   ```powershell
   docker cp experiments/stack-comparison/php coreerp-stack-comparison-tools-1:/tmp/comparison-php
   docker exec -e COMPARE_FIXTURE_ALLOW=1 coreerp-stack-comparison-tools-1 php /tmp/comparison-php/prepare.php
   ```

2. Periksa konfigurasi dan hasil operasi langsung dari database:

   ```powershell
   docker exec -e COMPARE_FIXTURE_ALLOW=1 coreerp-stack-comparison-tools-1 php /tmp/comparison-php/verify.php
   ```

3. Buktikan oracle dapat gagal tanpa mengubah data. Perintah ini harus keluar dengan status 1:

   ```powershell
   docker exec -e COMPARE_FIXTURE_ALLOW=1 -e COMPARE_ORACLE_SELFTEST=1 coreerp-stack-comparison-tools-1 php /tmp/comparison-php/verify.php
   ```

`COMPARE_TENANTS` bawaannya 2. `COMPARE_USERS_PER_TENANT` bawaannya 4, termasuk owner. Untuk 128 tenant dan 1.024 akun pembanding pada database eksperimen baru, tambahkan `-e COMPARE_TENANTS=128 -e COMPARE_USERS_PER_TENANT=8` pada perintah setup. Jumlah tenant dapat ditambah pada fixture yang sama. Jumlah akun per tenant tidak dapat diganti setelah rencana dibuat.

Konfigurasi tersimpan pada `/tmp/comparison-fixture.json`, mode 0600. Berkas berisi `accounts`, `probe_accounts`, `tenants`, dan `receipt_body` yang siap dikirim untuk setiap tenant. Berkas tidak menyimpan password, hash, cookie, atau token. Jangan hapus berkas ini selama database fixture masih dipakai. `COMPARE_FIXTURE_PATH` dapat memilih lokasi privat lain. Tanggal dan mata uang hanya dipilih saat rencana pertama dibuat melalui `COMPARE_RECEIPT_DATE` dan `COMPARE_CURRENCY_CODE`.

Setup memakai `RegisterBusiness::forOperator(..., null)` untuk membuat production/provider, lalu action organisasi, hierarchy, dan vendor produksi. Seed master module dan akun dilakukan programatis di luar waktu pengukuran. Hash bcrypt memakai biaya runtime sebenarnya dan dipakai ulang untuk pengguna sintetis. Fixture tidak mengubah konfigurasi hash.

Akun utama mempunyai izin module dan scope seluruh tenant melalui rantai role, duty, privilege, dan permission. Probe terpisah menyediakan akun tanpa izin membuat aset, akun yang hanya menjangkau departemen pembatas, dan akun application yang tidak boleh login. ID role serta duty probe disertakan untuk pengujian pencabutan.

Profil utama adalah pembelian vendor dengan posting `pending`, tiga aset, harga `333333.333`, pajak `33333.333`, atribut decimal wajib, dan penyusutan garis lurus 48 bulan. Lokasi memberikan dimensi departemen. Hierarchy manajemen yang dipublikasikan memberikan business unit. Aksi HTTP harus memakai `receipt_body` tersimpan dan versi dari respons aktual.

`verify.php` memeriksa kesiapan melalui SQL dan pratinjau layanan produksi tanpa menyimpan jurnal. Ia juga memeriksa receipt draft dan receipt selesai yang dibuat dengan profil utama ini, termasuk aset, penempatan, buku, ledger, atribut, serta jurnal. Ia tidak membuktikan autentikasi HTTP. Login dan seluruh operasi yang diukur harus melewati HTTP nyata.

Lampiran, purchase request, hibah, saldo awal, pembatalan, serta posting `held` dan `manual` membutuhkan skenario dan oracle terpisah. Jangan memasukkan hasil varian tersebut ke klaim verifikasi profil utama. Katalog source dipaku pada SHA `6b3cf5846fd4442c3e895cacf2ba58275f9dd75e`. Registrasi manifest hanya dilakukan bila katalog belum ada, karena registrasi ulang source ini memperlakukan duty custom sebagai duty manifest yang dihapus.
