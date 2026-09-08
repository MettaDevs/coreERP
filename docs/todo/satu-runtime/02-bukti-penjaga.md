# Bukti ketiga penjaga batas bisa gagal

Pemeriksa yang belum pernah terlihat gagal tidak bisa dipercaya. Repo ini punya catatannya sendiri:
sebuah pemeriksa cakupan tabel pernah melaporkan sukses justru karena ia rusak, dan laporan sukses palsu
menghentikan pencarian lebih cepat daripada tidak ada laporan sama sekali.

Halaman ini menyimpan pesan gagal ketiga penjaga batas, apa adanya. Ia dipisahkan dari
[rencana kerja](01-prd.md) supaya dokumen rencana tidak berubah setiap kali penjaga disentuh.

**Cara memperbaruinya.** Ulangi tiga percobaan di bawah, tempelkan pesannya, lalu pastikan ketiga
pemeriksa hijau kembali. Jangan menyunting pesannya supaya rapi; pesan yang sudah dirapikan bukan lagi
bukti.

Diukur 8 September 2026 pada PostgreSQL 16 yang dipakai stack lokal.

## Penjaga pertama: migration modul membuat tabel milik modul lain

**Percobaan.** Migration `contoh-a` diubah membuat tabel `contoh_b_m_curian`.

```
Migration module "contoh-a" membuat tabel yang bukan miliknya: contoh_b_m_curian.
Awalan yang sah: "contoh_a_".
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
@@ @@
-Array &0 []
+Array &0 [
+ 0 => 'contoh_b_m_curian',
+]
```

Pesannya menyebut **nama tabelnya** dan **awalan yang sah**. Itu yang membedakannya dari pemeriksa yang
hanya bilang "ada yang salah": orang yang membacanya tahu apa yang harus diubah tanpa membuka kode
pemeriksa.

## Penjaga kedua: modul menyebut namespace modul lain

**Percobaan.** `contoh-a` menambahkan `use Modules\Apperp\ContohB\Models\Rak;`.

```
Ada module yang menyebut module lain. Module yang saling memanggil tidak bisa dicabut sendirian.
Yang boleh disebut module: kelas Core (App\), kerangka kerja, dan kelasnya sendiri.
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
@@ @@
-Array &0 []
+Array &0 [
+ 0 => 'modules/apperp/contoh-a/src/Http/Controllers/BarangController.php menyebut Apperp\ContohB',
+]
```

Penjaga ini membaca berkas, bukan menganalisa tipe. Aturan PHPStan sempat ditulis lebih dulu dan dibuang
karena berlubang: baris `use` dan pemanggilan statis tidak pernah sampai ke aturannya. Rinciannya ada
pada catatan F1-05 di [rencana kerja](01-prd.md).

## Penjaga ketiga: query modul melewati penyaringan tenant

Penjaga ini dua lapis, jadi buktinya juga dua.

**Percobaan A — query mentah.** `contoh-b` mengganti pemanggilan model dengan `DB::table(`.

```
Kode module memakai query builder mentah pada tabelnya sendiri.
Query mentah melewati global scope tenant, jadi ia tidak tersaring dan tidak ada yang memberi tahu.
Pakai model module; bila memang butuh SQL langsung, saring tenant secara eksplisit dan
daftarkan pengecualiannya di berkas test ini supaya terlihat pada diff.
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
@@ @@
-Array &0 []
+Array &0 [
+ 0 => 'modules/apperp/contoh-b/src/Http/Controllers/RakController.php memakai DB::table(',
+]
```

**Percobaan B — query tanpa tenant aktif.** Tidak perlu merusak apa pun; ini perilaku tetap yang diuji
`test_query_tanpa_tenant_aktif_dibatalkan_bukan_dibiarkan`.

```
Query module dijalankan tanpa tenant aktif. Setel coreerp.module.tenant_id lebih dulu;
menjalankannya tanpa penyaringan akan membaca data seluruh tenant.
```

Scope-nya **gagal menutup, bukan gagal membuka**. Pilihan sebaliknya berarti pekerjaan latar yang lupa
menyetel konteks membaca data semua orang tanpa satu pun tanda bahaya.

## Satu jebakan yang membuat penjaga terlihat hijau padahal tidak

PHPStan menyimpan hasil analisa, dan **mengubah berkas aturan buatan sendiri tidak membatalkan simpanan
itu**. Selama menulis penjaga kedua, aturannya sudah berjalan sejak awal, tetapi setiap perubahan
kodenya disajikan hasil lama dengan nol temuan — terbaca persis seperti "aturannya tidak jalan".

```bash
rm -rf "$TEMP/phpstan"
```

Memanggil `clear-result-cache` saja tidak cukup. Ini berlaku untuk aturan PHPStan apa pun yang ditulis
sendiri, bukan hanya penjaga batas.
