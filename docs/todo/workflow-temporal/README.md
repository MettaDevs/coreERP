# Fondasi proses custom dengan Flowable

Rencana pilot engine dan integrasi satu proses aset, dibuat 7 Oktober 2026. Ini backlog,
bukan fitur yang sudah berjalan atau pengganti desain kanonik. Belum ada dependency engine baru
yang dipasang oleh pekerjaan ini. CoreERP belum mempunyai pelanggan aktif; kebutuhan variasi
proses berasal dari pengalaman pelanggan sistem lama.

**Keputusan pemilik, 31 Oktober 2026: workflow proses bisnis memakai Flowable.**
Keputusan ini menggantikan pembandingan Temporal sebelumnya. [Pilot Flowable](./pemilihan-engine)
membuktikan versi, deployment, binding PHP dan recovery sebelum integrasi penuh; pemilihan engine
bukan bukti fitur sudah berjalan. Pembuktian implementasi pertama tetap satu flow aset.

Arah produk mencakup proses lintas Healthcare, Procurement dan Finance dengan satu source bersama.
Developer menyediakan tindakan dan batasnya sekali; operator memilih paket default saat membuat
tenant, lalu admin yang berwenang menyesuaikan template proses dan aturan sebagai data berversi.
Posting profile, loket pembayaran, aturan penjamin dan tujuan integrasi tidak disamakan dengan
urutan proses. Flowable mengoordinasikan tindakan; fakta bisnis dan validasi tetap milik module.

Pemilik kemudian meminta fondasi mengacu pada panduan SAP Signavio lokal supaya kemampuan
dapat bertambah per kebutuhan client. Model proses dipisah dari editor dan executor. Ini
menambah arah desain sejak awal, bukan memperluas flow pertama menjadi seluruh Signavio.

## Isi paket

| Halaman                                      | Tujuan                                                                               |
| -------------------------------------------- | ------------------------------------------------------------------------------------ |
| [PRD](./prd)                                 | Kebutuhan, satu flow, scope, dan keputusan material                                  |
| [Pemilihan engine](./pemilihan-engine)       | Keputusan Flowable dan gate kesiapan integrasi                                       |
| [Riset dan bukti](./riset)                   | Source existing/sistem lama, referensi resmi, dan batas klaim                        |
| [Fondasi Signavio](./fondasi-signavio)       | Pemetaan PDF SAP, model semantik, evolusi per kemampuan, dan bahan yang masih kurang |
| [Contoh XML Signavio](./contoh-xml-signavio) | Bukti export trial non-executable, batas produk SAP, dan gate importer/publish       |
| [Arsitektur](./arsitektur)                   | Kepemilikan data, transaksi, outbox, authorization, versi, dan extensibility         |
| [Pemasangan](./pemasangan)                   | Engine Java, worker PHP, adapter, database, lisensi, CI, dan on-prem                 |
| [TODO](./TODO)                               | Area kerja berurutan dengan file, dependency, dan kriteria selesai                   |
| [Verifikasi](./verifikasi)                   | Test nyata, crash, retry, oracle SQL, beban, dan keputusan lanjut                    |

## Keputusan dan batas

| ID    | Keputusan                                                                                                                                | Status                                                                               |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| WT-01 | Rencana awal Temporal, mulai satu flow aset                                                                                              | Historis; pilihan engine diganti WT-16                                               |
| WT-02 | Flow awal: penerimaan draft → permintaan penyelesaian → tindakan module → hasil terlihat                                                 | Usulan untuk dikunci di area 0; binding sesuai engine                                |
| WT-03 | Tidak menambah approval wajib atau tipe workflow baru untuk flow ini                                                                     | Usulan; bukan izin mengubah semua proses                                             |
| WT-04 | Pembuatan aset dan posting perolehan tetap satu transaksi database module                                                                | Invariant existing yang harus dipertahankan                                          |
| WT-05 | Konsep BPMN Signavio; renderer existing digunakan bila sesuai format terpilih                                                            | WT-09/12/13; belum keputusan parser/modeler baru                                     |
| WT-06 | Self-host pada percobaan; cara distribusi SaaS/on-prem ditentukan dari hasil pengukuran                                                  | Usulan; belum deployment wajib semua instalasi                                       |
| WT-07 | Integrasi luar melalui API/event berkontrak, tidak diberi akses langsung ke runtime engine                                               | Mengikuti batas extensibility CoreERP                                                |
| WT-08 | Tidak membangun financial dimension BC, pemisahan database legal entity, procurement lengkap, atau koneksi healthcare pada pekerjaan ini | Batas satu flow                                                                      |
| WT-09 | Fondasi grand design mengikuti panduan pemodelan Signavio; bertambah per kemampuan sesuai kebutuhan client                               | Diminta pemilik, 7 Okt 2026                                                          |
| WT-10 | Model semantik, layout, binding, executor dipisah; ID/versi dan gate kompatibilitas dijaga                                               | Usulan teknis WT-09, dikunci di area 0A                                              |
| WT-11 | Elemen belum executable ditolak publish; tidak ada placeholder yang mengaku selesai                                                      | Pengaman rancangan, belum implementasi                                               |
| WT-12 | BPMN executable untuk Flowable; metadata/penyimpanan internal, konversi editor dan interoperability dibuktikan                           | Arah runtime mengikuti WT-16; mapping dan modeler menunggu area 0A                   |
| WT-13 | Pembandingan Flowable dan Temporal sebelum dependency produk                                                                             | Historis; diganti keputusan pemilik WT-16, bukan pekerjaan pilot aktif               |
| WT-14 | Kunci aturan satu flow, pilot sekarang, integrasi penuh setelah lulus                                                                    | Urutan direkomendasikan; tidak menunggu semua module settle                          |
| WT-15 | Satu template default teruji; salinan tenant/version tetap; alternatif sesuai kebutuhan                                                  | Arah konfigurasi client, flow pertama tetap satu                                     |
| WT-16 | Flowable adalah engine proses bisnis; tidak membuat interpreter BPMN kedua atau fallback engine lain                                     | Dipilih pemilik, 31 Okt 2026; integrasi/versi/operasi belum terbukti                 |
| WT-17 | Satu source; katalog tindakan berkode dan paket default berversi saat pembuatan tenant; penyesuaian sebagai data tenant                  | Arah produk diminta pemilik; belum implementasi provisioning/editor                  |
| WT-18 | Engine mengoordinasikan Healthcare/Procurement/Finance; transaksi, AR/AP/GL dan invariant tetap di module pemilik                        | Arah end-to-end; perluasan per flow, bukan implementasi semua module pada pilot aset |

## Cara mengerjakan

Status mengikuti [aturan backlog](/todo/): `[ ]` belum, `[~]` berjalan, `[x]` selesai dengan bukti.
Jangan mencentang test mock sebagai bukti ketahanan crash atau integrasi Flowable nyata. Setiap area
mencatat commit, versi runtime, perintah, hasil, dan lokasi bukti sebelum selesai.

Area 0–2 membuktikan kelayakan sebelum perubahan perilaku aset. Jalur engine dan module dapat
dikerjakan paralel setelah kontrak internal dibekukan; deployment dan penerimaan end-to-end tetap
berurutan. Daftar lajur tidak memberi izin membuat subagent atau mengubah file di luar scope.

Area 0A wajib sebelum desain persistensi/adapter. Model lama, template tenant, dan history
berjalan dijaga ketika kemampuan baru dikirim. Roadmap lanjut ada di [fondasi Signavio](./fondasi-signavio),
tanpa menambah flow kedua pada gate pertama.

Nama folder lama dipertahankan untuk tautan. Area 1 membuktikan Flowable, gate 1G memeriksa
kesiapan integrasinya, lalu area 3–6 mengintegrasikan engine yang sudah dipilih.

Rujukan tetap: [workflow existing](/dev/21-visual-workflow-engine),
[standar module](/dev/02-module-standard), [extensibility](/dev/05-customization-and-addons),
[feed finance](/todo/feed-posting-finance/), dan [API integrator](/todo/api-untuk-integrator/).
