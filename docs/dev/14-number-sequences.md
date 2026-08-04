# Number sequences

Number Sequence adalah layanan Control Plane untuk menerbitkan kode bisnis yang dapat dipakai app mana pun. Ia bukan database bersama: app meminta nomor melalui API internal dan tidak pernah membaca tabel sequence Core secara langsung.

## Pemilik kebenaran

| Data | Pemilik |
| --- | --- |
| Reference kode dan scope yang didukung | Manifest app / katalog Core |
| Format, status, counter, dan audit tenant | Control Plane |
| Kalender fiskal | Entitas legal (lihat [01a](01a-tenant-and-org-hierarchy.md)) |
| Transaksi yang memakai nomor | Database app pemanggil |

Reference hanya menjadi konfigurasi `draft` bagi tenant bila app tersebut memiliki entitlement aktif **dan** placement/release-nya `ready`. Katalog atau entitlement saja tidak cukup.

## Bagaimana ini dibangun

Layanan ini terdiri dari empat lapis. Setiap lapis punya alasan mengapa ia ada.

### 1. Katalog dan konfigurasi

`app_number_sequence_references` menyimpan reference yang dideklarasikan app. `tenant_number_sequences` menyimpan pilihan tenant untuk tiap reference: profile, scope, status, mode continuous/manual, periode reset, batas nomor, dan segment format. `number_sequence_profiles` adalah preset yang disiapkan Core.

Setelah sebuah sequence memiliki counter, issue, atau reservation, pengaturan strukturalnya dikunci. Mengubah mode, scope, periode reset, nomor awal, atau format setelah nomor terpakai akan menghasilkan nomor ganda, jadi `configure()` menolaknya.

### 2. Counter, blok, dan pool

Ada tiga jalur pengambilan nomor, dipilih berdasarkan mode sequence.

| Jalur | Dipakai saat | Tabel | Sifat |
| --- | --- | --- | --- |
| Counter langsung | preallocation mati | `number_sequence_counters` | Satu row lock per penerbitan |
| Blok preallocation | non-continuous, preallocation hidup | `number_sequence_allocations` | Blok diambil sekali, nomor dibagikan dari blok |
| Pool continuous | continuous, preallocation hidup | `number_sequence_continuous_pool` | Satu row per nomor, diklaim `FOR UPDATE SKIP LOCKED` |

Semua preallocation bersifat **durable dan milik Control Plane**. Tidak ada counter atau rentang nomor yang pernah disimpan di memori instance API atau di app. Inilah yang membuat instance Core API bebas ditambah dan dikurangi.

Continuous boleh memakai preallocation karena poolnya bukan cache: tiap nomor adalah satu row yang statusnya terlacak (`available`, `reserved`, `reconciliation_pending`, `confirmed`). Nomor yang tidak jadi dipakai kembali menjadi `available`, sehingga tidak ada nomor yang hilang.

### 3. Periode reset dan kalender fiskal

`reset_period` menentukan bagaimana counter dipartisi lewat `period_key`.

| `reset_period` | `period_key` | Butuh |
| --- | --- | --- |
| `never` | `all` | — |
| `calendar_year` | `2026` | segmen `year` |
| `fiscal_year` | `FY:<fiscal_year_id>` | legal entity di context, segmen `fiscal_year` |
| `fiscal_period` | `FP:<fiscal_year_id>:<ordinal>` | legal entity di context, segmen `fiscal_year` dan `fiscal_period` |

Reset tanpa segmen pembeda ditolak. Alasannya penting: unique index dibatasi per `period_key`, jadi bila format tidak ikut berubah saat periode berganti, database **tidak** akan menangkap nomor dokumen yang berulang. Guard ini ada di `configure()`, bukan hanya di UI.

Kalender fiskal mengikuti bentuk Dynamics 365: `fiscal_calendars` dipakai bersama di dalam satu tenant, `fiscal_years` memiliki rentang tanggal, dan `fiscal_periods` menutup rentang tahun itu tanpa celah. Entitas legal menunjuk satu kalender.

Reset fiskal karena itu selalu memerlukan **entitas legal di context**:

| Scope | Dari mana entitas legalnya |
| --- | --- |
| `legal_entity` | Dari scope itu sendiri |
| `operating_unit` | **Wajib dikirim pemanggil** sebagai `legal_entity_id` pada tiap request |
| `tenant` | Tidak tersedia — reset fiskal ditolak |

Di Dynamics 365 kalender fiskal menggantung pada ledger, dan ledger hanya dimiliki legal entity — tidak pernah pada operating unit. Operating unit justru sengaja dipakai lintas legal entity, sehingga ia tidak bisa menyiratkan satu entitas legal. D365 mengambil periodenya dari company context transaksi lalu menyerahkannya ke scope factory sebagai argumen tersendiri. CoreERP tidak punya company ambient, jadi pemanggil menyebutkannya secara eksplisit.

Konsekuensinya penting: untuk scope operating unit dengan reset fiskal, **entitas legal ikut menjadi bagian scope key** (`operating_unit:<id>|legal_entity:<id>`). Satu cabang yang dipakai dua entitas legal punya dua kalender fiskal, jadi dua aliran nomor. Bila entitas legal hanya masuk ke `period_key` dan bukan ke scope key, satu scope yang dideklarasikan akan diam-diam menyimpan dua counter, dan karena unique index dibatasi per periode, database akan menerima nomor dokumen yang sama dua kali. Aturan umumnya: **apa pun yang memecah counter harus ada di scope key, bukan hanya di period key.**

Scope tenant tetap menolak reset fiskal. Ia tidak menyebut organisasi apa pun, sehingga entitas legal kiriman pemanggil akan menjadi satu-satunya penentu identitas counter.

Tanggal di luar tahun atau periode fiskal yang terdefinisi akan gagal, bukan diam-diam memakai kalender lain. Menerbitkan nomor untuk periode yang belum ada adalah kesalahan akuntansi.

### 4. Penerbitan

Non-continuous memakai `issue`. Continuous memakai `reserve` lalu `confirm` atau `cancel`.

`idempotency_key` wajib. Retry dengan key yang sama mengembalikan nomor atau reservation yang sama. Satu pengecualian penting: **reservation yang sudah `cancelled` tidak akan direplay**. Nomornya sudah kembali ke pool dan mungkin sudah dipegang transaksi lain, jadi mengembalikannya berarti satu nomor untuk dua dokumen. Pemanggil harus memakai key baru.

## Manifest app

Reference dideklarasikan pada `app.yaml`:

```yaml
number_sequences:
  references:
    - code: management-aset.entitas-aset
      name: Kode entitas aset
      default_prefix: ENTA
      allowed_scopes:
        - tenant
    - code: management-aset.perencanaan-aset
      name: Nomor perencanaan aset
      default_prefix: PLNA
      allowed_scopes: [legal_entity]
```

Aturan yang ditegakkan Control Plane saat registrasi katalog:

| Field | Aturan |
| --- | --- |
| `code` | Wajib diawali ID app, unik lintas app, pola `^[a-z0-9][a-z0-9._-]*$`, maksimal 160 karakter. Duplikat ditolak. |
| `name` | Wajib, maksimal 150 karakter |
| `default_prefix` | Wajib, **tepat empat huruf kapital** (`^[A-Z]{4}$`) |
| `allowed_scopes` | Wajib, minimal satu, hanya `tenant`, `legal_entity`, atau `operating_unit` |

Seluruh blok `number_sequences` bersifat opsional — app yang memang tidak menerbitkan nomor tidak perlu mengirimkannya. Tetapi begitu dikirim, keempat aturan di atas berlaku penuh.

Core tidak men-seed reference bisnis dari app yang belum terpasang.

`default_prefix` adalah singkatan uppercase yang disetujui pemilik domain. Saat app sudah siap untuk tenant, konfigurasi awal langsung aktif dengan rentang `0`–`19999` dan preview prefix + lima digit. Prefix tidak diturunkan otomatis dari nama karena singkatan bisnis tidak selalu sama dengan huruf awal.

## Konfigurasi owner/admin

Owner atau admin tenant mengatur tiap reference melalui **Nomor dokumen** (butuh permission `manage-number-sequences`):

- scope `tenant`, `legal_entity`, atau `operating_unit`;
- status `draft`, `active`, atau `stopped`;
- segment format: teks tetap, nomor berpadded, tahun kalender, kode organisasi, tahun fiskal, dan periode fiskal;
- periode reset;
- batas nomor dan kenaikan counter berikutnya (hanya boleh naik);
- mode continuous/manual dan preallocation.

Manual harus sesuai format dan unik. Manual tidak memajukan counter. Continuous dan manual tidak dapat digabung. Nomor yang melampaui lebar segmennya menghasilkan error, bukan nomor yang melebar diam-diam.

## Penerbitan lewat API internal

App memakai credential service miliknya. Provider membuat credential sekali dan token hanya dikembalikan pada respons pembuatan; Core hanya menyimpan hash. Request membawa header `X-CoreERP-App-Id`, `X-CoreERP-Service-Token`, dan `X-CoreERP-Tenant-Id`.

Credential dapat diikat ke satu tenant lewat `app_service_credentials.tenant_id`. Credential bertenant hanya berlaku untuk tenant itu; credential tanpa tenant mempertahankan perilaku lama agar deployment yang sudah jalan tidak putus. **Credential baru sebaiknya selalu bertenant** — tanpa itu, satu token bocor berlaku untuk semua tenant yang memasang app tersebut.

Core tetap memeriksa entitlement dan readiness app untuk tenant tersebut pada setiap request. Endpoint dibatasi rate per app+tenant (`COREERP_INTERNAL_API_RATE_LIMIT`, default 600/menit).

| Operasi | Endpoint | Kegunaan |
| --- | --- | --- |
| Issue | `POST /api/internal/v1/number-sequences/{reference}/issue` | Non-continuous atau nomor manual. |
| Reserve | `POST /api/internal/v1/number-sequences/{reference}/reserve` | Continuous sebelum transaksi app disimpan. |
| Confirm | `POST /api/internal/v1/number-sequence-reservations/{id}/confirm` | Menetapkan nomor setelah transaksi app sukses. |
| Cancel | `POST /api/internal/v1/number-sequence-reservations/{id}/cancel` | Mengembalikan reservation yang tidak dipakai. |

## Rekonsiliasi

Core dan app memiliki database terpisah, sehingga commit transaksi app dan confirm ke Core bukan satu transaksi database. Untuk continuous, app wajib menyimpan transaksi bisnis dan outbox `confirm` atau `cancel` dalam satu transaksi database app. Worker app mengirim outbox tersebut ulang sampai Core menjawab; endpoint Core idempotent.

`php artisan number-sequences:recover` mencari reservation continuous yang melewati masa tunggu lalu mengubahnya menjadi `reconciliation_pending`. Job ini **tidak** mengembalikan nomor ke pool. Reservation `reconciliation_pending` masih boleh dikonfirmasi oleh outbox terlambat, atau dibatalkan bila app membuktikan transaksi tidak pernah tersimpan. Core tidak boleh mendaur ulangnya hanya berdasarkan TTL, karena TTL habis bukan bukti transaksi gagal.

Setiap kali job berjalan ia mencatat `number-sequence.recover.completed` berisi jumlah yang ditandai dan **backlog rekonsiliasi** saat itu. Backlog yang naik terus berarti ada app yang tidak pernah menyelesaikan outbox-nya, dan nomor pool tertahan. Jadikan angka itu alert.

## Scale-out dan high availability

Scale-out dilakukan pada **Core API**, bukan dengan membuat beberapa database Core yang menerima write sendiri.

```text
Core API 1 ─┐
Core API 2 ─┼──> satu endpoint write Control Plane ──> PostgreSQL primary
Core API N ─┘                                      ├─ replica standby 1
                                                   └─ replica standby 2
```

Satu image, tiga peran, dipilih lewat `CONTAINER_ROLE`:

| `CONTAINER_ROLE` | Perintah | Replika |
| --- | --- | --- |
| `web` (default) | `apache2-foreground` | Bebas |
| `scheduler` | `php artisan schedule:work` | **Tepat satu per cluster** |
| `worker` | `php artisan queue:work` | Bebas |

Tanpa container `scheduler`, `number-sequences:recover` tidak pernah jalan dan reservation kedaluwarsa menahan nomor pool selamanya.

Aturan operasional:

1. **Scale Core API terlebih dahulu.** Load balancer boleh menambah atau mengurangi instance kapan saja karena API tidak menyimpan counter pada memori lokal.
2. **Database Core hanya memiliki satu writer aktif.** Aplikasi memakai satu endpoint write; saat failover, endpoint diarahkan ke primary baru.
3. **Replica hanya untuk baca.** Jangan arahkan `issue`, `reserve`, `confirm`, `cancel`, recovery, claim pool, atau perubahan counter ke replica. Ini belum dipaksakan oleh kode: `config/database.php` sengaja tidak memiliki split `read`/`write`. Bila kelak split itu ditambahkan, seluruh operasi sequence wajib dipin ke koneksi write.
4. **`CACHE_STORE` wajib store bersama** (`database` atau `redis`). `onOneServer` mengandalkan lock yang terlihat semua instance; dengan `file` atau `array` tiap instance mengira dirinya sendirian. Command `number-sequences:recover` memperingatkan bila mendeteksi store lokal.
5. **Jangan membuat beberapa database Core mandiri lalu menyinkronkan write-nya.** Kafka bukan replikasi database dan Kubernetes bukan penyatu database. Multi-primary dapat menerbitkan nomor yang sama dan tidak didukung.
6. **Citus bukan langkah HA awal.** Tabel Number Sequence tetap local pada primary di v1. Jika kelak di-shard, seluruh tabel sequence harus didistribusikan dan colocated menurut `tenant_id`.

Uji failover sebelum produksi: setelah primary dipromosikan, retry request dengan `idempotency_key` yang sama harus mengembalikan nomor atau reservation yang sama, bukan nomor baru.

## Pengujian

Test suite berjalan di **PostgreSQL sungguhan**, pada schema terpisah (`DB_TEST_SCHEMA`, default `coreerp_test`). Ini bukan preferensi gaya. Pada SQLite, `lockForUpdate`, `sharedLock`, dan `FOR UPDATE SKIP LOCKED` semuanya dikompilasi menjadi string kosong, sehingga suite SQLite tidak membuktikan satu pun jaminan konkurensi yang menjadi dasar desain ini. Pemindahan ke PostgreSQL langsung menemukan satu bug produksi: kolom `status` selebar 20 karakter tidak muat menampung `reconciliation_pending` (23 karakter), jadi seluruh jalur recovery gagal di produksi sementara test SQLite lulus.

Siapkan sekali:

```bash
psql -d core_erp -c "CREATE SCHEMA IF NOT EXISTS coreerp_test;"
```

`NumberSequenceConcurrencyTest` memakai dua koneksi ke database yang sama (`pgsql_test` dan `pgsql_test_secondary`) untuk meniru dua instance Core API, dengan transaksi yang di-interleave manual agar deterministik. Yang dibuktikan:

- dua instance tidak pernah mengklaim nomor pool yang sama (`SKIP LOCKED`);
- instance kedua benar-benar diblokir pada row lock counter;
- unique index idempotency menolak nomor kedua untuk satu key;
- blok preallocation tidak pernah mengulang nomor.

## Load test

Correctness diuji oleh test suite; kapasitas harus diukur. Dua command menyiapkan dan menjalankan beban di schema terpisah agar data dev dan test tidak tersentuh:

```bash
DB_TEST_SCHEMA=coreerp_load APP_ENV=testing php artisan migrate:fresh --database=pgsql_test --force
DB_TEST_SCHEMA=coreerp_load APP_ENV=testing php artisan number-sequences:load-seed --database=pgsql_test --tenants=1000
```

PHP tidak bisa menghasilkan konkurensi di dalam satu proses, jadi beban nyata datang dari menjalankan beberapa proses sekaligus, masing-masing dengan `--worker` berbeda:

```bash
DB_TEST_SCHEMA=coreerp_load APP_ENV=testing php artisan number-sequences:load-run --database=pgsql_test --worker=0 --requests=250 --out=w0.json
```

Hasil pengukuran pada 1.000 tenant, 3.000 sequence, 8 proses paralel, 2.000 penerbitan (PostgreSQL lokal, satu mesin):

| Skenario | Sebelum | Sesudah |
| --- | --- | --- |
| `issue` throughput | 567/detik | 882/detik |
| `issue` p50 | ~12 ms | 8,3 ms |
| `issue` p95 | ~19 ms | 13,6 ms |
| Halaman pengaturan (sweep per tenant) | 2.482 ms | 7,1 ms |
| Query blok alokasi (20k blok habis) | 2,46 ms, 451 buffer | 0,07 ms, 4 buffer |

Angka absolut akan berbeda per mesin; yang penting adalah bentuk kurvanya. Sebelum perbaikan, halaman pengaturan dan pencarian blok alokasi tumbuh **linier** terhadap jumlah tenant dan umur sequence. Sesudahnya keduanya konstan.

### Simulasi matriks konfigurasi

Estate yang seragam hanya membuktikan satu konfigurasi. `number-sequences:matrix-seed` menyiapkan 100 tenant, masing-masing dengan satu entitas legal, kalender fiskal yang bulan awalnya berbeda-beda, dan tiga cabang; lalu mengaktifkan **seluruh 14 konfigurasi yang sah** (lihat `App\Support\NumberSequenceMatrix`) pada setiap tenant.

```bash
DB_TEST_SCHEMA=coreerp_load APP_ENV=testing php artisan number-sequences:matrix-seed --database=pgsql_test --tenants=100 --branches=3
DB_TEST_SCHEMA=coreerp_load APP_ENV=testing php artisan number-sequences:load-run --database=pgsql_test --worker=0 --requests=100 --scenario=matrix
```

Hasil pada 40 proses paralel, 4.000 penerbitan menyilang seluruh matriks:

| Ukuran | Hasil |
| --- | --- |
| Berhasil / gagal | 4.000 / 0 |
| Throughput | 1.248 per detik |
| p50 / p95 | 24,7 ms / 60,6 ms |
| Nomor duplikat | 0 |
| Idempotency key duplikat | 0 |
| Scope continuous diperiksa | 116, **0 bercelah** |
| Reservation menggantung | 0 |

Uji HTTP terpisah pada lima instance Core API sekaligus: 1.000 permintaan dengan 1.000 koneksi serentak selesai **1.000/1.000 tanpa satu pun error**. Beban itu 200 kali lipat kapasitas server uji, jadi latensinya memang mengantre panjang — yang dibuktikan di sini adalah antre, bukan tumbang: tidak ada 5xx, tidak ada koneksi putus, tidak ada nomor ganda.

Satu temuan datang dari simulasi ini sendiri: tenant yang tahun fiskalnya belum mencakup hari ini ditolak dengan *"Tanggal ini berada di luar tahun fiskal yang sudah didefinisikan"*. Itu perilaku yang benar — layanan menolak menerbitkan nomor ke periode yang belum didefinisikan alih-alih menebak.

### Verifikasi token

Token service dulu diverifikasi dengan bcrypt: 251 ms per panggilan, dijalankan untuk setiap credential milik app. bcrypt sengaja lambat untuk melindungi password manusia yang entropinya rendah; token service adalah 256 bit acak, jadi kelambatan itu tidak membeli apa pun dan membatasi throughput ke sekitar empat permintaan per detik per worker.

Token sekarang berbentuk `<credential_id>.<secret>`: id-nya membuat pencarian O(1) dan secret-nya dibandingkan sebagai digest SHA-256 dengan `hash_equals` yang constant-time. Diukur pada lima instance, p50 turun dari 1.081 ms ke 758 ms. Credential lama tanpa id tetap berfungsi lewat jalur bcrypt sampai token-nya diterbitkan ulang.

Tiga perubahan yang menghasilkan itu:

1. Halaman pengaturan hanya menyapu tenant yang sedang dibuka (`forReadyTenant`), bukan seluruh estate. `forReadyApp` tetap ada untuk backfill saat app menjadi ready, dan itu bukan jalur request.
2. Blok alokasi yang sudah habis dihapus saat blok baru dibuat, dan disapu lagi oleh job recovery. Tanpa ini setiap `issue()` harus melewati seluruh blok mati.
3. Partial index `number_sequence_allocations_live` menutup predikat `next_number <= last_number` yang tidak bisa diwakili index biasa.

## Batas penyalahgunaan

Layanan ini mengasumsikan pemanggilnya bisa salah, termasuk salah yang merusak. Yang ditegakkan:

| Perilaku | Yang terjadi |
| --- | --- |
| Reserve terus tanpa confirm | Ditolak setelah `max_outstanding_reservations` (default 500), dengan pesan yang menyebut sebabnya |
| Spam API internal | Rate limit per app+tenant |
| `idempotency_key` raksasa atau aneh | Ditolak validasi (maks 160, charset terbatas) |
| Retry dengan key reservation yang sudah dibatalkan | Ditolak; nomornya sudah milik transaksi lain |
| Menaikkan counter ke angka yang tidak muat format | Ditolak saat `advance`, bukan saat penerbitan berikutnya |
| Mengubah format setelah nomor terpakai | Ditolak |
| Reset periode tanpa segmen pembeda | Ditolak |
| Nomor melampaui lebar segmen | Ditolak, bukan melebar diam-diam |
| Membuka halaman pengaturan berulang kali | Konstan per tenant, tidak menyentuh tenant lain |

## Aturan implementasi app

1. Nyatakan reference dan allowed scope di manifest app.
2. Minta nomor hanya melalui API internal; jangan query tabel Core.
3. Gunakan idempotency key yang **stabil** dari transaksi app. Key yang dibuat ulang tiap percobaan membatalkan seluruh manfaat idempotency dan membakar satu nomor per retry.
4. Untuk continuous, simpan transaksi bisnis dan catatan outbox confirm/cancel dalam satu transaksi database app; worker mengirimnya sampai sukses.
5. Jangan menyimpulkan reservation kedaluwarsa berarti transaksi gagal. Hanya cancel bila transaksi memang tidak tersimpan.
6. Jangan mengaktifkan atau mengubah format dari kode app; itu keputusan owner/admin tenant.
7. Set timeout eksplisit pada HTTP client ke Core.

## Lihat juga

- [Kalender fiskal](15-fiscal-calendars.md) — periode reset yang dipakai penerbitan nomor
- [Query scope dan schema](08-query-scopes-and-schema.md) — scope organisasi yang memecah counter
- [Gate penemuan dan keputusan](18-module-discovery-and-decision-gate.md) — kapan sebuah entitas memang perlu nomor
- [Standar module](02-module-standard.md) — deklarasi reference nomor pada manifest
