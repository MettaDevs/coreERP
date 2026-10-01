# Pemeliharaan preventif: counter, rencana, dan jadwal

Pemeliharaan preventif menjawab satu pertanyaan: **pekerjaan apa yang jatuh tempo, untuk aset mana, kapan.** Tiga bagian bekerja bersama. **Counter** mencatat pemakaian aset (jam operasi, kilometer, jumlah tindakan). **Rencana pemeliharaan** menyatakan aturannya ("kalibrasi setiap 1 tahun", "servis setiap 500 jam"). **Jadwal pemeliharaan** adalah hasil hitungan aturan itu: satu baris usulan per jatuh tempo per aset, yang diubah perencana menjadi work order atau diabaikan.

Rujukan utamanya Dynamics 365 F&O Asset Management. Business Central tidak punya rencana: ia hanya menyimpan satu field **Next Service Date** pada kartu aset, diisi tangan, dan laporan *Maintenance - Next Service* menyaring aset yang tanggalnya jatuh di rentang tertentu (`FixedAsset.Table.al` field 25, `MaintenanceNextService.Report.al`). Itu sebabnya modul ini mengikuti F&O.

| Bagian | Padanan F&O | MS Learn |
| --- | --- | --- |
| Jenis counter dan pembacaannya | Counters, Asset counters | [Counters](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/setup-for-objects/counters), [Manual update of asset counters](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/work-orders/manual-update-of-asset-counters) |
| Rencana pemeliharaan | Maintenance plans | [Maintenance plans](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/preventive-and-reactive-maintenance/maintenance-plans) |
| Hitung jadwal | Schedule maintenance plans | [Schedule maintenance plans](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/preventive-and-reactive-maintenance/schedule-maintenance-plans) |
| Daftar usulan dan Buat work order | Maintenance schedule, Create work orders | [Maintenance schedule](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/preventive-and-reactive-maintenance/maintenance-schedule), [Creating work orders](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/preventive-and-reactive-maintenance/creating-work-orders) |

## Yang mudah tertukar

**Angka meter bukan total pemakaian.** Petugas mengetik angka yang tertera di meter (`nilai`). Total pemakaian (`nilai_total`) dihitung server dan melewati setiap penggantian meter, padanan *Totals* di F&O. Rencana berbasis counter selalu membaca total, bukan angka meter, karena angka meter kembali ke nol saat meternya diganti.

**Usulan bukan work order.** Menghitung jadwal hanya membuat usulan. Work order baru lahir saat perencana memilih usulan dan menekan **Buat work order** — sama seperti F&O tanpa *Auto create*.

**Toleransi hari bukan tenggang keterlambatan.** Toleransi pada rencana (`toleransi_hari_sebelum`/`_sesudah`) adalah rentang di sekitar jatuh tempo untuk mengenali pekerjaan yang **sudah ada**. Ia tidak menunda apa pun. Toleransi counter (`toleransi_counter`) lain lagi: ia membuat usulan muncul lebih awal, saat total counter kurang sebanyak itu dari batasnya.

## Counter aset

| Tabel | Isi |
| --- | --- |
| `aset_m_jenis_counter` | Master jenis counter: kode (Number Sequence), nama, `satuan_id` (satuan Core, opaque, tanpa foreign key) dan salinan kodenya di `satuan`. |
| `aset_m_jenis_aset_counter` | Counter yang boleh dibaca pada aset satu jenis (FastTab *Asset types* pada counter F&O). Disunting dari seksi **Counter aset** di form jenis aset. |
| `aset_tr_pembacaan_counter` | Satu pembacaan: aset, counter, `dibaca_pada` (tanggal dan jam yang diketik petugas), `nilai`, `nilai_total`, `reset`, keterangan. Pencatatnya kolom jejak `created_by_user_id`, ditampilkan dengan nama. |

Aturan yang dijaga `CounterReadingController`, beserta alasannya:

- **Angka meter tidak boleh turun, kecuali ditandai penggantian meter.** Angka yang turun tanpa penanda hampir selalu salah ketik, dan kalau diterima, total pemakaian ikut turun dan rencana berbasis counter mundur. F&O mencatat penggantian sebagai dua baris berwaktu sama: bacaan akhir meter lama, lalu meter baru dengan *Counter reset*. Di sini caranya sama, dan baris reset **tidak menambah total** — angkanya angka awal meter baru.
- **Pembacaan tidak disisipkan di tengah riwayat dan tidak diubah.** Total setiap baris dihitung dari baris sebelumnya, jadi menyisipkan atau mengubah baris lama berarti seluruh total sesudahnya salah. Waktu baca tidak boleh lebih awal dari pembacaan terakhir (boleh sama, untuk penggantian meter), dan yang salah diarsipkan — **hanya yang terakhir** — lalu dicatat ulang.
- **Satu pencatatan pada satu waktu per aset.** Baris aset dikunci (`lockForUpdate`) selama total dihitung; tanpa itu dua pencatatan yang saling menyela sama-sama menghitung dari baris yang sama.
- **Counter berlaku per jenis aset, dengan fallback.** Counter yang dikaitkan ke jenis aset hanya dapat dibaca pada aset jenis itu; counter yang belum dikaitkan ke jenis mana pun berlaku untuk semua. Aturannya sama persis dengan jenis pekerjaan maintenance, supaya tenant yang belum mengatur relasi tetap dapat bekerja.
- **Jangkauan organisasi mengikuti aset.** Pembacaan terlihat dan dapat dicatat oleh pengguna yang menjangkau unit penanggung jawab asetnya (`OrganizationScope::asetQuery`). Aset yang sudah dihentikan atau dilepas tidak lagi dicatat counternya.

Pilihan F&O yang belum ada: pembaruan otomatis dari jam atau jumlah produksi, *Average* (pemantauan ambang seperti suhu), deviasi terhadap tren, *Related counters*, dan *Inherit counter values* ke aset anak. Tidak ada jenis counter bawaan: satuannya milik daftar satuan tiap tenant dan kodenya dari Number Sequence, jadi tenant membuatnya sendiri.

## Rencana pemeliharaan

| Tabel | Isi |
| --- | --- |
| `aset_m_rencana_pemeliharaan` | Header: kode, nama, aktif, `tanggal_mulai` (*Plan date*), `toleransi_hari_sebelum`, `toleransi_hari_sesudah`. |
| `aset_m_rencana_pemeliharaan_baris` | Baris: `dasar`, interval waktu (`interval` + `satuan_interval`) **atau** interval counter (`jenis_counter_id`, `interval_counter`, `toleransi_counter`), jenis pekerjaan (+ varian, bidang keahlian), tipe work order, tingkat layanan, `selesai_dalam_hari`, `deskripsi` untuk work order. |
| `aset_m_rencana_pemeliharaan_objek` | Berlaku untuk: tepat satu dari `aset_id` atau `jenis_aset_id` (constraint `CHECK`), dengan `tanggal_mulai` opsional (*Start date* F&O). |

Tiga dasar hitung (`MaintenancePlanBasis`), dari belasan pilihan *Interval type* F&O:

| Dasar | Padanan F&O | Jatuh tempo |
| --- | --- | --- |
| `tanggal_mulai` | Repeated from start date | Tanggal mulai, lalu setiap interval. Tidak peduli kapan pekerjaan terakhir selesai. |
| `work_order_terakhir` | Repeated from last work order | Interval sesudah `aktual_selesai` work order terakhir berstatus selesai/ditutup untuk aset dan jenis pekerjaan (dan varian, bila disebut) yang sama. Tanpa riwayat: tanggal mulai. Cocok untuk kalibrasi alat kesehatan. |
| `nilai_counter` | Repeated on aggregated value | Setiap kelipatan `interval_counter` dari total counter aset. |

**Keputusan yang diambil sendiri, dan alasannya:**

- **Tipe work order wajib per baris.** F&O jatuh ke parameter *Preventive work order type* bila baris kosong; modul ini belum punya parameter itu, dan menambah parameter hanya untuk satu bawaan tidak sepadan. Baris menyebutnya langsung.
- **Objek jenis aset dibaca saat jadwal dihitung.** Di F&O, rencana pada asset type hanya terpasang pada aset yang dibuat sesudahnya. Di sini seluruh aset jenis itu — yang sudah ada maupun yang diterima kemudian — ikut, karena tenant pertama memasang rencana atas register yang sudah berisi. Objek aset menang atas objek jenis untuk aset yang sama, karena tanggal mulainya lebih spesifik.
- **Baris diperbarui di tempat, tidak dihapus lalu disisipkan ulang** seperti baris template checklist. Usulan menunjuk id baris rencana dan keunikannya berkunci id itu; baris yang berganti id setiap simpan akan mengusulkan ulang jatuh tempo yang sudah diusulkan. Baris yang dikeluarkan diarsipkan.
- **Hanya aset yang cocok dengan jenis pekerjaannya yang diusulkan.** Jenis pekerjaan yang sudah dikaitkan ke jenis aset hanya berlaku untuk jenis itu — aturan pembuatan work order. Tanpa penyaringan ini lahir usulan yang mustahil dijadikan work order.

Belum ada: *once*, *linked* ("1 tahun atau 25.000 km, mana yang lebih dulu"), *once reached above/below*, musim, *Suppress overlapping maintenance jobs* antar baris, *Auto create*, objek lokasi fungsional, dan proyeksi tren counter ke depan.

## Jadwal pemeliharaan

`aset_tr_jadwal_pemeliharaan` menyimpan satu usulan per jatuh tempo: rencana, baris, aset, entitas legal dan unit penanggung jawab aset saat dihitung, `jatuh_tempo`, `nilai_jatuh_tempo` (batas total counter; kosong untuk baris waktu), `nilai_counter` (total saat dihitung), `status`, dan `pemeliharaan_aset_id`.

| Status | Arti | Siapa mengubahnya |
| --- | --- | --- |
| `usulan` | Dihitung, belum diputuskan (*Created*). | Perhitungan jadwal. |
| `work_order_dibuat` | Sudah menjadi work order (*Work order created*). | **Buat work order**. |
| `diabaikan` | Ditolak perencana (*Discard*). | **Abaikan**. |

### Cara usulan dihitung

`MaintenanceScheduleCalculator::run()` berjalan untuk setiap rencana aktif, setiap baris aktif, dan setiap aset yang dikenainya, sampai tanggal batas yang dipilih pengguna (paling jauh satu tahun):

1. **Berulang dari tanggal mulai.** Kejadian = tanggal mulai + k × interval. Yang sudah lewat **hanya diusulkan yang terakhir** — pekerjaan terlambat tetap terlihat, tetapi rencana bulanan yang dimulai lima tahun lalu tidak menimbun enam puluh usulan basi. Yang akan datang diusulkan seluruhnya sampai tanggal batas. F&O tidak pernah mengusulkan tanggal yang sudah lewat; di sini sengaja diusulkan, karena kalibrasi yang terlewat justru yang harus terlihat.
2. **Berulang dari work order terakhir.** Satu jatuh tempo saja: interval sesudah work order terakhir selesai, atau tanggal mulai bila belum pernah. Jatuh tempo berikutnya baru dapat dihitung setelah pekerjaan ini selesai.
3. **Berulang setiap nilai counter.** k = ⌊(total + toleransi) ÷ interval⌋; bila k ≥ 1, batasnya k × interval dan tanggalnya tanggal pembacaan pertama yang mencapai batas dikurangi toleransi — F&O juga memakai waktu pembacaan yang melewati batas. Aset tanpa pembacaan counter itu dilewati, sama seperti F&O.
4. **Toleransi.** Jatuh tempo dibuang bila aset sudah punya work order (tidak dibatalkan, tidak diarsipkan) untuk jenis pekerjaan yang sama dengan tanggal acuan di rentang [jatuh tempo − toleransi sebelum, jatuh tempo + toleransi sesudah]. Tanggal acuannya selesai aktual, lalu jadwal, lalu harapan, lalu tanggal dibuat — yang pertama terisi. Ini padanan *Tolerance days* + *Suppress overlapping maintenance jobs* F&O, diterapkan terhadap work order yang sudah ada, termasuk yang dibuat tangan.
5. **Simpan, idempoten.** Usulan disisipkan dengan `ON CONFLICT DO NOTHING` di atas dua indeks unik parsial: `(tenant, baris, aset, jatuh_tempo)` untuk baris waktu dan `(tenant, baris, aset, nilai_jatuh_tempo)` untuk baris counter. Menghitung dua kali — atau dua orang menghitung bersamaan — tidak pernah melahirkan usulan kembar. Usulan yang sudah menjadi work order atau diabaikan menahan kuncinya, jadi tidak lahir lagi (F&O: *discarded lines never come back*).
6. **Bersihkan.** Usulan yang masih `usulan` tetapi tidak lagi dihasilkan perhitungan — interval diubah, aset dikeluarkan dari rencana, rencana atau barisnya dimatikan, batas counter tergantikan kelipatan berikutnya — diarsipkan. Yang sudah menjadi work order atau diabaikan tidak disentuh.

Hari ini dihitung menurut zona waktu pengguna (`RequestContext::timezone()`), dan seluruh perbandingan memakai tanggal kalender, bukan jam server.

### Dari usulan ke work order

**Buat work order** (padanan dialog *Create work orders*) memakai `WorkOrderCreator`, jalur yang sama dengan `POST pemeliharaan-aset`: pemeriksaan master aktif, varian milik jenis pekerjaannya, jenis pekerjaan cocok dengan jenis aset, aset dalam jangkauan dan masih beredar, nomor dari Number Sequence, dan checklist bawaan jenis pekerjaan. Jalur ini dipindahkan dari controller work order supaya tiga pemakainya (layar work order, jadwal, permintaan) tidak menyalin aturan yang lambat laun menyimpang.

- `kelompok=baris` membuat satu work order per usulan; `kelompok=aset` menggabungkan usulan satu aset dengan tipe work order yang sama menjadi satu work order dengan beberapa baris pekerjaan.
- Entitas legal dan unit dibaca dari aset **saat ini**, bukan dari usulan: aset mungkin sudah dimutasi sejak jadwal dihitung. Aset tanpa unit penanggung jawab ditolak dengan pesan yang menyebut asetnya.
- Diharapkan mulai = jatuh tempo; diharapkan selesai = jatuh tempo + `selesai_dalam_hari` bila diisi. Keterangan = `deskripsi` baris, atau nama rencana dan jenis pekerjaan beserta jatuh temponya.
- Kunci idempotensi work order adalah `jadwal:<id usulan pertama>`, dan setiap usulan mengklaim versinya sendiri. Permintaan ulang tidak membuat work order kedua.

## Endpoint

| Endpoint | Guna |
| --- | --- |
| `GET/POST jenis-counter`, `GET/PATCH/DELETE jenis-counter/{id}` | Master jenis counter (`MasterDataController`). |
| `GET/PUT jenis-aset/{id}/counter` | Counter yang berlaku untuk satu jenis aset; mengklaim versi jenis aset. |
| `GET/POST pembacaan-counter`, `DELETE pembacaan-counter/{id}` | Daftar, catat, arsipkan pembacaan terakhir. |
| `GET pembacaan-counter/counter-aset?aset_id=` | Counter yang dapat dibaca pada satu aset beserta pembacaan terakhirnya. |
| `GET/POST rencana-pemeliharaan`, `GET/PATCH/DELETE rencana-pemeliharaan/{id}` | Header rencana. |
| `GET/PUT rencana-pemeliharaan/{id}/baris`, `GET/PUT rencana-pemeliharaan/{id}/objek` | Baris dan objek; mengklaim versi header. |
| `GET jadwal-pemeliharaan` | Daftar usulan (bawaan status `usulan`), dengan penanda terlambat. |
| `POST jadwal-pemeliharaan/hitung` | Hitung usulan sampai tanggal batas. |
| `POST jadwal-pemeliharaan/work-order` | Usulan terpilih menjadi work order. |
| `POST jadwal-pemeliharaan/{id}/abaikan` | Abaikan satu usulan. |

Kontraknya di `contracts/src/paths/pemeliharaan-preventif.yaml`.

## Hak akses

| Manifest | Duty | Isi |
| --- | --- | --- |
| `maintenance/counters.yaml` | `jenis-counter.manage` | Kelola master jenis counter. |
| `maintenance/counter-readings.yaml` | `pembacaan-counter.maintain-readings` | Catat dan arsipkan pembacaan. Terpisah dari kelola work order: yang membaca meter di lapangan tidak perlu boleh menyusun pekerjaan. |
| `maintenance/maintenance-plans.yaml` | `rencana-pemeliharaan.manage` | Kelola rencana, baris, dan objeknya. |
| `maintenance/maintenance-schedule.yaml` | `jadwal-pemeliharaan.manage` | Lihat, hitung, abaikan. **Membuat work order dari usulan menuntut juga `pemeliharaan-aset.create`** (duty kelola work order), jadi penjadwal tanpa duty itu hanya dapat menghitung dan meninjau. |

Permission pembacaan counter dan jadwal masuk kebijakan `management-aset.asset-responsibility` (`manifest/asset-responsibility.yaml`); master tidak.

## Belum ada

- **Job terjadwal harian.** Perhitungan dijalankan dari layar. Job harian butuh daftar tenant yang memasang module, dan tenant production bawaannya berdatabase sendiri; menelusuri tenant lintas database adalah urusan Core, dan belum ada pola module untuk itu.
- **Maintenance rounds** F&O (satu pekerjaan berulang untuk banyak aset sekaligus, misalnya pelumasan) — di luar tahap ini.
- Penyetelan counter otomatis saat work order mencapai status tertentu (*Reset counter* pada lifecycle state F&O).
- Load test untuk perhitungan jadwal pada register besar.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `database/migrations/2026_10_01_100000_create_asset_counter_tables.php` | Tabel counter. |
| `database/migrations/2026_10_01_110000_create_maintenance_plan_tables.php` | Tabel rencana dan jadwal, indeks unik idempotensi. |
| `src/Http/Controllers/master/CounterTypeController.php`, `AssetTypeCounterController.php` | Master counter dan relasinya ke jenis aset. |
| `src/Http/Controllers/transaksi/CounterReading/CounterReadingController.php` | Pembacaan counter. |
| `src/Http/Controllers/master/MaintenancePlanController.php`, `MaintenancePlanDetailController.php` | Header, baris, dan objek rencana. |
| `src/Services/MaintenanceScheduleCalculator.php` | Perhitungan usulan. |
| `src/Http/Controllers/transaksi/MaintenanceSchedule/MaintenanceScheduleController.php` | Daftar, hitung, abaikan, buat work order. |
| `src/Services/WorkOrderCreator.php` | Jalur pembuatan work order bersama. |
| `src/Support/MaintenancePlanBasis.php`, `MaintenanceScheduleStatus.php` | Dasar hitung dan status usulan. |
| `ui/transactions/counter-readings/`, `ui/transactions/maintenance-schedule/` | Layar pembacaan counter dan jadwal. |
| `ui/master/detail/MaintenancePlanLines.tsx`, `MaintenancePlanTargets.tsx`, `JenisAsetCounterTypes.tsx` | Seksi baris dan objek rencana, dan seksi Counter aset pada jenis aset. |
| `tests/Feature/AssetCounterTest.php`, `MaintenanceScheduleTest.php` | Test. |

## Test

- `AssetCounterTest`: satuan dari Core, total melewati penggantian meter, angka turun ditolak, riwayat tidak disisipi, hanya pembacaan terakhir yang diarsipkan, idempotensi, counter per jenis aset, jangkauan organisasi.
- `MaintenanceScheduleTest`: ketiga dasar hitung, terlambat terakhir saja, idempotensi perhitungan ulang, kelipatan counter yang tergantikan, toleransi terhadap work order yang ada, usulan menjadi satu work order per aset lengkap dengan nomor dan checklist bawaan, usulan yang diabaikan tidak kembali, baris yang diubah membersihkan usulan basi, rencana dimatikan, validasi baris, duty jadwal tanpa duty work order tidak dapat membuat work order (manifest sungguhan), dan jangkauan organisasi.

## Halaman terkait

- [Pemeliharaan aset (work order)](/apps/management-aset/transaction/pemeliharaan-aset/)
- [Permintaan pemeliharaan](/apps/management-aset/transaction/permintaan-pemeliharaan/)
- [Setup maintenance](/apps/management-aset/master/maintenance/)
