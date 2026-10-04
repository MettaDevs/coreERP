# Dasbor dan visual

Bagian dari [engine analitik](/todo/analitik/). Halaman ini menetapkan cara dasbor, widget, dan
query tersimpan disimpan, API yang dipakai layar, dan cara layar menggambar hasil query. Aturan
hak dan berbagi ada di [keamanan](/todo/analitik/keamanan#dasbor-widget-dan-query-tersimpan).

## Tabel

Semua tabel Core biasa berawalan `analytics_`, tinggal di database environment tenant, dan membawa
kolom jejak, `version`, dan tiga trigger yang sama dengan `report_presets`. Migration ditulis tanpa
kelas `App\`, karena admin.erp ikut menjalankan migration Core.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dasbor, widget, dan query tersimpan engine analitik (area 6). Rancangannya di
 * docs/todo/analitik/dasbor-dan-visual.md. Kolom jejak dan trigger ditulis langsung seperti migration
 * preset laporan, karena admin.erp ikut menjalankan migration Core tanpa kelas `App\`.
 *
 * Kode dataset bukan foreign key: dataset hidup di kode module dan dapat hilang bersama module yang
 * dicabut, sedangkan widget-nya tidak ikut dihapus (tidak ada baris yang dihapus fisik).
 */
return new class extends Migration
{
    private const TABLES = ['analytics_dashboards', 'analytics_widgets', 'analytics_saved_queries'];

    public function up(): void
    {
        Schema::create('analytics_dashboards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->unsignedBigInteger('user_id');            // pemilik
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->boolean('shared')->default(false);
            $table->jsonb('layout')->default('[]');           // [{widget_id, x, y, w, h}]
            $table->jsonb('slicers')->default('[]');          // fase 2
            $table->string('template_code', 160)->nullable(); // asal template, fase 2
            $table->unsignedInteger('template_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->index(['tenant_id', 'shared']);
            $table->index(['tenant_id', 'user_id']);
        });
        DB::statement('CREATE UNIQUE INDEX analytics_dashboards_owner_name_unique ON analytics_dashboards (tenant_id, user_id, lower(name)) WHERE deleted_at IS NULL AND NOT shared');
        DB::statement('CREATE UNIQUE INDEX analytics_dashboards_shared_name_unique ON analytics_dashboards (tenant_id, lower(name)) WHERE deleted_at IS NULL AND shared');

        Schema::create('analytics_widgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('dashboard_id')->constrained('analytics_dashboards');
            $table->string('title', 120);
            $table->string('type', 20);                         // kpi, bar, column, line, area, donut, table, text
            $table->string('dataset_code', 160)->nullable();    // kosong untuk widget teks
            $table->unsignedInteger('dataset_version')->nullable();
            $table->jsonb('query')->nullable();                 // AnalyticsQuery
            $table->jsonb('visual')->default('{}');
            $table->unsignedInteger('cache_ttl_seconds')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->index(['tenant_id', 'dashboard_id']);
            $table->index(['tenant_id', 'dataset_code']);
        });

        Schema::create('analytics_saved_queries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('code', 80);                         // dipakai publikasi dan feed, fase 2
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('shared')->default(false);
            $table->string('dataset_code', 160);
            $table->unsignedInteger('dataset_version');
            $table->jsonb('query');
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        DB::statement('CREATE UNIQUE INDEX analytics_saved_queries_code_unique ON analytics_saved_queries (tenant_id, lower(code)) WHERE deleted_at IS NULL');

        foreach (self::TABLES as $table) {
            DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
            DB::statement("CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()");
            DB::statement("CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

Model ketiganya mengikuti `ReportPreset`: `HasUlids`, `SoftDeletes`,
`#[DataClassification(DataClass::CustomerContent)]`, dan `COLUMN_CLASSIFICATION` yang menandai
`user_id` sebagai `EndUserPseudonymousIdentifiers`. Tabel baru juga wajib lolos
`DataClassificationBoundaryTest`, `AuditColumnsBoundaryTest`, dan `MigrasiKompatibelMundurTest`.

Tabel fase 2 (area 15, 17, 18) — `analytics_publications`, `analytics_embed_tokens`,
`analytics_role_dashboards`, `analytics_user_preferences` — dirancang di halaman areanya, dengan
pola yang sama. Tabel cache dan log ada di [kinerja](/todo/analitik/kinerja-dan-uji-beban).

## API untuk layar

Di bawah `api/v1/analytics`, sesi dan CSRF seperti API Core lain, didaftarkan di
`routes/analytics.php` yang di-require dari grup `auth` di `routes/web.php` — bukan dari grup
`api/v1`, karena berkas yang sama memuat halaman `/analytics/...`. Hanya layar Core pemakainya, jadi
kontraknya hasil Scramble, bukan tulisan tangan. Setiap rute dijaga permission di kolom Hak lewat
`CoreSecurityCatalog::gate(...)` (area 4); saklar sementara area 0 sudah dibuang.

| Metode dan path | Hak | Gunanya |
| --- | --- | --- |
| `GET datasets` | `dashboard.read` | Dataset yang boleh dibaca pengguna ini |
| `GET datasets/{code}` | `dashboard.read` | Field, measure, field waktu, dimensi bersama (data pribadi disaring) |
| `POST query` | `explore.invoke` | Menjalankan query bebas |
| `GET dashboards` | `dashboard.read` | Milik sendiri dan bersama |
| `POST dashboards` | `dashboard.create`; `shared` butuh `shared-dashboard.update` | |
| `GET dashboards/{id}` | Aturan berbagi | Susunan dan widget, tanpa data |
| `PATCH dashboards/{id}` | Aturan berbagi, `If-Match` | Nama, tata letak, slicer |
| `DELETE dashboards/{id}` | Aturan berbagi, `If-Match` | Arsipkan |
| `POST dashboards/{id}/widgets` | Seperti mengubah dasbor | Query divalidasi saat disimpan |
| `PATCH widgets/{id}` / `DELETE widgets/{id}` | Seperti mengubah dasbornya, `If-Match` | |
| `GET widgets/{id}/data` | Melihat dasbornya; hak dataset | Hasil query widget; slicer di query string |
| `POST widgets/{id}/refresh` | Seperti melihat | Lewati cache untuk widget ini |
| `GET/POST/PATCH/DELETE saved-queries` | Seperti dasbor | |

```php
// routes/analytics.php — di-require dari grup `auth`; halaman di luar blok ini, API di dalamnya.
use App\Platform\Access\Support\CoreSecurityCatalog as Security;

Route::prefix('api/v1/analytics')->name('api.analytics.')->group(function (): void {
    Route::middleware(Security::gate(Security::ANALYTICS_DASHBOARD_READ))->group(function (): void {
        Route::get('datasets', [DatasetController::class, 'index'])->name('datasets.index');
        Route::get('datasets/{code}', [DatasetController::class, 'show'])->name('datasets.show');
        Route::get('dashboards', [DashboardController::class, 'index'])->name('dashboards.index');
        Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');
        Route::get('widgets/{widget}/data', WidgetDataController::class)
            ->middleware('throttle:analytics-interactive')->name('widgets.data');
    });
    Route::post('query', QueryController::class)
        ->middleware([Security::gate(Security::ANALYTICS_EXPLORE_INVOKE), 'throttle:analytics-interactive'])
        ->name('query');
    // Menulis: hak dicek di controller, karena dasbor pribadi dan bersama butuh permission berbeda.
    Route::post('dashboards', [DashboardController::class, 'store'])->name('dashboards.store');
    Route::patch('dashboards/{dashboard}', [DashboardController::class, 'update'])->name('dashboards.update');
    Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])->name('dashboards.destroy');
    // … widget dan query tersimpan dengan pola yang sama
});
```

Route model binding untuk `{dashboard}` dan `{widget}` wajib menyaring tenant aktif (scope di model
atau `resolveRouteBinding`), sehingga id tenant lain menjadi 404 sebelum controller berjalan.

### Yang dikirim area 6

*4 Oktober 2026.* Seluruh blok area 6 di `routes/analytics.php` dijaga `core.analytics.dashboard.read`;
`POST dashboards` dan `POST saved-queries` juga `core.analytics.dashboard.create`. Mengubah dan
mengarsipkan diputuskan `Dashboards\DashboardAccess` di controller. `{dashboard}`, `{widget}`, dan
`{savedQuery}` dicari di tenant aktif saja (`Models\BindsWithinActiveTenant`).

| Metode dan path | Jawaban | Galat khusus |
| --- | --- | --- |
| `GET datasets` | `{data: DatasetSummary[]}` — module terpasang dan permission baca dipegang | |
| `GET datasets/{code}` | `{data: DatasetDescription}` — field dan measure data pribadi disaring | 404 `analytics.dataset_unknown`, 403 `analytics.dataset_forbidden` |
| `GET dashboards` | `{data: DashboardSummary[]}` milik sendiri dan bersama, urut nama | |
| `POST dashboards` | 201 `{data: DashboardDetail}`, `ETag` | 403 tanpa hak; 422 nama ganda |
| `GET dashboards/{id}` | `{data: DashboardDetail}`, `ETag` | 404 pribadi orang lain |
| `PATCH dashboards/{id}` | `name`, `description`, `shared`, `layout`; `If-Match` atau `version` | 428, 409, 403 dasbor bersama tanpa hak, 422 letak |
| `DELETE dashboards/{id}` | 204; widget-nya ikut diarsipkan | 428, 409 |
| `POST dashboards/{id}/widgets` | 201 `{data: DashboardWidget}`, `ETag` | `query.…` dari validator, `analytics.invalid_visual`, 422 `analytics.limit_exceeded` |
| `PATCH widgets/{id}` / `DELETE widgets/{id}` | `title`, `type`, `query`, `visual`, `cache_ttl_seconds` / 204 | 428, 409 |
| `GET widgets/{id}/data`, `POST widgets/{id}/refresh` | `ResultSet` seperti `POST query`, dihitung sebagai yang melihat | 422 `analytics.field_removed`, 403 `analytics.dataset_forbidden`, 404 |
| `GET/POST saved-queries`, `GET/PATCH/DELETE saved-queries/{id}` | `{data: SavedQuery}`; `?dataset=` menyaring daftar | 422 kode ganda |

Halaman: `GET /analytics` merender `platform/analytics/index` dengan prop `dashboards` dan `abilities`
(`{create, share}`), `GET /analytics/dashboards/{id}` merender `platform/analytics/dashboard` dengan prop
`dashboard` dan `abilities`. Komponennya milik area 7.

Perilaku yang perlu diketahui layar:

- **Versi naik dua kali** pada perubahan yang menulis kolom: klaim versi lalu penyimpanannya. Pakai `version`
  atau `ETag` dari jawaban, jangan menghitung sendiri.
- **Menambah widget tidak mengubah versi dasbor.** `layout` di jawaban adalah letak efektif: letak tersimpan
  untuk widget yang masih ada, lalu widget tanpa letak di bawahnya, dua per baris, `w` 6 dan `h` 2. `PATCH
  layout` hanya menerima widget dasbor itu, `x + w ≤ 12`, `w` 3/4/6/8/12, `h` 1–3.
- **Query widget dikirim dalam bentuk ringkas** (tanpa nilai bawaan) dengan kunci yang sudah dipetakan lewat
  `renamed`. `status` widget menyebut definisi yang tidak dapat dihitung tanpa menghitungnya: `ok`,
  `field_removed` beserta `missing_fields`, atau `dataset_unavailable`. Izin membaca datanya baru diketahui
  dari data widget.
- **Galat simpan widget** berbentuk `{error: {code, message, field}}` dengan `field` berawalan `query.` atau
  `visual.`; isian dasar (judul, jenis, masa simpan) memakai galat validasi Laravel `{message, errors}`. Keduanya
  dibaca `CoreApiError`.
- **Mengganti jenis widget tanpa mengirim `visual`** memakai tampilan bawaan jenis baru, bukan tampilan lama.
- **Kode query tersimpan** dibuat dari nama bila tidak dikirim (`nilai-perolehan`, lalu `-2`), unik per tenant,
  dan tidak dapat diganti.
- Belum ada: slicer (fase 2, area 12), cache dan rate limit `analytics-interactive` (area 9; sampai itu
  `refresh` sama dengan data), dan menyalin dasbor bersama menjadi pribadi. Kontrak API ini tidak ditulis
  tangan karena pemakainya hanya layar Core; `contracts/openapi.json` hasil Scramble belum diperbarui sejak
  lama dan tidak memuatnya.

## Widget

| Jenis | `visual` | Kebutuhan query |
| --- | --- | --- |
| `kpi` | `{measure, thresholds?, compact?: bool}` | Satu measure; boleh satu dimensi waktu untuk garis kecil (fase 2) |
| `bar`, `column` | `{x, series?, y: [measure], stacked?: "none" \| "stacked" \| "percent", show_values?: bool}` | 1–2 dimensi, 1–4 measure |
| `line`, `area` | Sama, `x` wajib dimensi waktu | Dimensi waktu + 0–1 dimensi seri |
| `donut` | `{category, value, max_slices?: 8}` | Satu dimensi, satu measure |
| `table` | `{columns: [kunci], show_totals?: bool}` | Apa pun dalam batas |
| `text` | `{text}` | Tanpa query. Teks biasa dengan baris baru; bukan Markdown atau HTML |

*Dikirim area 6:* aturan ini diperiksa `Dashboards\WidgetDefinition` saat widget disimpan. Tile fase 1 tidak
menerima pengelompokan; pengelompok kedua grafik wajib menjadi `series`; `measure` tile serta `category` dan
`value` donat boleh dihilangkan dan diisi dari query-nya; bagian `visual` yang tidak dikenal ditolak.

Ambang tile mengikuti Cue Setup BC — dua ambang, tiga rentang, gaya bermakna — dan dipetakan ke
token tema, bukan warna mentah:

```json
{
  "measure": "disposed",
  "thresholds": { "threshold1": 5, "threshold2": 20, "low": "favorable", "middle": "ambiguous", "high": "unfavorable" }
}
```

| Gaya | Token | Ikon |
| --- | --- | --- |
| `favorable` | `success` | panah naik / centang |
| `unfavorable` | `destructive` | segitiga peringatan |
| `ambiguous` | `warning` | tanda tanya |
| `subordinate` | `muted-foreground` | — |
| `none` | bawaan kartu | — |

Warna tidak pernah menjadi satu-satunya sinyal: setiap gaya membawa ikon dan teks pembaca layar
("Di atas ambang 20").

## Layar

| Halaman | Rute | Isi |
| --- | --- | --- |
| Daftar dasbor | `/analytics` | Kartu `DataTable` dasbor milik sendiri dan bersama; aksi Buat dasbor |
| Dasbor | `/analytics/dashboards/{id}` | Grid widget; mode ubah; slicer (fase 2) |
| Analisis data | `/analytics/explore` | Penjelajah: pilih data, kelompokkan, saring, tabel atau grafik, simpan |
| Publikasi | `/settings/analytics/publications` | Fase 2 |

Aturan halaman Core berlaku: tanpa `AppLayout` ganda, breadcrumb lewat `Page.layout`, entri di
`components/app-sidebar.tsx` dengan permission rutenya — Daftar dasbor `core.analytics.dashboard.read`,
Analisis data `core.analytics.explore.invoke` (area 4 sudah memasangnya) — dan diperiksa dengan
menelusuri rail, bukan mengetik URL (skill `coreerp-ui`, bagian *New pages inside Control Plane*).

### Grid

Grid 12 kolom dengan lebar 3, 4, 6, 8, atau 12 dan tinggi 1–3 baris. Kelas Tailwind ditulis lengkap
di peta, **tidak disusun dari string**: `col-span-${w}` tidak pernah dipindai Tailwind dan kelasnya
tidak ada di CSS hasil build.

```tsx
const WIDTH: Record<number, string> = {
    3: 'lg:col-span-3',
    4: 'lg:col-span-4',
    6: 'lg:col-span-6',
    8: 'lg:col-span-8',
    12: 'lg:col-span-12',
};
const HEIGHT: Record<number, string> = { 1: 'min-h-36', 2: 'min-h-72', 3: 'min-h-[27rem]' };

export function DashboardGrid({ widgets }: { widgets: DashboardWidget[] }) {
    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {widgets.map((widget) => (
                <div key={widget.id} className={cn('col-span-1', WIDTH[widget.w] ?? WIDTH[6], HEIGHT[widget.h] ?? HEIGHT[2])}>
                    <WidgetFrame widget={widget} />
                </div>
            ))}
        </div>
    );
}
```

Mode ubah fase 1 memindah widget dengan tombol "Geser ke kiri/kanan" dan memilih lebar dari daftar.
`layout` tetap menyimpan `x, y, w, h`, supaya seret-lepas di fase 2 (react-grid-layout v2, bila
disetujui) tidak butuh migrasi data.

### Memuat data widget

```tsx
/**
 * Data satu widget, dimuat saat widget masuk layar. Dasbor 24 widget tidak menembakkan 24 query
 * sekaligus; yang di bawah lipatan menunggu digulir.
 */
export function useWidgetData(widgetId: string, slicers: SlicerValues) {
    const ref = useRef<HTMLDivElement | null>(null);
    const [visible, setVisible] = useState(false);
    const [state, setState] = useState<WidgetState>({ status: 'idle' });
    const key = `${widgetId}:${JSON.stringify(slicers)}`;

    useEffect(() => {
        const node = ref.current;
        if (!node) return;
        const observer = new IntersectionObserver(([entry]) => entry.isIntersecting && setVisible(true), { rootMargin: '200px' });
        observer.observe(node);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (!visible) return;
        const controller = new AbortController();
        setState({ status: 'loading' });
        fetchWidgetData(widgetId, slicers, controller.signal)
            .then((result) => setState({ status: 'ready', result }))
            .catch((error: unknown) => {
                if (!controller.signal.aborted) setState(widgetError(error));
            });
        return () => controller.abort();
        // `key` menggantikan slicers sebagai dependensi supaya objek baru dengan isi sama tidak memuat ulang.
    }, [visible, key]);

    return { ref, state };
}
```

`widgetError()` memetakan kode galat ke keadaan yang dapat ditindaklanjuti: `analytics.dataset_forbidden`
→ "Anda tidak punya akses ke data ini", `analytics.field_personal_data` → "Kolom ini memuat data
pribadi", `analytics.query_timeout` → "Perhitungan terlalu berat" dengan tombol Ubah widget, 404 →
"Data ini tidak tersedia lagi". Satu widget yang gagal tidak pernah menjatuhkan dasbor.

### Grafik

Grafik memakai `@apperp/ui/chart` (pembungkus shadcn atas Recharts 3.8), yang sudah ada tetapi belum
dipakai siapa pun. Warna seri dari token `--chart-1` … `--chart-5` di tema SDK, jadi tema gelap ikut.

```tsx
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@apperp/ui/chart';

export function ColumnWidget({ result, visual }: { result: ResultSet; visual: ColumnVisual }) {
    const x = column(result, visual.x);
    const measures = visual.y.map((key) => column(result, key));
    const config = Object.fromEntries(
        measures.map((m, i) => [m.key, { label: m.caption, color: `var(--chart-${(i % 5) + 1})` }]),
    ) satisfies ChartConfig;
    const data = result.rows.map((row) => ({
        ...row,
        __x: displayValue(x, row),          // label, bukan id; periode menjadi "Sep 2026"
        ...Object.fromEntries(measures.map((m) => [m.key, Number(row[m.key] ?? 0)])),
    }));

    return (
        <ChartContainer config={config} className="h-full w-full" aria-label={chartSummary(result, visual)}>
            <BarChart data={data} accessibilityLayer>
                <CartesianGrid vertical={false} />
                <XAxis dataKey="__x" tickLine={false} axisLine={false} />
                <YAxis tickFormatter={(v: number) => formatCompact(measures[0], v)} width={72} />
                <ChartTooltip content={<ChartTooltipContent formatter={(v, name) => formatMeasure(result, String(name), v)} />} />
                {measures.length > 1 && <ChartLegend content={<ChartLegendContent />} />}
                {measures.map((m) => (
                    <Bar key={m.key} dataKey={m.key} fill={`var(--color-${m.key})`} radius={4}
                        stackId={visual.stacked === 'none' ? undefined : 'stack'} />
                ))}
            </BarChart>
        </ChartContainer>
    );
}
```

- **Angka di grafik dikonversi `Number()` hanya untuk menggambar.** Tooltip dan tabel memformat dari
  string aslinya, supaya `1250000000.00` tidak menjadi `1249999999.9999998`.
- **Uang lintas mata uang tidak digambar sebagai satu seri.** Hasil dengan dimensi mata uang tersirat
  digambar per mata uang (panel kecil per mata uang), atau widget meminta pengguna menyaring satu mata
  uang.
- **Setiap grafik punya padanan tabel** ("Lihat sebagai tabel" di menu widget) dan `aria-label` yang
  merangkum isinya. Keduanya syarat aksesibilitas, bukan tambahan.
- **Titik dibatasi**: grafik garis lebih dari 366 titik meminta granularitas lebih kasar, bukan
  menggambar ribuan titik.

### Format angka

`lib/analytics/format.ts` adalah satu-satunya tempat angka hasil analitik diformat di layar:

```ts
const NUMBER = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const COMPACT = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });

export function formatMeasureValue(column: ResultColumn, row: Record<string, ResultValue>, compact = false): string {
    const raw = row[column.key];
    if (raw === null || raw === undefined) return '—';
    const value = Number(raw);
    switch (column.format) {
        case 'money': {
            const currency = String(row[column.currency_key ?? ''] ?? 'IDR');
            return new Intl.NumberFormat('id-ID', {
                style: 'currency', currency, notation: compact ? 'compact' : 'standard', maximumFractionDigits: compact ? 1 : 2,
            }).format(value);
        }
        case 'percent':
            return `${NUMBER.format(value)}%`;
        case 'hours':
            return `${NUMBER.format(value)} jam`;
        default:
            return compact ? COMPACT.format(value) : NUMBER.format(value);
    }
}
```

`Intl` bahasa Indonesia menulis ringkasan sebagai "rb", "jt", "M", dan "T" — "Rp 1,3 M" untuk
satu koma tiga miliar — sama dengan cara orang menyebutnya.

## Pembangun widget

`Sheet` sisi kanan dengan isi bergulir dan tombol Simpan/Batal tetap, `portalContainer` diteruskan
ke setiap `Select` di dalamnya (aturan *UI overlay dropdowns* di `AGENTS.md`).
Urutannya mengikuti cara pengguna berpikir, bukan struktur JSON:

1. **Data** — pilih dataset (nama tampilan dan deskripsinya; module yang tidak terpasang tidak muncul).
2. **Nilai** — measure, banyak pilihan.
3. **Kelompokkan menurut** — sampai empat field; field waktu meminta per hari/minggu/bulan/kuartal/tahun.
4. **Saring** — memakai ulang pola `AdditionalFilters` K-30: field bawaan tampil, sisanya lewat
   "+ Tambah saringan"; rujukan memakai `MasterFilter`; contoh sintaks di bawah isian.
5. **Periode** — daftar token relatif, atau rentang tanggal sendiri.
6. **Tampilan** — jenis widget yang cocok dengan pilihan di atas saja; jenis yang tidak cocok
   dinonaktifkan dengan alasannya ("Grafik garis butuh pengelompokan waktu").
7. **Pratinjau** — query dijalankan saat isian berhenti berubah (jeda 500 ms), dengan batas baris
   kecil.

Komponen saringan K-30 hari ini tinggal di UI module aset (`ui/laporan/_shared/AdditionalFilters.tsx`).
Area 8 membuat versi Core di `resources/js/components/analytics/filter-editor.tsx` dari pola yang sama;
layar laporan aset tidak diubah.

## Slicer, cross-filter, drill (fase 2)

- **Slicer** tersimpan di `analytics_dashboards.slicers`: kunci, judul, sumber (dimensi bersama atau
  field satu dataset), kontrol (pilih banyak, rentang tanggal, ekspresi), nilai bawaan. Nilai yang
  sedang dipakai tinggal di **query string** (`?s[unit]=…&s[periode]=@this_month`), dibaca ulang setiap
  render, tidak disalin ke `useState` — aturan *State the page does not own* di skill `coreerp-page-standard`.
  Dasbor yang tersaring dapat dibagikan sebagai tautan.
- **Pemetaan slicer ke widget**: slicer dimensi bersama berlaku ke setiap widget yang datasetnya
  punya field dengan dimensi bersama itu; slicer field berlaku ke widget dari dataset yang sama.
  Widget yang tidak terkena menampilkan penanda "Saringan unit kerja tidak berlaku di sini".
- **Cross-filter**: klik nilai di satu widget menambah saringan sementara (chip yang dapat dihapus)
  ke widget lain dengan pemetaan yang sama seperti slicer.
- **Drill-down** waktu (tahun → kuartal → bulan → hari) mengganti granularitas widget dan menambah
  saringan rentang; tombol Kembali mengembalikan tingkat sebelumnya.
- **Drill-through** membuka `Sheet` berisi baris di balik angka ([mesin query](/todo/analitik/mesin-query#baris-di-balik-angka));
  baris membuka layar record module bila dataset menyatakan `recordRoute`.

## Template dan beranda per peran (fase 2)

- Module mendaftarkan template lewat `Contracts\Analytics\DashboardTemplates`: kode, versi, judul,
  deskripsi, dan widget (query JSON + visual) atas dataset miliknya sendiri.
- Galeri template hanya menampilkan template dari module yang terpasang. **Pakai** membuat dasbor
  tenant baru dengan `template_code` dan `template_version`. Template versi baru tidak menimpa salinan
  tenant; dasbor salinan menandai "Template punya versi baru" dan pengguna dapat memasangnya ulang
  sebagai dasbor baru (KA-17).
- Admin menetapkan dasbor beranda per security role (`analytics_role_dashboards`, dengan prioritas
  untuk pengguna berperan banyak); pengguna boleh memilih berandanya sendiri
  (`analytics_user_preferences`). Halaman `/dashboard` kerangka diganti: preferensi pengguna → dasbor
  peran berprioritas tertinggi → keadaan kosong yang menautkan ke galeri.
- Dasbor aset sesi "Struktur layer" menjadi template pertama `management-aset.asset-overview`, dengan
  angka yang sama dengan endpoint lamanya. Satu catatan dari endpoint lama yang **tidak** ikut dibawa:
  rentang dua belas bulannya memakai `Carbon::today()` zona aplikasi, sedangkan engine memakai zona
  pengguna.
