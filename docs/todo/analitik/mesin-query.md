# Mesin query

Bagian dari [engine analitik](/todo/analitik/). Halaman ini menetapkan bentuk query JSON, cara
query itu menjadi SQL, cara SQL-nya dijalankan, bentuk hasilnya, dan galatnya. Dataset yang menjadi
bahannya dijelaskan di [model semantik](/todo/analitik/model-semantik).

## Bentuk query

Satu query membaca satu dataset. Bentuknya meniru query Cube (KA-07) karena bentuk itu sudah teruji
dan langsung terpetakan ke `SELECT … GROUP BY`.

```json
{
  "dataset": "management-aset.asset-register",
  "dimensions": [
    "group_aset_id",
    { "field": "acquired_on", "granularity": "month" }
  ],
  "measures": ["count", "acquisition_value"],
  "filters": {
    "lifecycle_state": ["received", "decommissioned"],
    "nama": "@*laptop*",
    "acquisition_value": ">=5.000.000"
  },
  "time_range": { "field": "acquired_on", "range": "@last_12_months" },
  "sort": [{ "key": "acquisition_value", "direction": "desc" }],
  "limit": 10,
  "totals": true,
  "fill_gaps": true
}
```

| Kunci | Wajib | Isi | Batas bawaan |
| --- | --- | --- | --- |
| `dataset` | ya | Kode dataset | — |
| `dimensions` | tidak | Kunci field, atau `{field, granularity}` untuk field waktu | 4 |
| `measures` | ya | Kunci measure dataset; fase 2 juga kunci rumus | 12 |
| `filters` | tidak | Kunci field → ekspresi sintaks BC (teks, angka, tanggal) atau daftar nilai (pilihan, ya/tidak, rujukan) | 20 field, aturan K-30 per nilai |
| `time_range` | tidak | `field` (kolom waktu dataset; bawaan field waktu utama dataset) dan `range`: token relatif atau ekspresi tanggal | — |
| `sort` | tidak | `{key, direction}`: `key` salah satu dimensi atau measure yang **dipilih**, `direction` `asc`/`desc` (wajib ditulis) | 3 |
| `limit` | tidak | Top-N | ≤ `limits.rows_interactive` |
| `totals` | tidak | Hitung total keseluruhan | — |
| `fill_gaps` | tidak | Isi celah deret waktu; bawaan `true` bila ada dimensi waktu, dan tanpa dimensi waktu isian ini tidak berarti apa-apa | 1000 titik |
| `compare` | tidak, fase 2 | `previous_period` atau `previous_year` | — |
| `formulas` | tidak, fase 2 | Rumus, lihat [bahasa rumus](#bahasa-rumus) | 5 |

Batas jumlah di kolom kanan dibaca dari `config/analytics.php` (`limits.dimensions`, `limits.measures`,
`limits.filters`, `limits.sort`) dan dijawab 422 `analytics.limit_exceeded`. `compare` dan `formulas`
belum dibaca engine dan ditolak sebagai "belum tersedia", bukan diabaikan: query yang diam-diam
mengabaikan perbandingan atau rumus memulangkan angka yang berbeda dari yang diminta.

Skema JSON-nya ditulis area 2 di `apps/core/resources/schemas/analytics-query.schema.json` (draft 2020-12)
sebagai sumber bentuk untuk tiga tempat: pembaca query di server, tipe TypeScript layar, dan kontrak
`integrasi-analitik.yaml` (area 15). Repo ini tidak memasang pustaka validasi skema: `QueryParser`
memvalidasi dengan aturannya sendiri, dan `QueryShapeSyncTest` memastikan skema, pembaca, tipe
TypeScript (`types.ts`), dan daftar token di `query.ts` menyebut kunci, ukuran waktu, dan token yang sama.
Kunci fase 2 baru masuk skema bersama pembacanya.

### Saringan

Nilai saringan memakai bentuk yang sama dengan filter tambahan laporan (K-30), dan dijalankan oleh
`FieldFilterExpression::apply()` yang sama — bukan salinan:

| Tipe field | Bentuk nilai | Contoh |
| --- | --- | --- |
| Teks | Ekspresi | `Asus|Lenovo`, `*laptop*`, `@asus*`, `<>Rusak`, `''` |
| Angka | Ekspresi, angka gaya Indonesia | `>=1.000.000`, `100..500`, `<>0` |
| Tanggal, tanggal-jam | Ekspresi | `01/09/2026..30/09/2026`, `>=01/01/2026`, `t` |
| Pilihan, ya/tidak, rujukan | Daftar nilai | `["received"]`, `["1"]`, `["01J…", "01J…"]` |

Saringan pada field yang tidak dikenal dataset ditolak, bukan diabaikan: saringan yang diabaikan
diam-diam memulangkan angka yang lebih besar dari yang diminta pengguna.

Isian kosong berarti tanpa saringan: teks kosong, `null`, dan daftar kosong. `null` memang yang tiba
di server bila isian dikosongkan di layar, karena `ConvertEmptyStringsToNull` juga membersihkan badan
JSON. `QueryNormalizer` membuang isian kosong sebelum validasi, jadi saringan kosong pada kolom yang
salah ketik tidak ditolak — ia memang tidak menyaring apa pun, sama seperti di `FieldFilterExpression`.
`''` (dua petik tunggal) bukan isian kosong: itu ekspresi "bernilai kosong" sintaks BC.

### Rentang waktu relatif

`time_range.range` menerima ekspresi tanggal biasa atau token. Token diterjemahkan `RelativeRange`
menjadi ekspresi `Y-m-d..Y-m-d` **menurut zona waktu pengguna**, lalu dijalankan
`FieldFilterExpression` yang sudah tahu cara mengubah hari penuh di zona pengguna menjadi rentang UTC.
Nilai yang diawali `@` selalu dibaca sebagai token: yang tidak dikenal ditolak 422 di `time_range.range`
beserta daftar token yang sah, tidak dioper ke sintaks tanggal. Ekspresi tanggal biasa diperiksa
`FieldFilterExpression` saat compile (`analytics.invalid_filter`, path `time_range.range`).
`time_range.field` harus kolom waktu yang dinyatakan dataset; tanpa itu, dataset yang tidak punya
field waktu utama menolak rentang waktu dengan meminta kolomnya disebut.

| Token | Arti, untuk "sekarang" = Kamis 15 Oktober 2026 |
| --- | --- |
| `@today` / `@yesterday` | 15 Okt / 14 Okt |
| `@this_week` / `@last_week` | 12–18 Okt / 5–11 Okt (minggu mulai Senin) |
| `@this_month` / `@last_month` | 1–31 Okt / 1–30 Sep |
| `@this_quarter` / `@last_quarter` | 1 Okt–31 Des / 1 Jul–30 Sep |
| `@this_year` / `@last_year` | 1 Jan–31 Des 2026 / 2025 |
| `@last_7_days` / `@last_30_days` / `@last_90_days` | Termasuk hari ini |
| `@last_12_months` | 1 Nov 2025–31 Okt 2026, bulan penuh |
| `@year_to_date` / `@month_to_date` | 1 Jan–15 Okt / 1–15 Okt |

`RelativeRange` terpisah dari `RelativeDates` milik preset laporan K-25. Token preset tersimpan di
preset tenant dan menjadi tanggal tunggal; token analitik menjadi rentang. Menggabungkan keduanya
mengubah arti token yang sudah tersimpan. Tahun fiskal (`@this_fiscal_year`) menyusul di fase 2
lewat `FiscalCalendarDirectory`, karena butuh legal entity.

`RelativeRange` berisi fungsi statis, seperti `RelativeDates` dan `FieldFilterExpression`, dan tidak
membaca jam sendiri: pemanggil memberinya `AnalyticsPrincipal::now()` yang sudah berzona.
`RelativeRange::expression($range, $now)` memulangkan ekspresi untuk `FieldFilterExpression` (ekspresi
tanggal biasa dikembalikan apa adanya), dan `RelativeRange::bounds($token, $now)` memulangkan hari
pertama dan terakhirnya, yang dibutuhkan `GapFiller` (area 3) untuk tahu titik waktu mana yang harus
ada. Daftar tokennya `RelativeRange::TOKENS`, yang sama dengan daftar di `resources/js/lib/analytics/query.ts`
dan dijaga `QueryShapeSyncTest`. Zona yang diuji: `Asia/Jakarta`, `Asia/Makassar`, `Asia/Jayapura`, di
pergantian tahun, hari kabisat, dan pergantian minggu (`RelativeRangeTest`).

## Dari JSON ke objek

```php
<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Query analitik yang sudah dibaca dan dinormalkan. Tidak berubah setelah dibuat: kunci cache dihitung
 * dari bentuk normalnya, jadi dua JSON yang berbeda urutan kuncinya menjadi satu entri cache.
 */
final readonly class AnalyticsQuery
{
    /**
     * @param  list<Dimension>  $dimensions
     * @param  list<string>  $measures
     * @param  array<string, string|list<string>>  $filters
     * @param  list<array{key: string, direction: 'asc'|'desc'}>  $sort
     */
    public function __construct(
        public string $dataset,
        public array $dimensions,
        public array $measures,
        public array $filters,
        public ?TimeRange $timeRange,
        public array $sort,
        public ?int $limit,
        public bool $totals,
        public bool $fillGaps,
    ) {}

    /** Bentuk normal untuk kunci cache dan log: kunci urut, nilai daftar urut, tanpa nilai bawaan. */
    public function normalized(): array
    {
        $filters = $this->filters;
        ksort($filters);
        foreach ($filters as &$value) {
            if (is_array($value)) {
                sort($value);
            }
        }

        return [
            'dataset' => $this->dataset,
            'dimensions' => array_map(fn (Dimension $d): array => $d->toArray(), $this->dimensions),
            'measures' => $this->measures,
            'filters' => $filters,
            'time_range' => $this->timeRange?->toArray(),
            'sort' => $this->sort,
            'limit' => $this->limit,
            'totals' => $this->totals,
            'fill_gaps' => $this->fillGaps,
        ];
    }
}
```

Tiga langkah, masing-masing satu kelas, dan urutannya tetap:

1. **`QueryParser`** membaca JSON menjadi objek ini apa adanya dan menolak bentuk yang salah dengan galat
   berpath (`dimensions.1.granularity`, `sort.0.direction`, `time_range.range`): tipe nilai, kunci yang
   dikenal di setiap tingkat, panjang. Tidak ada yang diperiksa terhadap dataset di sini.
2. **`QueryNormalizer`** menyatukan query yang setara: urutan kunci saringan, pilihan yang diurutkan dan
   tidak berulang, spasi di ujung isian, saringan kosong dibuang, dan `fill_gaps` yang
   dinyalakan tanpa dimensi waktu dimatikan, karena celah hanya ada di deret waktu. Urutan `dimensions`, `measures`, dan `sort` **tidak** diubah,
   karena ia menentukan urutan kolom dan baris hasil. `RunQuery` memanggilnya, jadi setiap jalur masuk
   yang membangun `AnalyticsQuery` sendiri — bukan hanya yang lewat `QueryParser` — menghasilkan
   `meta.query_hash` dan kunci cache yang sama untuk query yang sama.
3. **`QueryValidator`** memeriksa query terhadap `CompiledDataset` dan principal, sebelum ada SQL:
   batas jumlah (`limits.*`), setiap kunci dikenal dan tidak dipilih dua kali (dimensi dan measure
   berbagi satu ruang kunci, karena hasilnya memakai kunci itu sebagai nama kolom), ember waktu hanya pada
   field waktu dataset, `time_range` memakai field waktu dan token yang dikenal, `sort` hanya pada kunci
   yang dipilih dan tidak berulang, `limit` dalam batas principal, lalu gerbang data pribadi.

Gerbang data pribadi (area 4) dipanggil lewat antarmuka kecil `Query\FieldUseGate`: terakhir, sesudah
semua kunci terbukti dikenal, validator melaporkan setiap kolom dataset yang dipakai query sebagai peta
path → kunci (`dimensions.0`, `filters.nama`, `time_range.field`), ditambah field yang dibaca measure
terpilih (`measures.1` untuk kolom bahannya bila ia field dataset, `measures.1.where.status` untuk saringan
tetapnya). Urutan tidak dilaporkan terpisah karena `sort` hanya dapat memakai kunci yang sudah dipilih.
Implementasinya `Security\PersonalDataGate` (area 4), diikat lewat atribut `#[Bind]` pada antarmukanya;
parameter validator tidak opsional.

## Dari objek ke SQL

Urutan di bawah bukan selera; setiap langkah bergantung pada langkah sebelumnya.

1. **Query dasar dari dataset.** `Model::query()` untuk dataset bermodel — `TenantScope` dan
   `SoftDeletes` ikut dari model — atau `fromSub(sumber, 'base')` ditambah `where base.tenant_id = ?`
   untuk dataset bersumber query. Tabel dasar tidak pernah diberi alias.
2. **Join yang dibutuhkan saja.** `JoinPlanner` mengumpulkan alias yang disebut dimensi, measure,
   saringan, dan kolom kebijakan, lalu memasang join itu saja, beserta join yang menjadi jalannya. Setiap
   join membawa `alias.tenant_id = <tabel dasar>.tenant_id`, dan `alias.deleted_at IS NULL` kecuali join
   label. Semuanya `LEFT JOIN` dengan syarat di `ON`: join hanya dipasang bila kolomnya disebut, jadi join
   yang membuang baris membuat jumlah baris berubah hanya karena pengguna menambah satu pengelompok. Baris
   yang induknya terarsip atau (karena data rusak) milik tenant lain tetap dihitung dengan kolom join
   kosong, dan kebijakan data pada kolom join tetap gagal tertutup — kolom kosong tidak cocok dengan hibah
   apa pun. Ini juga yang dilakukan `whereHas()` ber-`SoftDeletes` di layar module untuk pengguna berhibah.
3. **Kebijakan data dan saringan terkunci**, sebelum saringan pengguna, lewat `DataPolicyScope`:
   `DataPolicyFilter::apply()` pada kolom yang dinyatakan, lalu saringan terkunci principal (kosong atau
   field tak dikenal = nol baris). Saringan pengguna hanya dapat menyempitkan, tidak pernah melebarkan.
4. **Saringan pengguna dan rentang waktu** lewat `FieldFilterExpression::apply()`.
5. **Dimensi** dengan alias posisi `d0`, `d1`, …, dan pengelompokan menurut alias itu.
6. **Dimensi tersirat**: kolom mata uang dan satuan dari measure uang dan kuantitas (KA-22).
7. **Measure** dengan alias `m0`, `m1`, ….
8. **Urutan dan batas**: `ORDER BY` pada alias, `LIMIT n + 1`.
9. **Total**: query kedua dengan langkah 1–4 dan 6 yang sama, tanpa dimensi lain dan tanpa batas baris —
   total seluruh kelompok, bukan hanya yang lolos top-N. Kolom mata uang dan satuan memakai alias yang
   sama dengan di hasil, juga bila mata uang dipilih sebagai dimensi, supaya total tetap satu baris per mata
   uang dan berkunci sama.

```php
<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DataPolicyScope;
use App\Platform\Modules\Contracts\FieldFilterExpression;

/**
 * Menyusun query builder Laravel dari query analitik. Tidak menjalankan apa pun; eksekusinya milik
 * {@see QueryExecutor}, supaya `analytics:explain` dan test dapat memeriksa SQL tanpa membaca data.
 */
final class QueryCompiler
{
    public function __construct(
        private readonly JoinPlanner $joins,
        private readonly DataPolicyScope $policy,
        private readonly MeasureSql $measures,
        private readonly TimeBucketSql $time,
    ) {}

    public function compile(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): CompiledQuery
    {
        $builder = $dataset->baseQuery();
        $grammar = $builder->getQuery()->getGrammar();

        $this->joins->apply($builder, $dataset, $query);
        $this->policy->apply($builder, $dataset, $principal);

        foreach ($query->filters as $key => $value) {
            FieldFilterExpression::apply($builder, $dataset->filterField($key), $value, $principal->timezone());
        }
        if ($query->timeRange !== null) {
            $field = $dataset->filterField($query->timeRange->field ?? $dataset->defaultTime());
            FieldFilterExpression::apply($builder, $field, RelativeRange::expression($query->timeRange->range, $principal->now()), $principal->timezone());
        }

        $totals = $query->totals ? clone $builder : null;
        $columns = [];

        foreach ($query->dimensions as $i => $dimension) {
            $alias = "d{$i}";
            $sql = $dimension->granularity === null
                ? $grammar->wrap($dataset->qualified($dimension->field))
                : $this->time->bucket($dataset, $dimension->field, $dimension->granularity, $principal->timezone());
            // Kelompokkan menurut alias, bukan menurut ekspresi: ekspresi yang membawa parameter muncul dua
            // kali sebagai `$1` dan `$5`, dan PostgreSQL tidak tahu keduanya sama.
            $builder->selectRaw("{$sql} as {$alias}")->groupBy($alias);
            $columns[] = ResultColumn::dimension($alias, $dimension, $dataset);
            foreach ($dataset->labelColumnsFor($dimension->field) as $labelAlias => $labelSql) {
                $builder->selectRaw("{$labelSql} as {$alias}_{$labelAlias}")->groupBy("{$alias}_{$labelAlias}");
            }
        }

        foreach ($this->implicitDimensions($dataset, $query) as $j => $field) {
            $alias = "c{$j}";
            $builder->selectRaw($grammar->wrap($dataset->qualified($field))." as {$alias}")->groupBy($alias);
            $totals?->selectRaw($grammar->wrap($dataset->qualified($field))." as {$alias}")->groupBy($alias);
            $columns[] = ResultColumn::implicit($alias, $field, $dataset);
        }

        foreach ($query->measures as $i => $key) {
            [$sql, $bindings] = $this->measures->sql($dataset, $dataset->measure($key));
            $builder->selectRaw("{$sql} as m{$i}", $bindings);
            $totals?->selectRaw("{$sql} as m{$i}", $bindings);
            $columns[] = ResultColumn::measure("m{$i}", $key, $dataset);
        }

        foreach ($this->orderOf($query, $columns) as [$alias, $direction]) {
            $builder->orderByRaw("{$alias} {$direction} nulls last");
        }
        $limit = $query->limit ?? $principal->rowLimit();
        $builder->limit($limit + 1);

        return new CompiledQuery($builder, $totals, $columns, $limit);
    }
}
```

`$dataset->qualified()` hanya memulangkan nama yang sudah lolos `DatasetValidator` (huruf kecil,
angka, garis bawah), dan `wrap()` menambahkan tanda kutip identifier. Tidak satu pun nilai dari
pemanggil masuk ke SQL selain lewat binding.

**Sketsa di atas tidak lolos analisa tipe, dan kode area 0 tidak menulisnya begitu.** Laravel 12
menandai `selectRaw()`, `orderByRaw()`, `groupByRaw()`, dan `whereRaw()` dengan `literal-string`, dan
Larastan menolak string yang memuat nama kolom dari definisi dataset — termasuk alias `"d{$i}"`, karena
bilangan yang disisipkan membuat string tidak lagi literal. Repo ini tidak memakai `@phpstan-ignore`
maupun baris baseline baru. Yang dipakai `QueryCompiler`:

| Kebutuhan | Cara tanpa SQL mentah |
| --- | --- |
| Kolom dimensi | `addSelect('<tabel>.<kolom> as d0')`, lalu `groupBy('d0')` — grammar membungkus keduanya |
| Label rujukan | `addSelect('r0.nama as d0_label')`, lalu `groupBy('d0_label')` |
| Join | `leftJoin('<tabel> as <alias>', fn (JoinClause $j) => $j->on(…)->on('<alias>.tenant_id', '=', '<tabel dasar>.tenant_id')->whereNull('<alias>.deleted_at'))` |
| Ember waktu | `selectExpression(new TimeBucketExpression(…), 'd0')`, lalu `groupBy('d0')` |
| Measure | `selectExpression(MeasureExpression::for($dataset, $measure), 'm0')`; ekspresinya objek `Illuminate\Contracts\Database\Query\Expression` yang menyusun SQL lewat grammar (`getValue(Grammar)`) |
| Saringan tetap measure | Ekspresi yang sama dengan `FILTER (WHERE …)` berplaceholder; nilainya lewat `addBinding($expression->bindings(), 'select')` |
| Urutan | `orderBy('m0', 'desc')`, `orderBy('d0')` |
| `NULLS LAST` | `orderBy(new IsNullExpression($ekspresi))` tepat sebelum `orderBy('m0', 'desc')`: kunci `(<ekspresi>) is null` naik menaruh yang kosong di akhir. `orderBy()` tidak menerima `nulls last`, dan alias tidak dapat dipakai di dalam ekspresi `ORDER BY`, jadi kuncinya mengulang ekspresi kolomnya — kolom untuk dimensi, agregat (dengan binding `order`) untuk measure. Hanya pada urutan turun, dan hanya untuk kolom yang dapat kosong: dimensi, dan measure `avg`, `min`, `max` |

Kunci query yang sudah dibaca parser semuanya dikompilasi: `time_range` lewat `RelativeRange::expression()`
lalu `FieldFilterExpression::apply()`, dengan `InvalidFilterExpression` menjadi `analytics.invalid_filter`
berpath `time_range.range`; `sort` pengguna menggantikan urutan bawaan, dan pengelompok yang belum ikut
diurutkan tetap menjadi pemutus seri; ember waktu dan `totals` sejak area 3. Urutan bawaan: setiap dimensi
berember waktu naik, selain itu measure pertama turun.

### Measure

`MeasureExpression::getValue(Grammar)` menyusun SQL-nya; `bindings()` memulangkan nilai saringan tetap
dalam urutan placeholder:

| Agregat | Tanpa saringan tetap | Dengan saringan tetap |
| --- | --- | --- |
| `count` | `count(*)` atau `count("t"."x")` | `count(*) filter (where "t"."status" in (?))` |
| `count_distinct` | `count(distinct "t"."x")` | `count(distinct "t"."x") filter (where …)` |
| `sum` | `coalesce(sum("t"."x"), 0)` | `coalesce(sum("t"."x") filter (where …), 0)` |
| `avg`, `min`, `max` | `avg("t"."x")` | `avg("t"."x") filter (where …)` |

`FILTER` melekat pada panggilan agregatnya, jadi untuk `sum` ia ditulis **di dalam** `coalesce`;
`coalesce(sum(x), 0) filter (…)` ditolak PostgreSQL. Saringan tetap hanya kesamaan dan daftar nilai yang
sudah divalidasi saat dataset didaftarkan; nilai `null` di daftar menjadi `… is null`.

Dua hal yang disengaja:

- `sum` dibungkus `coalesce(…, 0)`: kelompok tanpa baris yang memenuhi saringan measure bernilai nol,
  bukan kosong. `avg`, `min`, dan `max` tetap boleh kosong, karena rata-rata dari nol baris bukan nol.
- Measure uang **tidak pernah** dijumlah tanpa kolom mata uangnya ikut dikelompokkan (langkah 6).
  Kalau pengguna tidak memilih mata uang sebagai dimensi, compiler menambahkannya sebagai dimensi
  tersirat; layar menampilkan satu nilai per mata uang ("Rp 1,2 M · USD 12.000"), tidak satu jumlah
  campuran.

### Waktu dan zona

Kolom waktu di repo ini ada tiga jenis, dan masing-masing butuh SQL berbeda. Zona aplikasi
(`config('app.timezone')`) adalah UTC, dan `$table->timestamps()` membuat `timestamp` **tanpa** zona
yang berisi waktu UTC.

| Tipe kolom | Ekspresi bucket bulan untuk pengguna `Asia/Makassar` |
| --- | --- |
| `date` | `date_trunc('month', "t"."acquired_on"::timestamp)::date` |
| `timestamp` (tanpa zona, berisi UTC) | `date_trunc('month', ("t"."created_at" at time zone 'UTC') at time zone 'Asia/Makassar')::date` |
| `timestamptz` | `date_trunc('month', "t"."posted_at" at time zone 'Asia/Makassar')::date` |

- **Kolom `date` tidak dikonversi.** Tanggal perolehan adalah tanggal kalender, bukan saat. Cast ke
  `timestamp` (tanpa zona) memilih varian `date_trunc` yang tidak membaca zona sesi; tanpa cast,
  PostgreSQL memilih `timestamptz` dan hasilnya bergantung pada zona sesi yang kebetulan UTC.
- **Zona ditulis sebagai literal, bukan binding**, setelah dicocokkan dengan
  `DateTimeZone::listIdentifiers()` (`TimeBucketExpression` menolak nama lain). Ekspresi yang sama
  muncul dua kali — kolom hasil dan kunci kosong-di-akhir — dan dua binding menjadi dua parameter berbeda
  bagi PostgreSQL, yang lalu tidak mengenalinya sebagai ekspresi yang dikelompokkan.
- **Jangan memakai `to_char()` pada `timestamptz`.** Ia memakai zona sesi (UTC): awal Oktober di
  Makassar adalah 30 September pukul 16.00 UTC, dan `to_char` menulisnya sebagai September.
- **Minggu mulai Senin** (`date_trunc('week', …)` di PostgreSQL memang ISO).
- **Celah deret waktu diisi di PHP** (`GapFiller`) dari rentang yang diminta: bulan tanpa transaksi
  tetap muncul dengan nol untuk `count`/`sum` dan kosong untuk `avg`/`min`/`max`, untuk setiap kombinasi
  dimensi lain (termasuk mata uang tersirat) yang muncul di hasil. Batasnya 1000 titik.
  - Rentangnya token `time_range` (`@this_year`) bila field-nya field ember itu, sehingga bulan kosong di
    ujung rentang ikut muncul; selain itu — ekspresi tanggal biasa, rentang pada field lain, atau tanpa
    rentang — dari periode pertama sampai terakhir di hasil.
  - Ember waktu pertama yang diisi; dimensi berember lain diperlakukan seperti dimensi biasa.
  - Tidak diisi bila hasil terpotong (periode yang hilang mungkin terpotong, bukan kosong), bila urutan
    pertama bukan periode itu (pengguna meminta urutan lain, misalnya nilai terbesar), bila lebih dari
    1000 titik, atau bila baris sesudah diisi melebihi batas baris query.
  - Baris disusun per periode. Di dalam satu periode, baris yang ada tetap dalam urutan SQL-nya dan baris
    isian menyusul — tepat untuk urutan turun menurut measure, mendekati untuk urutan naik menurut dimensi
    lain.

Test yang wajib: transaksi pada 30 September 2026 pukul 16.30 UTC masuk bucket **Oktober** bagi
pengguna WITA dan bucket September bagi pengguna UTC.

### Label

| Jenis field | Sumber label | Waktu |
| --- | --- | --- |
| Pilihan (`FIELD_OPTIONS`) | Peta nilai → label dari dataset | Setelah query, di PHP |
| Rujukan module (`reference()`) | Join label ke tabel master yang sama module, termasuk baris terarsip | Di query, ikut dikelompokkan |
| Dimensi bersama (`shared()`) | Resolver dimensi bersama di Core, sekali per himpunan id | Setelah query |
| Ya/tidak | "Ya" / "Tidak" | Setelah query |

Label dikirim di kolom pendamping `<kunci>__label`, tepat sesudah nilainya. Nilai mentah tetap dikirim,
karena drill dan slicer butuh id, bukan nama. Label yang tidak dikenal — id yang tidak ada di tenant ini,
atau nama orang yang ditahan — bernilai `null`, dan layar menampilkan nilai mentahnya; pilihan yang tidak
dikenal memakai nilai mentahnya sebagai label. Periode dan kolom tersirat (mata uang dan satuan yang tidak
dipilih) tidak berlabel. Label nama orang (`EndUserIdentifiableInformation`) hanya bagi principal yang
berhak membaca data pribadi: `LabelResolver` meneruskan `$principal->mayUsePersonalData()` ke
`SharedDimensionRegistry::labels()`.

## Eksekusi baca-saja

```php
<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Illuminate\Database\QueryException;

final class QueryExecutor
{
    /**
     * @return array{rows: list<object>, totals: list<object>}
     */
    public function run(CompiledQuery $compiled, int $timeoutMs): array
    {
        $connection = $compiled->builder->getConnection();
        $connection->beginTransaction();

        try {
            // Baca-saja di level database: compiler yang salah pun tidak dapat menulis.
            $connection->statement('set transaction read only');
            // SET tidak menerima binding; nilainya integer dari config, bukan dari pemanggil.
            $connection->statement(sprintf('set local statement_timeout = %d', $timeoutMs));

            return [
                'rows' => $compiled->builder->toBase()->get()->all(),
                'totals' => $compiled->totals?->toBase()->get()->all() ?? [],
            ];
        } catch (QueryException $e) {
            // Galat yang bermakna bagi pengguna menjadi 422; cacat engine (menulis, SQL tidak sah) dilempar
            // apa adanya supaya menjadi 500 yang dilaporkan.
            throw AnalyticsQueryException::fromDatabase($e) ?? $e;
        } finally {
            // ROLLBACK, bukan COMMIT. Query baca tidak butuh commit, dan bila engine dipanggil di dalam
            // transaksi lain (test, job), rollback ke savepoint juga membatalkan SET LOCAL dan READ ONLY
            // di atas. Commit ke savepoint membiarkan keduanya berlaku sampai transaksi luar selesai, dan
            // INSERT berikutnya di transaksi itu gagal dengan "cannot execute INSERT in a read-only transaction".
            $connection->rollBack();
        }
    }
}
```

| Kode SQLSTATE | Arti | Jawaban |
| --- | --- | --- |
| `57014` | `statement_timeout` tercapai | 422 `analytics.query_timeout` |
| `25006` | Percobaan menulis di transaksi baca-saja | 500 dan laporan galat: cacat compiler, bukan kesalahan pengguna |
| `22P02`, `22007`, `22008` | Nilai tidak dapat dibaca sebagai angka atau tanggal | 422 `analytics.invalid_filter` |
| `42xxx` | SQL tidak sah | 500 dan laporan galat; cacat compiler atau dataset |

`InvalidFilterExpression` dari `FieldFilterExpression` diterjemahkan ke 422 `analytics.invalid_filter`
dengan path field-nya, sebelum query sampai ke database.

`QueryExecutor::explain()` membaca rencana `EXPLAIN (FORMAT TEXT)` — tanpa `ANALYZE`, jadi query-nya tidak
dijalankan — untuk query hasil dan query total, di transaksi baca-saja yang sama. Pemakainya
`analytics:explain`.

Test yang wajib untuk eksekutor:

- Dipanggil di dalam transaksi test, lalu `INSERT` di transaksi yang sama **berhasil** — bukti
  rollback ke savepoint membatalkan baca-saja dan batas waktu.
- Query yang sengaja lambat (`pg_sleep`) pada dataset fixture berhenti di batas waktu dan menjadi 422.
- Compiler yang dipaksa menulis (fixture test) gagal dengan `25006` di database, bukan lolos.

## Bentuk hasil

```json
{
  "columns": [
    { "key": "group_aset_id", "kind": "dimension", "caption": "Group aset", "type": "reference", "label_key": "group_aset_id__label" },
    { "key": "acquired_on", "kind": "dimension", "caption": "Tanggal perolehan", "type": "period", "granularity": "month" },
    { "key": "currency_code", "kind": "dimension", "caption": "Mata uang", "type": "text", "implicit": true },
    { "key": "count", "kind": "measure", "caption": "Jumlah aset", "type": "number", "format": "number" },
    { "key": "acquisition_value", "kind": "measure", "caption": "Nilai perolehan", "type": "number", "format": "money", "currency_key": "currency_code" }
  ],
  "rows": [
    { "group_aset_id": "01J9Z…", "group_aset_id__label": "Kendaraan", "acquired_on": "2026-09-01", "currency_code": "IDR", "count": 4, "acquisition_value": "1250000000.00" }
  ],
  "totals": [
    { "currency_code": "IDR", "count": 140, "acquisition_value": "9870000000.00" }
  ],
  "meta": {
    "dataset": "management-aset.asset-register",
    "dataset_version": 1,
    "generated_at": "2026-10-15T09:12:03+08:00",
    "timezone": "Asia/Makassar",
    "truncated": false,
    "row_limit": 5000,
    "cached": true,
    "duration_ms": 41,
    "query_hash": "sha256:…"
  }
}
```

- Alias SQL (`d0`, `m1`, `c0`) dipetakan kembali ke kunci dataset sebelum dikirim; alias tidak
  pernah keluar dari server.
- **Uang dan desimal dikirim sebagai string.** `numeric` PostgreSQL lebih presisi daripada float
  JavaScript; layar memformatnya, bukan menghitungnya.
- `totals` berupa daftar, satu baris per mata uang dan satuan, atas seluruh kelompok (bukan hanya yang
  lolos `limit`); kosong bila tidak diminta.
- Periode dikirim sebagai tanggal awal bucket (`2026-09-01`) beserta `granularity`; layar yang
  menulis "Sep 2026".

Tipe TypeScript-nya ditulis sekali di `resources/js/lib/analytics/types.ts`:

```ts
export type TimeGranularity = 'day' | 'week' | 'month' | 'quarter' | 'year';
export type MeasureFormat = 'number' | 'money' | 'percent' | 'quantity' | 'hours';

export type AnalyticsQuery = {
    dataset: string;
    dimensions?: Array<string | { field: string; granularity?: TimeGranularity }>;
    measures: string[];
    filters?: Record<string, string | string[]>;
    time_range?: { field?: string; range: string };
    sort?: Array<{ key: string; direction: 'asc' | 'desc' }>;
    limit?: number;
    totals?: boolean;
    fill_gaps?: boolean;
};

export type ResultColumn = {
    key: string;
    kind: 'dimension' | 'measure';
    caption: string;
    type: 'text' | 'number' | 'date' | 'datetime' | 'boolean' | 'option' | 'reference' | 'period';
    format?: MeasureFormat;
    granularity?: TimeGranularity;
    label_key?: string;
    currency_key?: string;
    unit_key?: string;
    implicit?: boolean;
};

export type ResultValue = string | number | boolean | null;

export type ResultSet = {
    columns: ResultColumn[];
    rows: Array<Record<string, ResultValue>>;
    totals: Array<Record<string, ResultValue>>;
    meta: {
        dataset: string;
        dataset_version: number;
        generated_at: string;
        timezone: string;
        truncated: boolean;
        row_limit: number;
        cached: boolean;
        duration_ms: number;
        query_hash: string;
    };
};
```

## Galat

Bentuknya `{"error": {"code": "...", "message": "...", "field": "..."}}`, bentuk yang sudah dibaca
`CoreApiError` di `resources/js/lib/core-api.ts`. Pesannya bahasa sehari-hari yang menyebut apa yang
dapat dilakukan pengguna.

| Kode | HTTP | Pesan untuk pengguna |
| --- | --- | --- |
| `analytics.invalid_query` | 422 | Bentuk query salah, dengan path bagian yang salah (`dimensions.1`); juga kunci yang belum dibaca engine. Contoh: Pilih sedikitnya satu nilai yang dihitung. |
| `analytics.dataset_unknown` | 404 | Data ini tidak tersedia. Aplikasinya mungkin belum terpasang. |
| `analytics.dataset_forbidden` | 403 | Anda tidak punya akses ke data ini. |
| `analytics.field_unknown` | 422 | Kolom "…" tidak dikenal. Pilih kolom dari daftar. |
| `analytics.field_personal_data` | 403 | Kolom "…" memuat data pribadi dan tidak dapat dipakai di analitik dengan hak Anda. |
| `analytics.invalid_filter` | 422 | Saringan "…" tidak dapat dibaca. Contoh yang sah: … |
| `analytics.limit_exceeded` | 422 | Terlalu banyak kolom pengelompokan. Maksimal 4. |
| `analytics.query_timeout` | 422 | Perhitungan ini terlalu berat. Persempit periode atau saringan. |
| `analytics.busy` | 429 | Terlalu banyak perhitungan berjalan bersamaan. Coba lagi sebentar. (dengan `Retry-After`) |
| `analytics.rate_limited` | 429 | Terlalu banyak permintaan analisis dalam satu menit. Tunggu sebentar, lalu coba lagi. (limiter `analytics-interactive` per pengguna, dengan `Retry-After`) |

## Bahasa rumus

Fase 2 (area 13). Rumus menghitung nilai baru dari measure lain di baris yang sama: rasio, selisih,
persen, kondisi sederhana. Dikompilasi ke SQL di atas ekspresi agregat, jadi urutan dan top-N dapat
memakai hasilnya.

```text
ekspresi  := suku (('+' | '-') suku)*
suku      := faktor (('*' | '/') faktor)*
faktor    := angka | measure | fungsi | '(' ekspresi ')' | '-' faktor
measure   := '[' kunci_measure ']'                  contoh: [acquisition_value]
fungsi    := NAMA '(' argumen (';' argumen)* ')'    pemisah ';' karena koma adalah desimal
kondisi   := ekspresi ('=' | '<>' | '<' | '<=' | '>' | '>=') ekspresi
```

| Fungsi | Arti | SQL |
| --- | --- | --- |
| `BAGI(a; b)` | `a / b`, nol bila `b` nol | `coalesce(a / nullif(b, 0), 0)` |
| `BAGI(a; b; c)` | `a / b`, `c` bila `b` nol | `coalesce(a / nullif(b, 0), c)` |
| `JIKA(kondisi; a; b)` | `a` bila kondisi benar | `case when … then a else b end` |
| `ABS(a)`, `BULAT(a; n)` | Nilai mutlak, pembulatan | `abs(a)`, `round(a, n)` |
| `MIN(a; b)`, `MAKS(a; b)` | Terkecil, terbesar | `least(a, b)`, `greatest(a, b)` |

Contoh: persentase aset dilepas `BAGI([disposed]; [count]) * 100`.

Aturan yang dijaga:

- **Daftar fungsi tertutup.** Nama di luar tabel ditolak saat dibaca, sebelum ada SQL apa pun.
- **Angka menjadi binding**, measure menjadi ekspresi agregat yang sudah divalidasi. Tidak ada teks
  pengguna yang masuk ke SQL.
- **Batas**: 500 karakter, kedalaman 20, 5 rumus per query, rumus tidak boleh memakai rumus lain di
  fase 2.
- **Uang**: rumus atas measure uang mewarisi pengelompokan mata uangnya; rumus yang mencampur measure
  dengan kolom mata uang berbeda ditolak.
- **Galat menunjuk posisi**: "Rumus tidak dapat dibaca di karakter 14: `]` tanpa pasangan".

Test parser wajib mencakup percobaan menyisipkan SQL (`[count]); drop table x; --`), nama fungsi
yang tidak dikenal, pembagian dengan nol, dan angka gaya Indonesia (`1.000,5`).

## Perbandingan periode

Fase 2 (area 13). `compare: "previous_period"` menjalankan query yang sama dengan rentang waktu
digeser sepanjang rentang itu sendiri; `previous_year` menggeser satu tahun. Hasil kedua digabung ke
hasil pertama menurut dimensi selain dimensi waktu, dan setiap measure mendapat tiga kolom tambahan:
`<kunci>__previous`, `<kunci>__change`, `<kunci>__change_pct`. Persen perubahan dari nol ditulis kosong,
bukan tak hingga.

Perbandingan butuh `time_range`; query tanpa rentang waktu ditolak dengan pesan yang memintanya.

## Baris di balik angka

Fase 2 (area 12). Drill-through memakai query terpisah yang memilih baris, bukan kelompok:

- Saringannya saringan widget **ditambah** nilai dimensi yang diklik sebagai kesamaan.
- Kolomnya field yang dipilih penyusun widget, atau field bawaan dataset.
- Kebijakan data dan gerbang data pribadi sama dengan query kelompok.
- Halaman memakai kursor pada `id` tabel dasar, 100 baris per halaman, paling banyak 1.000 baris di
  layar; lebih dari itu lewat antrean ekspor (`report_exports.kind = analytics`).

## Alat operator

- `php artisan analytics:datasets` — daftar dataset per module, versi, jumlah field dan measure, dan
  hasil validasi.
- `php artisan analytics:explain --query=<json> --tenant=<id> --user=<email>` — SQL hasil compile (dan SQL
  total bila diminta) beserta `EXPLAIN (FORMAT TEXT)` di tenant itu, tanpa `ANALYZE`, untuk melihat indeks
  yang dipakai. Query disusun sebagai pengguna itu — hak, hibah kebijakan data, zona waktu — lewat langkah
  yang sama dengan `RunQuery`. Argumen widget menyusul bersama penyimpanan dasbor (area 6).
