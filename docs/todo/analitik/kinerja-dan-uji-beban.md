# Kinerja, cache, dan uji beban

Bagian dari [engine analitik](/todo/analitik/). Query analitik berjalan di database yang sama dengan
transaksi kasir dan gudang. Halaman ini menetapkan apa yang menahan beban itu, cara cache bekerja
tanpa menyalin data tenant ke tempat lain, dan uji beban yang membuktikan semuanya.

## Sumber beban

| Sumber | Bentuk beban | Penahan utama |
| --- | --- | --- |
| Dasbor dibuka | Beberapa query agregat bersamaan per pengguna | Cache, widget dimuat saat terlihat, batas widget per dasbor |
| Penjelajah | Query bebas yang berubah tiap klik | Batas waktu interaktif, batas dimensi, jeda pratinjau 500 ms |
| Publikasi JSON/CSV (area 15) | Query agregat dibaca sistem luar per halaman | Batas waktu luar, halaman sampai 5.000 baris, rate limit per klien |
| Job fase 3 | Peringatan dan kirim terjadwal | Batas waktu job, jadwal tersebar |

Area 10 hanya mencakup publikasi JSON/CSV yang dikirim area 15. OData (area 16) dan embed (area 17) ditunda;
keduanya belum menjadi bagian skenario atau gate area 10.

## Batas

Bawaan di `config/analytics.php` ([arsitektur](/todo/analitik/arsitektur#konfigurasi)). Tiga yang
paling menentukan:

- **`statement_timeout` per transaksi** (8 detik layar, 20 detik luar). Dipasang `SET LOCAL`, jadi
  berlaku hanya untuk query analitik itu dan tidak bocor ke koneksi persisten berikutnya.
- **Batas baris** dengan `LIMIT n + 1` dan tanda `truncated`.
- **Query bersamaan per tenant**, bawaan 4 (`limits.concurrent_per_tenant`). Diterapkan dengan kunci
  bernomor di store kunci Laravel (`analytics:slot:{tenant}:{0..3}`); tidak ada slot kosong berarti 429
  `analytics.busy` dengan `Retry-After: 2`. Nama kunci hanya memuat id tenant, bukan data. Kunci dilepas
  di `finally`, dan umurnya **dua kali** batas waktu ditambah 5 detik — satu query menjalankan dua
  pernyataan (hasil dan total) yang masing-masing dibatasi `statement_timeout` — supaya proses yang mati
  keras tidak mengurangi jatah selamanya. Hasil dari cache tidak memakai jatah. Karena store kunci
  bersama untuk semua instance, batasnya per tenant untuk seluruh instance, bukan per instance.
- **Laju per pengguna**: limiter bernama `analytics-interactive`, bawaan 120 per menit
  (`rate_limits.interactive_per_minute`), dipasang di setiap API analitik yang menghitung query; Muat
  ulang ikut terhitung. Jawaban 429-nya `analytics.rate_limited`.

Kenapa batas bersamaan perlu walau sudah ada batas waktu: server on-prem menjalankan `core-app` satu
container dengan jumlah proses PHP terbatas. Dua puluh query analitik yang masing-masing sah delapan
detik dapat menahan semua proses itu, dan layar kasir menunggu. Batas bersamaan menjaga proses tetap
tersedia untuk layar transaksi.

## Cache

### Kenapa tidak memakai cache store Laravel

Store cache bawaan repo ini `database`, dan `EnvironmentConnection::pins()` mengarahkannya ke
**database pusat**, bukan database environment tenant. Menyimpan hasil analitik di sana berarti
angka tenant ber-database sendiri tersalin ke database pusat — data yang justru dipisahkan karena
keputusan "production database sendiri" (KA-18). Redis tidak ada di server on-prem. Karena itu hasil
disimpan di tabel tenant sendiri.

### Tabel

Dikirim area 9 (4 Oktober 2026) sebagai `2026_10_04_130000_create_analytics_query_cache_and_log_tables`,
dibaca dan ditulis hanya oleh `Cache\QueryCache`. `payload` dikirim ke PostgreSQL sebagai aliran
(`PARAM_LOB`): binding teks biasa ditolak sebagai UTF-8 yang tidak sah.

```php
Schema::create('analytics_query_cache', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->ulid('tenant_id');
    $table->char('cache_key', 64);               // sha256, lihat di bawah
    $table->string('dataset_code', 160);
    $table->binary('payload');                     // JSON hasil, dikompres gzip
    $table->unsignedInteger('size_bytes');
    $table->timestamp('expires_at');
    $table->timestamps();
    // kolom jejak dan version seperti tabel tenant lain
    $table->unique(['tenant_id', 'cache_key']);
    $table->index(['tenant_id', 'expires_at']);
});
```

Klasifikasi tabelnya `CustomerContent`: isinya angka bisnis tenant. Kolom `payload` diklasifikasi
`EndUserIdentifiableInformation`, karena hasil principal yang berhak data pribadi dapat memuat nama orang.
Baris yang kedaluwarsa bukan data bisnis dan dihapus fisik oleh pembersihan cache, bukan diarsipkan —
sama halnya dengan log yang diretensi.

### Kunci

```php
$key = hash('sha256', json_encode([
    'v' => 1,                                      // naikkan bila bentuk hasil berubah
    'tenant' => $principal->tenantId(),
    'dataset' => $dataset->code,
    'definition' => $dataset->hash(),              // definisi berubah saat rilis → cache lama tidak terbaca
    'query' => $query->normalized(),
    'scope' => $principal->fingerprint($dataset),
    'timezone' => $principal->timezone(),
    'today' => $principal->now()->toDateString(),  // token relatif berubah arti setiap hari
    'row_limit' => $principal->rowLimit(),         // hasil terpotong berbeda per batas baris
], JSON_THROW_ON_ERROR));
```

`row_limit` ditambahkan area 9: dua principal berjangkauan sama tetapi berbatas baris berbeda (pengguna
dan publikasi kelak) tidak boleh berbagi hasil yang terpotong. `AnalyticsCacheIsolationTest` membuktikan
setiap komponen mengubah kunci sendirian.

`ScopeFingerprint` menghitung hash dari yang menentukan baris mana yang terlihat: hibah kebijakan
dataset (terurut), hak data pribadi, dan saringan terkunci principal. Dua pengguna dengan hibah sama
berbagi cache; pengguna dengan hibah berbeda tidak pernah.

### Perilaku

- **TTL per widget**, bawaan 300 detik, minimum 60, `0` berarti tanpa cache. Penjelajah tidak
  memakai cache untuk pratinjau, tetapi memakainya untuk hasil yang sama persis dalam satu menit
  (`POST query` memakai TTL 60). TTL adalah umur terlama yang diterima **pembaca**: hasil yang ditulis
  widget ber-TTL sepuluh menit tidak dibaca penjelajah sesudah satu menit. Pemanggilnya
  `RunQuery::handle(…, cacheTtl:, refresh:, source:)`; area 6 meneruskan TTL widget dan Muat ulang.
- **Serbuan dicegah** dengan kunci `analytics:compute:{tenant}:{cache_key}` selama perhitungan; pemanggil
  kedua membaca ulang cache setiap 200 ms lalu memakai hasil pemanggil pertama. Bila kunci lepas tanpa
  hasil (terlalu besar, atau gagal), atau batas waktu principal habis, ia menghitung sendiri.
- **Hasil besar tidak di-cache** (lebih dari `cache.max_payload_kb`).
- **Tombol Muat ulang** melewati cache untuk satu widget, dan tetap tunduk pada rate limit.
- **Pembersihan**: baris kedaluwarsa dihapus saat terbaca, dan setiap penulisan menghapus paling banyak
  100 baris kedaluwarsa lain milik tenant itu. Pembersihan terjadwal per environment menunggu perintah
  terjadwal dapat berjalan per environment ([TODO database sendiri](/todo/produksi-database-sendiri/TODO),
  butir 4.2); sampai itu, pembersihan saat baca dan tulis yang menjaga tabel tetap kecil.
- **Kesegaran ditampilkan**: setiap widget menulis "Dihitung pukul 09.12" dari `meta.generated_at`.

## Indeks di tabel module

Query analitik paling sering menyaring `tenant_id`, kebijakan data, dan rentang waktu. Pengembang
module yang menambah dataset memeriksa rencana query-nya dengan `php artisan analytics:explain`, dan
menambah indeks lewat migration module bila perlu:

- Indeks gabungan `(tenant_id, <field waktu utama>)` untuk dataset yang hampir selalu dibatasi periode.
- Indeks gabungan `(tenant_id, legal_entity_id, <kolom unit>)` untuk dataset berkebijakan.
- BRIN pada kolom waktu tabel log atau riwayat yang hanya bertambah dan sangat besar.

Indeks adalah keputusan module. Engine tidak pernah membuat indeks di tabel module.

## Ringkasan pra-agregasi (fase 3)

Ditunda (KA-16) dan diputuskan saat fase 3, dengan arah yang sudah jelas dari Analysis View BC:

- **Ringkasan per tenant**, bukan materialized view. `REFRESH MATERIALIZED VIEW` membangun ulang
  seluruh isi untuk semua tenant sekaligus.
- **Diperbarui bertahap**: bucket yang barisnya berubah sejak pembaruan terakhir dihitung ulang
  utuh, ditandai dari `updated_at` dengan marjin pengaman. Baris yang diarsipkan ikut mengubah
  `updated_at`, jadi bucket-nya ikut dihitung ulang.
- **Hanya measure yang dapat dihitung ulang** (`Aggregate::rollsUp()`). Rata-rata disimpan sebagai
  jumlah dan cacah; jumlah unik tidak diringkas.
- **Penulisan ulang otomatis**: query yang dimensinya himpunan bagian dimensi ringkasan,
  granularitasnya sama atau lebih kasar, dan saringannya hanya pada dimensi ringkasan dibaca dari
  ringkasan. Hasil menyebut `data_as_of`.
- **Kebijakan data tetap berlaku**: kolom kebijakan selalu ikut menjadi dimensi ringkasan.

Replika baca juga fase 3 dan opsional: `analytics.read_connection` menunjuk replika environment bila
ada, dan hasil menyebut `data_as_of` karena replika tertinggal
([reporting dan read replica](/dev/07-reporting-and-replicas)).

## Uji beban

Gate modul baru berlaku untuk engine ini walaupun ia bukan module: 1000+ VU serentak, 100+ tenant,
2+ instance di belakang load balancer, PostgreSQL, 90 detik pada beban penuh. Harness yang dipakai
adalah milik Core di `apps/core/loadtest/` (empat instance di belakang nginx, pooler, oracle SQL di
`verify.sql`).

### Data

`prepare.sh` diperluas: 128 tenant, masing-masing 5.000–20.000 aset (dipilih acak per tenant supaya
ukuran tidak seragam), dua legal entity, delapan unit, aset IDR dan sebagian kecil USD, tanggal
perolehan tersebar tiga tahun termasuk yang jatuh tepat di batas bulan UTC/WIB. Setiap tenant punya
tiga pengguna: hibah semua, hibah dua unit, dan tanpa hibah aset.

### Skenario

| Skenario | Bentuk | VU | Yang dibuktikan |
| --- | --- | --- | --- |
| `analytics-dashboard` | Membuka dasbor 6 widget: 3 kelompok, 1 deret bulanan, 1 tile, 1 tabel top-10; 70% dari cache | 1000, 128 tenant | Latensi dan kebenaran pada beban campur |
| `analytics-explore` | Query bebas acak dari daftar 40 bentuk sah, tanpa cache | 300 | Latensi query dingin, batas waktu, batas bersamaan |
| `analytics-mixed` | `analytics-dashboard` bersamaan dengan skenario penjenuhan module aset yang sudah ada | 700 + 300 | Layar transaksi tetap dalam gate-nya saat analitik berjalan |
| `analytics-external` | Klien integrasi membaca publikasi JSON/CSV; halaman 10 baris memastikan cursor dipakai | 200 | Cursor dan format, penolakan baca lintas tenant |

Skenario external membuktikan cursor dan bentuk CSV pada hasil agregat fixture. Hasil itu belum mencapai 5.000
baris, jadi SLO halaman maksimum di bawah tetap belum terukur; jangan menandai area 10 selesai sampai ada
fixture area 15 yang mengukurnya dengan batas tenant dan oracle tetap utuh.

Setiap respons berisi angka dicatat k6 bersama pengguna dan tenant-nya untuk dicocokkan oracle.

### Oracle SQL

Dijalankan langsung ke database setelah uji, bukan lewat API yang sedang diuji. Nilai yang dicatat
k6 dimuat ke tabel sementara `lt_analytics_observed(tenant_id, user_id, query_code, group_key, value)`.

```sql
-- 1. Tidak ada angka dari tenant lain: setiap kelompok yang diamati ada di tenant pengamatnya.
SELECT o.tenant_id, o.query_code, o.group_key
FROM lt_analytics_observed o
WHERE o.query_code = 'count_by_group'
  AND NOT EXISTS (
    SELECT 1 FROM aset_tr_aset a
    WHERE a.tenant_id = o.tenant_id AND a.group_aset_id = o.group_key AND a.deleted_at IS NULL
  );
-- Harus nol baris.

-- 2. Kebijakan data: pengguna berhibah dua unit tidak pernah melihat jumlah lebih besar dari aset kedua unit itu.
SELECT o.tenant_id, o.user_id, o.value, x.expected
FROM lt_analytics_observed o
JOIN lt_user_scope s ON s.tenant_id = o.tenant_id AND s.user_id = o.user_id
CROSS JOIN LATERAL (
  SELECT count(*) AS expected FROM aset_tr_aset a
  WHERE a.tenant_id = o.tenant_id AND a.deleted_at IS NULL
    AND a.legal_entity_id = s.legal_entity_id AND a.responsible_org_unit_id = ANY (s.unit_ids)
) x
WHERE o.query_code = 'count_total' AND o.value <> x.expected;
-- Harus nol baris. Data tidak berubah selama skenario baca, jadi harus sama persis.

-- 3. Pengguna tanpa hibah aset tidak pernah melihat angka selain nol.
SELECT * FROM lt_analytics_observed o
JOIN lt_user_scope s USING (tenant_id, user_id)
WHERE s.unit_ids = '{}' AND o.value <> 0;
-- Harus nol baris.

-- 4. Uang tidak tercampur: setiap nilai uang yang diamati membawa mata uang, dan jumlah per mata uang cocok.
```

Oracle yang tidak pernah terlihat merah tidak dipercaya
([standar penjaga](/dev/25-standar-penjaga-dan-pengujian)). Sebelum mencatat hasil, jalankan sekali
dengan compiler yang sengaja dirusak — kebijakan data dimatikan lewat setelan khusus test — dan
pastikan oracle 2 melaporkan baris.

### Gate latensi

Diukur pada concurrency tertinggi yang masih memenuhi target, bukan pada titik jenuh. Target awal di
bawah dikunci setelah putaran pertama area 10, dengan perangkat kerasnya dicatat:

| Jalur | p95 | p99 |
| --- | --- | --- |
| Widget dari cache | < 200 ms | < 500 ms |
| Widget tanpa cache, dataset 20.000 baris per tenant | < 1 s | < 2 s |
| Dasbor 6 widget terbuka penuh (sisi server) | < 2,5 s | < 4 s |
| Halaman publikasi 5.000 baris | < 2 s | < 4 s |
| Layar transaksi aset selama `analytics-mixed` | Gate baca/tulis skill arsitektur | — |

Laporan uji menyebut **sumber daya yang jenuh pertama** beserta buktinya — CPU per container, jumlah
koneksi, proses PHP sibuk — bukan hanya angka permintaan per detik.
