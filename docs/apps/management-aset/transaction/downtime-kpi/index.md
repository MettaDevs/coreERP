# Downtime dan KPI pemeliharaan

Downtime mencatat **kapan sebuah aset tidak dapat dipakai, berapa lama, dan kenapa**. KPI pemeliharaan menghitung dari catatan itu dan dari work order yang selesai: seberapa sering aset rusak, berapa lama rata-rata memperbaikinya, dan berapa persen waktu aset tersedia.

Padanannya *Maintenance downtime* dan *Maintenance downtime reason codes* ([Maintenance downtime for work orders](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/work-orders/maintenance-downtime)) serta *Asset KPIs* ([Asset KPIs](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/controlling-and-reporting/asset-kpis)) di Dynamics 365 F&O Asset Management. Business Central hanya punya *Maintenance Ledger* (biaya servis) dan laporan *Maintenance - Analysis* yang menjumlahkan biaya per kode maintenance; ia tidak mengenal downtime maupun MTBF.

## Yang mudah tertukar

**Downtime bukan "Aktivitas downtime maintenance".** Flag `maintenance_downtime_activities` pada jenis pekerjaan adalah *Maintenance downtime activities* F&O: pekerjaan jenis ini menuntut aset berhenti sebelum dikerjakan (perencanaan henti). Catatan downtime di sini adalah *maintenance downtime registration*: henti yang **sudah terjadi**. Flag itu yang menentukan work order mana yang mencatat downtime-nya sendiri.

**Downtime bukan durasi work order.** Aset bisa berhenti lebih lama dari pekerjaannya (menunggu suku cadang), atau tidak berhenti sama sekali selama pekerjaan (inspeksi). Karena itu downtime catatan sendiri, bukan turunan `aktual_mulai`/`aktual_selesai` work order.

## Data yang disimpan

| Tabel | Isi |
| --- | --- |
| `aset_m_alasan_downtime` | Master alasan (*Maintenance downtime reason code*) dengan `masuk_kpi` (*KPI include*). Kode dari Number Sequence (`ALDT`, lingkup tenant). |
| `aset_tr_downtime_aset` | Aset, `mulai`, `selesai` (kosong selama masih berhenti), alasan, work order, permintaan pemeliharaan, `sumber` (`manual` atau `work_order`), keterangan. Waktu disimpan dalam UTC. |

Riwayat perubahan `mulai`, `selesai`, dan arsip dinyalakan bawaan (migration, `ChangeLogDefaults`), karena koreksi waktu menggerakkan availability.

## Pencatatan dari work order: semi-otomatis

**Keputusan:** downtime dibuat otomatis, waktunya boleh dikoreksi. F&O membuat registrasi dengan tangan dari work order; di sini otomatis supaya tidak terlupa, dan layar downtime tetap untuk koreksi dan untuk henti di luar work order. Aturannya di `Services/WorkOrderDowntime`, dipanggil dari perpindahan status work order:

| Work order berpindah ke | Yang terjadi pada aset di baris yang jenis pekerjaannya bertanda *Aktivitas downtime maintenance* |
| --- | --- |
| `dikerjakan` | Aset yang **sudah** punya downtime terbuka tanpa work order (dicatat operator saat mesin rusak) tidak dibuatkan catatan baru: catatan itu dikaitkan ke work order ini. Aset tanpa downtime terbuka dibuatkan catatan mulai dari `aktual_mulai` work order, bersumber `work_order`. Catatan yang akan tumpang tindih dengan downtime lain dilewati. |
| `selesai` | Downtime terbuka yang terkait work order ini ditutup pada `aktual_selesai`. |
| `dibatalkan` | Ditutup pada waktu pembatalan: aset memang berhenti selama itu walau pekerjaannya batal. |

Permintaan pemeliharaan yang melahirkan work order ikut tercatat pada downtime, supaya henti dapat ditelusuri ke laporan kerusakannya. Pencatatan downtime **tidak pernah menahan** perpindahan status work order.

## Aturan pencatatan, dan alasannya

- **Waktu menurut zona pengguna.** Isian tanpa offset dibaca menurut zona pengguna (`RequestContext::timezone()`) dan disimpan dalam UTC; layar menampilkannya kembali menurut zona yang sama.
- **Tidak di masa depan.** Downtime mencatat yang sudah terjadi, padanan *registration* F&O, bukan rencana.
- **Tidak tumpang tindih per aset.** F&O hanya menolak dua registrasi berwaktu persis sama; aturan di sini lebih ketat supaya satu jam tidak terhitung dua kali pada availability. Pencatatan mengunci baris aset, dan indeks unik parsial menolak dua catatan terbuka untuk satu aset.
- **Aset, work order, dan permintaan tidak dapat diganti** sesudah dicatat, seperti di F&O. Yang boleh dikoreksi: waktu, alasan, keterangan.
- **Jangkauan organisasi** mengikuti unit penanggung jawab aset. **Versi baris** pada koreksi dan arsip.

## Rumus KPI

Dihitung saat dibaca oleh `Services/MaintenanceKpi`, bukan disimpan sebagai tabel agregat: angkanya bergantung pada periode yang dipilih, dan downtime serta work order boleh dikoreksi sesudahnya. Rumus mengikuti tabel field halaman *Asset KPIs* F&O; satuannya jam.

| KPI | Rumus | F&O |
| --- | --- | --- |
| **Total waktu** | Jam dari awal periode — atau awal hari aset mulai dipakai (`placed_in_service_on`, atau `acquired_on`), mana yang lebih akhir — sampai akhir periode atau saat ini, mana yang lebih awal. | *Total time* |
| **Downtime** | Jumlah jam catatan downtime yang berpotongan dengan total waktu, dipotong ke batasnya; catatan terbuka dihitung sampai akhir total waktu. Catatan beralasan `masuk_kpi = false` tidak dihitung; catatan tanpa alasan dihitung. | *Downtime* dengan *KPI include* |
| **Uptime** | Total waktu − downtime. | *Uptime* |
| **Availability %** | Uptime ÷ total waktu × 100. Kosong bila total waktu nol. | *Availability %* |
| **Jumlah henti** | Banyaknya catatan downtime yang dihitung di atas. | *Number of stops* |
| **Jumlah kerusakan** | Baris pekerjaan ber-sebab kerusakan (`sebab_kerusakan_id` atau keterangan sebab kerusakan terisi) pada work order berstatus selesai atau ditutup yang `aktual_selesai`-nya di dalam periode. | *Number of faults* |
| **MTBF** | Total waktu ÷ jumlah kerusakan; tanpa kerusakan, MTBF = total waktu. | *MTBF* |
| **Jam perbaikan** | Jam aktual baris pekerjaan ber-sebab kerusakan itu. | *Repair time* |
| **MTTR** | Jam perbaikan ÷ jumlah kerusakan; tanpa kerusakan, MTTR = jam perbaikan. | *MRT* |
| **Work order selesai** | Banyaknya work order berstatus selesai atau ditutup yang `aktual_selesai`-nya di dalam periode dan memuat aset itu. | *Work orders* |

**Kelompok** (per jenis aset, per lokasi, dan total) menjumlahkan total waktu, downtime, kerusakan, jam perbaikan, henti, dan work order anggotanya, lalu **menghitung ulang rasionya dari jumlah itu**, bukan merata-ratakan rasio per aset; aset kecil dengan satu hari pakai tidak boleh berbobot sama dengan aset setahun penuh. Jenis dan lokasi yang dipakai adalah yang tercatat pada aset sekarang.

Contoh yang diuji: satu aset sepanjang September (720 jam), downtime 10 + 2 jam dan 4 jam dari catatan yang mulai 31 Agustus, henti terencana 5 jam tidak dihitung, dua kerusakan dengan 3 dan 5 jam kerja: availability 704 ÷ 720 = 97,78%, MTBF 720 ÷ 2 = 360 jam, MTTR 8 ÷ 2 = 4 jam, tiga henti, dua work order selesai.

### Selisih dengan F&O, dengan sengaja

- **Kalender 24 jam.** F&O menghitung total waktu dari kalender kerja aset (*Resource calendar* atau *Standard calendar* parameter). Modul ini belum punya kalender kerja; total waktu adalah jam kalender penuh. Availability mesin yang hanya beroperasi 8 jam sehari akan terlihat lebih tinggi dari angka F&O.
- **Periode yang melewati saat ini dihitung sampai saat ini**, supaya jam yang belum terjadi tidak terhitung sebagai uptime.
- **Kerusakan dihitung dari baris pekerjaan, bukan dari *fault symptom*.** Modul ini mencatat sebab kerusakan per baris pekerjaan; F&O mencatat *fault cause* pada *fault symptom*.
- Biaya (*Preventive/Corrective cost*), *Fail rate*, *MTBS*, *MTPS*, dan *Reliability %* belum dihitung.

## Dipakai ulang

`MaintenanceKpi::calculate(Builder $assets, CarbonInterface $from, CarbonInterface $until, string $groupBy)` menerima query aset yang sudah disaring pemanggil (jangkauan organisasi dan penyaringnya sendiri) dan periode dalam zona pengguna. Layar KPI memakainya lewat `GET kpi-pemeliharaan`; dashboard aset dapat memanggilnya langsung di dalam proses dengan cara yang sama, tanpa menyalin rumus.

## Layar dan hak akses

| Layar | Menu | Isi |
| --- | --- | --- |
| Downtime aset | Transaksi → Downtime aset | Daftar, catat, koreksi, arsipkan. Saring per aset dan yang masih berhenti. |
| KPI pemeliharaan | Laporan → KPI pemeliharaan | Periode, kelompok (aset, jenis, lokasi), saring jenis dan lokasi; ringkasan total di atas tabel. |
| Alasan downtime | Maintenance → Alasan downtime | Master; centang *Henti terencana, tidak dihitung pada KPI* untuk menyimpan `masuk_kpi = false`. |
| Detail aset | Inventarisasi aset → aset → bagian **Downtime** | Sepuluh catatan terakhir. Hanya baca. |

Duty `management-aset.downtime-aset.manage` (*Catat downtime aset*) dan `management-aset.kpi-pemeliharaan.analyze` (*Analisis kinerja pemeliharaan*) terpisah: yang membaca KPI tidak perlu boleh mengoreksi downtime. Downtime otomatis dari work order berjalan dengan hak work order itu sendiri.

Tidak ada data acuan alasan downtime untuk tenant lama: catatan tanpa alasan tetap dihitung, sama seperti F&O, jadi KPI sudah benar tanpa master terisi.

## Endpoint

| Endpoint | Guna |
| --- | --- |
| `GET/POST alasan-downtime`, `GET/PATCH/DELETE .../{id}` | Master alasan. |
| `GET/POST downtime-aset`, `PATCH/DELETE downtime-aset/{id}` | Downtime aset. |
| `GET kpi-pemeliharaan?dari=&sampai=&kelompok=` | KPI per kelompok, dan totalnya di `meta.total`. Periode paling panjang 400 hari. |

Kontraknya di `contracts/src/paths/downtime-kpi.yaml`.

## Belum ada

- Kalender kerja per aset atau lokasi untuk total waktu.
- *Maintenance downtime activities* sebagai perencanaan henti (kapasitas teknisi dalam satu jendela henti).
- KPI biaya pemeliharaan, yang menunggu biaya work order.

## Test

`tests/Feature/AssetDowntimeTest.php`: downtime manual tidak tumpang tindih dan tidak di masa depan, satu catatan terbuka per aset, koreksi butuh versi; work order dengan pekerjaan yang menuntut berhenti membuka dan menutup downtime-nya, pekerjaan lain tidak; downtime operator yang terbuka diambil alih work order dan ditutup saat dibatalkan; rumus KPI dibandingkan dengan hitungan tangan, termasuk pemotongan periode, aset yang mulai dipakai di tengah periode, alasan tidak dihitung, dan pengelompokan; layar KPI menuntut permission-nya; downtime mengikuti jangkauan aset.
