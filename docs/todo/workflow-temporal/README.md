# Fondasi proses custom: pilot satu flow aset dan pemilihan engine

Rencana pilot engine dan integrasi satu proses aset, dibuat 7 Oktober 2026. Ini backlog,
bukan fitur yang sudah berjalan atau pengganti desain kanonik. Belum ada dependency engine baru
yang dipasang oleh pekerjaan ini. CoreERP belum mempunyai pelanggan aktif; kebutuhan variasi
proses berasal dari pengalaman pelanggan sistem lama.

Pemilik meminta TODO lengkap sebelum implementasi: memasang mesin eksekusi yang sudah tersedia,
lalu membuktikannya pada satu flow aset. Temporal adalah kandidat awal; kini
[Flowable dibandingkan sebelum pilihan dikunci](./pemilihan-engine). Versi, deployment,
perilaku pengguna dan batas proses diputuskan sebelum integrasi penuh.

Pemilik kemudian meminta fondasi mengacu pada panduan SAP Signavio lokal supaya kemampuan
dapat bertambah per kebutuhan client. Model proses dipisah dari editor dan executor. Ini
menambah arah desain sejak awal, bukan memperluas flow pertama menjadi seluruh Signavio.

## Isi paket

| Halaman | Tujuan |
| --- | --- |
| [PRD](./prd) | Kebutuhan, satu flow, scope, dan keputusan material |
| [Pemilihan engine](./pemilihan-engine) | Pilot Flowable vs Temporal dan gate keputusan |
| [Riset dan bukti](./riset) | Source existing, referensi Microsoft/Temporal, dan batas klaim |
| [Fondasi Signavio](./fondasi-signavio) | Pemetaan PDF SAP, model semantik, evolusi per kemampuan, dan bahan yang masih kurang |
| [Contoh XML Signavio](./contoh-xml-signavio) | Bukti export trial non-executable, batas produk SAP, dan gate importer/publish |
| [Arsitektur](./arsitektur) | Kepemilikan data, transaksi, outbox, authorization, replay, dan extensibility |
| [Pemasangan](./pemasangan) | SDK, worker, service, database, lisensi, CI, dan on-prem |
| [TODO](./TODO) | Area kerja berurutan dengan file, dependency, dan kriteria selesai |
| [Verifikasi](./verifikasi) | Test nyata, crash, retry, oracle SQL, beban, dan keputusan lanjut |

## Keputusan dan batas

| ID | Keputusan | Status |
| --- | --- | --- |
| WT-01 | Rencana awal Temporal, mulai satu flow aset | Permintaan awal; engine dibuka oleh WT-13 |
| WT-02 | Flow awal: penerimaan draft → permintaan penyelesaian → tindakan module → hasil terlihat | Usulan untuk dikunci di area 0; binding sesuai engine |
| WT-03 | Tidak menambah approval wajib atau tipe workflow baru untuk flow ini | Usulan; bukan izin mengubah semua proses |
| WT-04 | Pembuatan aset dan posting perolehan tetap satu transaksi database module | Invariant existing yang harus dipertahankan |
| WT-05 | Konsep BPMN Signavio; renderer existing digunakan bila sesuai format terpilih | WT-09/12/13; belum keputusan parser/modeler baru |
| WT-06 | Self-host pada percobaan; cara distribusi SaaS/on-prem ditentukan dari hasil pengukuran | Usulan; belum deployment wajib semua instalasi |
| WT-07 | Integrasi luar melalui API/event berkontrak, tidak diberi akses langsung ke runtime engine | Mengikuti batas extensibility CoreERP |
| WT-08 | Tidak membangun financial dimension BC, pemisahan database legal entity, procurement lengkap, atau koneksi healthcare pada pekerjaan ini | Batas satu flow |
| WT-09 | Fondasi grand design mengikuti panduan pemodelan Signavio; bertambah per kemampuan sesuai kebutuhan client | Diminta pemilik, 7 Okt 2026 |
| WT-10 | Model semantik, layout, binding, executor dipisah; ID/versi dan gate kompatibilitas dijaga | Usulan teknis WT-09, dikunci di area 0A |
| WT-11 | Elemen belum executable ditolak publish; tidak ada placeholder yang mengaku selesai | Pengaman rancangan, belum implementasi |
| WT-12 | Format awal internal atau BPMN XML, cakupan interoperability, dependency modeler | Menunggu spike area 0A; PDF tidak menetapkannya |
| WT-13 | Bandingkan Flowable dan Temporal untuk alur visual custom sebelum dependency produk | Rekomendasi pembahasan; verdict dan keputusan pemilik masih diperlukan |
| WT-14 | Kunci aturan satu flow, pilot sekarang, integrasi penuh setelah lulus | Urutan direkomendasikan; tidak menunggu semua module settle |
| WT-15 | Satu template default teruji; salinan tenant/version tetap; alternatif sesuai kebutuhan | Arah konfigurasi client, flow pertama tetap satu |

## Cara mengerjakan

Status mengikuti [aturan backlog](/todo/): `[ ]` belum, `[~]` berjalan, `[x]` selesai dengan bukti.
Jangan mencentang test mock sebagai bukti ketahanan crash atau koneksi Temporal nyata. Setiap area
mencatat commit, versi runtime, perintah, hasil, dan lokasi bukti sebelum selesai.

Area 0–2 membuktikan kelayakan sebelum perubahan perilaku aset. Jalur engine dan module dapat
dikerjakan paralel setelah kontrak internal dibekukan; deployment dan penerimaan end-to-end tetap
berurutan. Daftar lajur tidak memberi izin membuat subagent atau mengubah file di luar scope.

Area 0A wajib sebelum desain persistensi/adapter. Model lama, template tenant, dan history
berjalan dijaga ketika kemampuan baru dikirim. Roadmap lanjut ada di [fondasi Signavio](./fondasi-signavio),
tanpa menambah flow kedua pada gate pertama.

Nama folder dipertahankan untuk tautan, bukan keputusan engine. Area 1 membandingkan kandidat,
gate 1G memilih executor, lalu area 3–6 mengintegrasikan pilihan tersebut.

Rujukan tetap: [workflow existing](/dev/21-visual-workflow-engine),
[standar module](/dev/02-module-standard), [extensibility](/dev/05-customization-and-addons),
[feed finance](/todo/feed-posting-finance/), dan [API integrator](/todo/api-untuk-integrator/).
