# Riset: analitik di D365, semantic layer terbuka, dan cara membuka data ke luar

Bahan keputusan untuk [engine analitik](/todo/analitik/). Ditulis 3 Oktober 2026 dari tiga sumber:
source Business Central di `D:\Kerja\BCApps` (dibaca lewat `bc-tools`, commit `777e102e90`),
Microsoft Learn, dan dokumentasi resmi produk lain. Butir yang belum dapat dipastikan ditandai
**belum pasti** beserta cara memastikannya; jangan memperlakukannya sebagai fakta sebelum diuji.

Halaman ini menjawab tiga pertanyaan pemilik produk:

1. Apakah membangun sendiri masuk akal, atau cukup memakai Power BI / alat sumber terbuka?
2. Seberapa jauh engine perlu dibangun untuk ERP ini?
3. Data dibuka ke luar lewat apa: GraphQL, OData, REST, atau yang lain?

## 1. Bagaimana Microsoft menyusunnya

Microsoft memisahkan analitik menjadi tiga tingkat, dan pemisahan yang sama dipakai rencana ini.

| Tingkat | Business Central | F&O | Padanan di rencana ini |
| --- | --- | --- | --- |
| Analisis di dalam aplikasi | *Data analysis mode*, Generic Chart, Cue, Headline | Workspace dengan tile dan chart | Dasbor, widget, penjelajah data (fase 1–2) |
| Penyimpanan teragregasi | Analysis View (363) beserta entry-nya (365) | Entity store: *aggregate measurements* dan *aggregate dimensions* | Ringkasan per tenant (fase 3) |
| Data untuk alat di luar | API query + konektor Power BI | OData data entities, Synapse Link | Query API, feed OData, embed (fase 2–3) |

### Business Central

**Query object adalah bentuk dataset BC.** Aplikasi PowerBIReports — puluhan query, satu per bahan
laporan Power BI — memakai satu pola yang sama:

- `QueryType = API` dengan `APIGroup = 'analytics'` dan `DataAccessIntent = ReadOnly`, jadi
  bacaannya boleh diarahkan ke replika baca.
- Kolom membawa `Method = Sum` atau `Count`; BC mengelompokkan menurut kolom lain yang tidak
  beragregasi.
- Join ditulis `DataItemLink`, saringan tetap ditulis `DataItemTableFilter`.
- Query lain di BC memakai `OrderBy` dan `TopNumberOfRows` untuk top-N, misalnya `Top10CustomerSales`.

Potongan dari
[`SalesLinesOutstanding.Query.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Apps/W1/PowerBIReports/App/Inventory/APIs/SalesLinesOutstanding.Query.al)
(lisensi MIT):

```al
query 36975 "Sales Lines - Outstanding"
{
    QueryType = API;
    APIPublisher = 'microsoft';
    APIGroup = 'analytics';
    EntitySetName = 'outstandingSalesLines';
    DataAccessIntent = ReadOnly;
    // ...
            column(outstandingQtyBase; "Outstanding Qty. (Base)")
            {
                Method = Sum;
            }
```

Akibatnya bagi kita: dataset yang dinyatakan module — kolom bertipe, agregasi per measure, join yang
dinyatakan, saringan tetap — bukan bentuk karangan sendiri. Itu bentuk BC.

**Generic Chart memberi pengguna grafik tanpa koding.** Tabel 9180–9183
([folder `GenericChart`](https://github.com/microsoft/BCApps/tree/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/System/GenericChart)):
pengguna memilih sumber (tabel atau query), sumbu X, sumbu Z (seri) opsional, judul, dan saringan.
Setiap measure di sumbu Y punya agregasi sendiri (`None, Count, Sum, Min, Max, Avg`) dan jenis grafik
sendiri (kolom, garis, area, bertumpuk, pie, donat, funnel, dan lain-lain). Daftar pilihan itu yang
ditiru pembangun widget.

**Cue memakai dua ambang dan gaya bermakna, bukan warna.**
[`Cue Setup` (9701)](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/System%20Application/App/Cues%20and%20KPIs/src/CueSetup.Table.al)
berkunci pengguna + tabel + field (pengguna kosong = bawaan perusahaan), dengan `Threshold 1`,
`Threshold 2`, dan gaya untuk rentang rendah, tengah, tinggi. Gayanya `Favorable`, `Unfavorable`,
`Ambiguous`, `Subordinate`, atau `None`. Gaya bermakna dipetakan ke token warna tema, sehingga tema
gelap dan aturan "warna bukan satu-satunya sinyal" otomatis terjaga.

**Analysis View adalah pra-agregasi milik tenant.**
[`Analysis View` (363)](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/Finance/Analysis/AnalysisView.Table.al)
menyimpan ringkasan per akun, sampai empat dimensi, dan tanggal yang dipadatkan (hari sampai tahun).
Ia diperbarui saat posting bila *Update on Posting* menyala, atau bertahap mulai dari `Last Entry No.`.
Pola ini dipakai untuk ringkasan di fase 3: ringkasan per tenant yang diperbarui bertahap, bukan
materialized view yang dibangun ulang seluruhnya.

**Role Center menyematkan Power BI lewat satu page part**,
[`Power BI Embedded Report Part` (6325)](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/Modules/System/PowerBI/Embedding/PowerBIEmbeddedReportPart.Page.al).
Datanya keluar lewat API query di atas, dibaca konektor Power BI. Di BC online, autentikasinya OAuth
Entra; *web service access key* (Basic) berhenti berlaku di online sejak 1 Oktober 2022 dan hanya
tersisa di on-prem
([autentikasi web service](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/webservices/web-services-authentication)).

**BC tidak mendokumentasikan `$apply` OData.** Agregasi di BC dilakukan query object
([totals dan grouping](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-query-totals-grouping)),
bukan oleh pemanggil lewat URL. **Belum pasti** — tidak ada halaman Learn yang menyatakan dukungan
atau penolakannya; laporan komunitas menyebut tidak berjalan.

**Data analysis mode** memberi pengelompokan dan pivot di halaman daftar tanpa meninggalkan layar
([analysis mode](https://learn.microsoft.com/en-us/dynamics365/business-central/analysis-mode)).
Padanan kita: penjelajah data per dataset (fase 2).

### Finance and Operations

- **Entity store** adalah gudang data operasional berisi *aggregate measurements* (star schema yang
  disederhanakan) dan *aggregate dimensions*, sumber workspace analitis dengan Power BI tertanam
  ([entity store](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/analytics/entity-store-on-prem),
  [menambah dimensi ke aggregate measurement](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/analytics/add-financial-dimensions-aggregate-measurements)).
  Measure dan dimensi dinyatakan pengembang, bukan disusun pengguna dari tabel mentah — sama dengan
  keputusan bahwa dataset dinyatakan module.
- **OData data entity** sinkron dan bawaannya sempit: hanya perusahaan bawaan pengguna, melebar ke
  semua perusahaan yang boleh diakses pengguna hanya bila diminta (`cross-company=true`). Rinciannya
  sudah dicatat di [API untuk integrator](/todo/api-untuk-integrator/).
- **Export to Data Lake berhenti 1 November 2024**, digantikan Azure Synapse Link for Dataverse
  ([pengumuman](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/azure-data-lake-ga-version-overview)).
  Ekspor terus-menerus ke data lake adalah bentuk ketiga untuk analitik luar; di rencana ini ia di
  luar cakupan.

### Power BI

- **"Embed for your customers"** (*app owns data*): backend memakai service principal, meminta embed
  token dengan *effective identity* sehingga RLS ikut di token, dan butuh kapasitas berbayar
  ([RLS pada embed](https://learn.microsoft.com/en-us/power-bi/developer/embedded/embedded-row-level-security),
  [embedded analytics](https://learn.microsoft.com/en-us/power-bi/developer/embedded/embedded-analytics-power-bi)).
  Biaya kapasitas per pelanggan adalah alasan pemilik produk memilih membangun sendiri. **Belum
  pasti**: masa berlaku bawaan embed token 60 menit hanya dari ringkasan pihak ketiga.
- **Publish to web** membuka laporan ke siapa pun di internet tanpa autentikasi, termasuk data model
  yang tidak tampil di laporan
  ([publish to web](https://learn.microsoft.com/en-us/power-bi/collaborate-share/service-publish-to-web)).
  Untuk data fasilitas kesehatan bentuk itu tidak ditiru sama sekali.

## 2. Semantic layer dan BI sumber terbuka

| Produk | Yang dipelajari | Kenapa tidak dipakai langsung |
| --- | --- | --- |
| Cube | Bentuk query JSON `{measures, dimensions, timeDimensions, filters, order, limit, offset}` ([format](https://docs.cube.dev/reference/core-data-apis/rest-api/query-format)); tenant disuntikkan saat penulisan ulang query dari konteks tepercaya ([security context](https://docs.cube.dev/docs/data-modeling/access-control/context), [multitenancy](https://docs.cube.dev/embedding/multitenancy)); pra-agregasi per tenant | Layanan Node.js terpisah dengan kredensial database sendiri: runtime kedua di samping Core, juga di server on-prem pelanggan, dan kebijakan data kita harus ditulis ulang di sana |
| dbt MetricFlow | Measure, dimensi, entity sebagai kunci join; jenis metrik simple, ratio, derived, cumulative ([metrics](https://docs.getdbt.com/docs/build/metrics-overview)) | Alat pemodelan untuk gudang data, bukan runtime aplikasi |
| Metabase | Embed tamu memakai JWT bertanda tangan berisi `resource`, `params`, dan `exp` (contohnya 10 menit); parameter terkunci diisi server ([guest embedding](https://www.metabase.com/docs/latest/embedding/guest-embedding)) | Edisi terbuka AGPL v3 dan membawa lencana "Powered by"; embed interaktif dan RLS hanya di edisi berbayar ([lisensi](https://www.metabase.com/license/agpl), [harga](https://www.metabase.com/pricing)) |
| Apache Superset | Backend meminta *guest token* berisi dashboard dan klausa RLS, dengan `aud` tetap | Stack Python terpisah; **belum pasti**: laporan bahwa di versi 5.0 RLS guest token diabaikan bila sebuah setelan tidak dinyalakan ([diskusi](https://github.com/apache/superset/discussions/36494)) |
| Looker | `access_filter` per pengguna dan `sql_always_where` untuk semua ([keamanan](https://docs.cloud.google.com/looker/docs/best-practices/how-to-keep-looker-secure)) | Produk komersial tertutup |

Lima kebiasaan yang ditiru dari semuanya:

1. **Hanya anggota model yang dapat disebut.** Pemanggil memilih field dan measure yang dinyatakan,
   tidak pernah menulis SQL.
2. **Tenant dan batas data disuntikkan di server dari konteks tepercaya**, di satu tempat sebelum
   query disusun, tidak pernah dari parameter pemanggil.
3. **Parameter keamanan yang hilang menggagalkan permintaan.** Metabase punya jebakan yang perlu
   dihindari: parameter terkunci berisi daftar kosong `[]` justru **mematikan** saringannya. Di sini
   saringan terkunci yang kosong berarti nol baris atau permintaan ditolak, tidak pernah "semua".
4. **Cache dan pra-agregasi selalu berkunci tenant** dan batas data pemanggilnya.
5. **Token embed berumur pendek dan terikat tujuan** (`aud`, dashboard, saringan terkunci).

Alasan keputusan KA-01 (membangun sendiri) tidak hanya bisnis. Ketiga alat terbuka di atas menuntut
runtime kedua dengan kredensial database sendiri. Itu bertentangan dengan satu runtime, membuat
server on-prem pelanggan menjalankan layanan tambahan, dan memaksa kebijakan data organisasi —
yang sudah dimiliki Core — ditulis ulang di alat lain.

## 3. Membuka data ke luar: REST, OData, atau GraphQL

Yang menentukan bukan selera teknologi, melainkan **alat apa yang akan dipakai pelanggan untuk
membacanya**.

| Pemakai | OData v4 | GraphQL | REST JSON / CSV |
| --- | --- | --- | --- |
| Excel, Power BI Desktop | Bawaan (`OData.Feed`) | Tidak ada; harus menulis `Web.Contents` POST sendiri | Konektor Web |
| Tableau | Bawaan, hanya extract; `$expand` dan `$select` tidak didukung ([Tableau OData](https://help.tableau.com/current/pro/desktop/en-us/examples_odata.htm)) | Tidak | Lewat konektor |
| Looker Studio | Tidak; konektor komunitas | Tidak | Konektor komunitas |
| Google Sheets | Tidak | Tidak | `IMPORTDATA` (CSV, tanpa header) |
| n8n | Node HTTP | Node GraphQL ([n8n](https://n8n.io/integrations/graphql/)) | Node HTTP |
| Zapier, Make | HTTP | **Belum pasti** ada node bawaan | HTTP, webhook |
| Developer yang membangun layar sendiri | Bisa | Bisa | Bisa |

**GraphQL tidak dipakai di v1.** Alasannya:

- Tidak satu pun alat BI yang dipakai pelanggan membacanya tanpa kode tambahan.
- Ia butuh pembatas kedalaman dan kompleksitas serta *persisted query* supaya satu permintaan tidak
  menghabiskan database. Lighthouse mendukung keduanya, tetapi validasi kompleksitasnya tidak dapat
  di-cache ([caching query](https://lighthouse-php.com/master/performance/query-caching.html)), dan
  ia satu dependency besar lagi.
- Kelebihan utamanya — pemanggil meminta persis field yang ia mau — sudah diberikan query JSON kita
  sendiri.

Bila kelak sebuah partner benar-benar memintanya, GraphQL dapat ditambahkan sebagai pintu lain di
atas mesin query yang sama, tanpa mengubah mesinnya.

**OData v4 baca-saja untuk Excel dan Power BI.** Konektor OData Power Query
([OData feed](https://learn.microsoft.com/en-us/power-query/connectors/odata-feed)) menerima
Anonymous, Windows, Basic, Web API, dan Organizational account. Tiga hal yang mengubah desain:

- **Kunci "Web API" dikirim di query string.** Opsi `ApiKeyName` adalah *nama* parameter URL yang
  membawa kunci ([`OData.Feed`](https://learn.microsoft.com/en-us/powerquery-m/odata-feed)).
  Kunci itu karena itu masuk log akses dan proxy; ia harus baca-saja, khusus feed, dapat dicabut, dan
  disamarkan di log.
- **Basic menurut Learn butuh gateway untuk refresh terjadwal di Power BI service.** **Belum pasti**:
  laporan komunitas menyebut feed OData di cloud dapat di-refresh dengan Basic tanpa gateway. Perlu
  diuji ke endpoint kita sendiri sebelum dijanjikan ke pelanggan.
- **Power Query melipat saringan dan kolom** ke `$filter`, `$select`, `$top`, dan `$orderby`.
  **Belum pasti** apakah `Table.Group` dilipat menjadi `$apply`; anggap tidak. Karena itu feed
  menerbitkan query tersimpan yang sudah teragregasi, bukan mengandalkan pemanggil mengagregasi lewat
  URL.

Pustaka OData untuk Laravel, `flat3/lodata`, sudah mendukung Laravel 12 (rilis 5.34.0) dan masih
aktif ([rilis](https://github.com/flat3/lodata/releases)), tetapi dirawat terutama satu orang dan
lisensinya **belum diperiksa**. Pilihan antara pustaka ini dan subset OData buatan sendiri adalah
keputusan dependency (KA-13), diputuskan lewat spike satu hari.

**REST JSON untuk developer, CSV untuk spreadsheet.** Query API memakai bentuk query yang sama dengan
layar kita sendiri, jadi apa pun yang dapat disusun di layar dapat dipanggil dari luar. CSV melayani
Google Sheets dan alat sejenis yang tidak dapat mengirim header.

## 4. Embed yang aman

- **Token berumur menit, terikat tujuan.** Contoh Metabase 10 menit; Superset mengikat `aud`.
  Token dicetak backend situs pelanggan, tidak pernah oleh peramban.
- **`frame-ancestors` per embed.** Header CSP pada halaman embed menyebut asal situs yang boleh
  memasangnya; situs lain mendapat halaman yang menolak dipasang.
- **Tanpa cookie sesi di dalam iframe.** Safari dan Firefox memblokir cookie pihak ketiga secara
  bawaan; Chrome membatalkan rencana penghapusannya pada 2025, tetapi embed yang bergantung pada
  cookie tetap rusak di dua peramban itu
  ([ringkasan 2025](https://www.smashingmagazine.com/2025/05/reliably-detecting-third-party-cookie-blocking-2025/)).
  Token disimpan di memori halaman embed dan dikirim sebagai header `Authorization`.
- **Token tidak lewat query string.** Query string bocor lewat header `Referer`, log server, dan
  riwayat peramban. Fragment URL (`#...`) tidak pernah dikirim di `Referer`
  ([MDN Referer](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referer)).
  Bentuk terbaik: token pertama lewat fragment, pembaruan lewat `postMessage` dengan pemeriksaan
  `event.origin` yang ketat.
- **Saringan terkunci gagal tertutup.** Lihat jebakan Metabase di bagian 2.

## 5. Pengaman PostgreSQL untuk query yang disusun pengguna

Dari dokumentasi resmi PostgreSQL
([client config](https://www.postgresql.org/docs/current/runtime-config-client.html),
[SET TRANSACTION](https://www.postgresql.org/docs/current/sql-set-transaction.html)):

- Setiap query analitik berjalan di transaksi `READ ONLY` dengan `SET LOCAL statement_timeout`.
  `SET LOCAL` berlaku hanya sampai transaksi selesai, jadi tidak bocor ke permintaan berikutnya pada
  koneksi persisten. PostgreSQL 17 menambah `transaction_timeout`.
- Batas baris lewat `LIMIT n + 1`: baris ke-`n+1` menandai hasil terpotong tanpa menghitung semuanya.
- `EXPLAIN (FORMAT JSON)` memberi perkiraan biaya sebelum query mahal dijalankan;
  `pg_stat_statements` menunjukkan query mana yang benar-benar mahal setelah berjalan.
- `date_trunc(field, timestamptz, zona)` tersedia sejak PostgreSQL 12
  ([rilis 12](https://www.postgresql.org/docs/release/12.0/)); pengelompokan per hari atau bulan
  memakai zona waktu pengguna, bukan UTC.
- `GROUPING SETS` / `ROLLUP` memberi subtotal dalam satu query.
- `REFRESH MATERIALIZED VIEW CONCURRENTLY` butuh indeks unik dan tetap membangun ulang seluruh view
  ([refresh](https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html)). Untuk data
  per tenant yang terus tumbuh, tabel ringkasan bertahap ala Analysis View lebih cocok.
- Indeks BRIN untuk kolom waktu yang hampir selalu bertambah
  ([BRIN](https://www.postgresql.org/docs/current/brin.html)).

## 6. Grafik dan tata letak

- **Recharts 3.8.0 sudah terpasang** di `apps/core/package.json` dan `packages/ui/package.json`.
  Komponen chart shadcn juga sudah pindah ke Recharts v3
  ([PR shadcn](https://github.com/shadcn-ui/ui/pull/8486)). Tidak perlu pustaka grafik baru.
- **Apache ECharts** (Apache-2.0) lebih lengkap dan punya dukungan ARIA
  ([ARIA ECharts](https://echarts.apache.org/handbook/en/best-practices/aria/)), tetapi besar.
  Dipertimbangkan hanya bila Recharts tidak sanggup (misalnya peta panas besar).
- **react-grid-layout v2** (ditulis ulang dengan TypeScript, Desember 2025,
  [RFC](https://github.com/react-grid-layout/react-grid-layout/blob/master/rfcs/0001-v2-typescript-rewrite.md))
  dibutuhkan hanya bila pengguna harus menyeret dan mengubah ukuran tile. Fase 1 memakai grid CSS
  dengan ukuran tetap dan tombol pindah, tanpa dependency.

## 7. Yang belum pasti, dan cara memastikannya

| Butir | Cara memastikan | Dipakai di |
| --- | --- | --- |
| Refresh terjadwal Power BI service untuk feed OData ber-Basic tanpa gateway | Spike: feed contoh di SaaS dev, refresh terjadwal dari workspace Power BI | KA-13, area 16 |
| Power Query melipat `Table.Group` ke `$apply` | Spike yang sama, amati URL yang dipanggil | Area 16 |
| Lisensi `flat3/lodata` | Baca `LICENSE` di repo-nya | KA-13 |
| Excel "From OData feed" dengan kunci Web API pada endpoint kita | Spike | Area 16 |
| Masa berlaku bawaan embed token Power BI | Tidak dibutuhkan; TTL kita diputuskan sendiri | — |

## Sumber lain

- [Integration overview F&O](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/integration-overview)
  — tiga pertanyaan (real-time, volume puncak, frekuensi) sebelum memilih pola.
- [Web services BC](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/webservices/web-services)
  — page, codeunit, dan query diterbitkan sebagai OData v4.
- [Rilis Lighthouse](https://github.com/nuwave/lighthouse/releases).
