# Fiscal calendars

Kalender fiskal menentukan bagaimana sebuah entitas legal membagi waktunya untuk keperluan akuntansi. Ia bukan kalender kalender-tahun, dan bukan properti tenant.

## Pemilik kebenaran

Entitas legal memiliki konsekuensi legal, ledger, pajak, mata uang, dan **kalender fiskal**. Karena itu `legal_entities.fiscal_calendar_id` yang menunjuk kalender, bukan tenant dan bukan operating unit. Lihat [01a-tenant-and-org-hierarchy.md](01a-tenant-and-org-hierarchy.md).

Kalender itu sendiri dipakai bersama di dalam satu tenant: beberapa entitas legal dengan tahun buku yang sama boleh menunjuk kalender yang sama. Ini mengikuti bentuk Dynamics 365, di mana fiscal calendar adalah data bersama dan legal entity memilih salah satunya.

## Bentuk data

| Tabel | Isi |
| --- | --- |
| `fiscal_calendars` | Kalender milik tenant, unik per `code` |
| `fiscal_years` | Tahun fiskal dengan `starts_on` dan `ends_on`, unik per nama dalam satu kalender |
| `fiscal_periods` | Periode berurutan di dalam satu tahun fiskal, unik per `ordinal` |

Tahun fiskal tidak harus dimulai Januari. Nama tahun bebas (`FY2027`), sehingga tahun buku Juli 2026–Juni 2027 dapat disebut `FY2027` sesuai kebiasaan perusahaan.

## Invarian

Ditegakkan oleh `FiscalCalendarService`, bukan oleh schema:

1. Tahun fiskal dalam satu kalender tidak boleh bertumpang tindih.
2. Periode harus berurutan tanpa celah dan tanpa tumpang tindih.
3. Periode harus menutup seluruh rentang tahun fiskalnya.
4. Periode harus berada di dalam rentang tahun fiskalnya.

Aturan ini butuh perbandingan rentang yang tidak punya padanan constraint portabel, jadi ia hidup di service. Konsekuensinya: **setiap penulisan kalender fiskal wajib lewat `FiscalCalendarService`**, tidak boleh lewat model atau query langsung.

`monthlyPeriods()` membangun periode bulanan untuk tahun yang dimulai pada tanggal 1, yang menutup kasus paling umum.

## Resolusi

`FiscalCalendarService::resolve(LegalEntity, date)` mengembalikan tahun dan periode fiskal untuk sebuah tanggal. Ia gagal, bukan menebak, ketika:

- entitas legal belum memiliki kalender fiskal;
- tanggal berada di luar semua tahun fiskal yang terdefinisi;
- tanggal berada di luar semua periode yang terdefinisi.

Diam-diam jatuh ke kalender tahun akan menghasilkan angka akuntansi yang salah, jadi kegagalan itu disengaja.

## Pemakaian oleh Number Sequence

[Number sequence](14-number-sequences.md) memakai resolusi ini untuk `reset_period` `fiscal_year` dan `fiscal_period`, dan untuk segmen format `fiscal_year` serta `fiscal_period`.

Karena kalender dimiliki entitas legal, reset fiskal selalu memerlukan entitas legal di context. Scope `legal_entity` mendapatkannya dari scope itu sendiri. Scope `operating_unit` juga didukung, tetapi pemanggil wajib mengirim `legal_entity_id` pada tiap request — operating unit sengaja dipakai lintas entitas legal sehingga tidak bisa menyiratkan satu — dan entitas legal itu ikut menjadi bagian scope key counter. Scope `tenant` tidak bisa memakai reset fiskal.

## Administrasi

Owner atau admin tenant mengelolanya lewat **Kalender fiskal** (`/settings/fiscal-calendars`), memakai permission yang sama dengan number sequence (`manage-number-sequences`) karena kalender inilah yang menentukan periode penomoran.

| Aksi | Endpoint |
| --- | --- |
| Daftar kalender dan entitas legal | `GET /settings/fiscal-calendars` |
| Buat kalender | `POST /settings/fiscal-calendars` |
| Tambah tahun fiskal beserta periodenya | `POST /settings/fiscal-calendars/{calendar}/years` |
| Tetapkan kalender ke entitas legal | `POST /settings/fiscal-calendars/{calendar}/assign` |

Menambah tahun cukup dengan nama, tanggal mulai, dan jumlah periode; periode bulanan dibangun otomatis. Kalender yang tidak terbagi rata per bulan (misalnya 4-4-5) tetap bisa mengirim `periods` eksplisit.

Semua penulisan melewati `FiscalCalendarService`, jadi tumpang tindih dan celah ditolak di sini juga, bukan hanya di seeder.

## Yang belum ada

- Belum ada status buka/tutup periode. Menutup periode akuntansi adalah domain ledger, bukan domain kalender. Di Dynamics 365 hal ini ditangani `LedgerFiscalCalendarPeriod`, yang menyimpan status per ledger di atas kalender bersama.
- Nama tahun fiskal hanya unik per kalender, bukan per tenant. Dua kalender dalam satu tenant boleh sama-sama memiliki tahun bernama `FY2027`. Ini disengaja, dan itulah sebabnya `period_key` memakai id tahun fiskal, bukan namanya.
- Tahun fiskal belum bisa diubah atau dihapus setelah dibuat; ini disengaja selama belum ada aturan apa yang terjadi pada nomor yang sudah terbit di periode itu.

## Lihat juga

- [Number sequence](14-number-sequences.md) — konsumen utama periode fiskal
- [Tenant dan hierarki organisasi](01a-tenant-and-org-hierarchy.md) — entitas legal sebagai pemilik kalender
- [Satuan ukur](16-units-of-measure.md) — reference data platform lain
