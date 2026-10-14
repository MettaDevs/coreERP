# Gate penemuan dan keputusan module

Sebelum membuat app, master, transaksi, workflow, atau integrasi, buat proposal yang merujuk Microsoft Learn Dynamics 365. Bila tidak ada padanan resmi, nyatakan itu; jangan menciptakan klaim kesetaraan.

Proposal menetapkan pemilik data, lifecycle, scope organisasi, keamanan, nomor, workflow/SoD, dan kontrak API/event.

## Scope diputuskan per resource

Satu module dapat mempunyai identitas bersama, konfigurasi perusahaan, transaksi milik perusahaan,
dan detail yang mengikuti dokumen induk. Karena itu pertanyaan scope diajukan untuk setiap resource,
bukan satu pilihan untuk seluruh module. Pemilik kode (Foundation atau module) juga tidak menentukan
scope data: sebuah master di Foundation tetap dapat menyimpan penggunaan per perusahaan.

| Pertanyaan sebelum implementasi | Keputusan yang dicatat |
| --- | --- |
| Apakah identitas/definisi ini dipakai bersama, dimiliki perusahaan, dimiliki unit, atau mengikuti dokumen induk? | Pemilik data dan model/tabel atau relasi induknya |
| Apakah ada konsekuensi akuntansi, pajak, kontrak, ledger, atau pelaporan resmi? | Perusahaan yang bertanggung jawab; unit kerja dapat menjadi pembatas tambahan |
| Saat pengguna berhak atas A dan B tetapi bekerja di A, apakah daftar dan tindakan harus terbatas pada A? | Konteks baca/tulis biasa, terpisah dari hak akses |
| Apakah unit penanggung jawab/pengguna dan turunannya perlu membatasi akses? | Policy, field bisnis, hierarchy, dan aturan penggabungan grant |
| Apakah laporan, analitik, integrasi, atau proses antrean perlu lintas perusahaan? | Jalur yang diizinkan dan sumber konteks perusahaan tepercaya |
| Memakai rekomendasi F&O atau desain lain? | Pilihan pengguna, alasan, dampak pada relasi/nomor, dan keputusan yang masih terbuka |

Rekomendasi awal mengikuti F&O: resource milik perusahaan bekerja pada perusahaan aktif; resource
bersama tetap bersama; proses lintas perusahaan dinyatakan secara eksplisit. Ini rekomendasi desain,
**bukan klaim bahwa seluruh runtime CoreERP sudah menerapkannya**. Perilaku yang berbeda dari
rekomendasi harus dicatat dan disetujui sebelum diterapkan. Bila padanan F&O tidak ditemukan,
nyatakan itu dan putuskan dari kebutuhan bisnis resource tersebut.

Legal entity dan operating unit bukan pilihan yang selalu saling menggantikan. Transaksi dapat
dimiliki perusahaan sekaligus dibatasi unit penanggung jawab. Detail, lampiran, atau baris dokumen
mengikuti batas induknya; ketiadaan kolom perusahaan pada detail tidak menjadikannya data bersama.
Unit aktif juga tidak otomatis menyaring semua resource operasional.

Hak akses, konteks kerja, dan kepemilikan dicatat terpisah. Pilihan perusahaan tidak menambah izin,
dan grant seluruh organisasi tidak otomatis berarti semua layar bekerja lintas perusahaan.
`security.data_policies` pada manifest sekarang menyatakan dimensi akses; ia belum menjadi deklarasi
bahwa query otomatis dibatasi perusahaan aktif.

Uji keputusan dengan pengguna berhak atas A+B saat bekerja di A lalu B. Daftar, pencarian, detail,
buat/ubah/arsip/post, relasi detail, replay idempotency, laporan, ekspor, analitik, dan pekerjaan
antrean harus mengikuti keputusan yang sama pada jalur yang relevan. Data bersama tetap tersedia,
tenant lain tetap tertutup, pasangan perusahaan/unit yang tidak diizinkan ditolak, dan scope nomor
sesuai indeks unik dokumen. Pekerjaan antrean membawa perusahaan dokumen/permintaan yang tersimpan;
ia tidak mengambil pilihan browser yang mungkin sudah berubah.

Dasar Microsoft: [perilaku company dan cross-company](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/cross-company-behavior),
[pemodelan organisasi dan master bersama](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/plan-organizational-hierarchy),
dan [kebijakan keamanan data XDS](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/extensible-data-security-policies).

## Keputusan lain pada proposal

Untuk setiap **master**, proposal menjawab lebih dulu: milik Foundation atau milik module? Master yang dipakai, atau pasti akan dipakai, lebih dari satu module — atau yang padanannya di Business Central/Dynamics 365 hidup di lapis bersama — lahir di Foundation, bukan di module yang pertama memintanya. Pelanggan yang membeli satu module tidak boleh dipaksa membeli module lain demi master itu. Aturannya ada di [standar module](02-module-standard.md#master-bersama-dan-modul-yang-berdiri-sendiri).

Bagian **keamanan** pada proposal berarti rantai lengkapnya, bukan sekadar daftar hak: entry point apa yang dilindungi, permission beserta access level-nya, privilege sebagai satuan tugas, dan duty sebagai bagian proses bisnis — ditambah bentuk security role yang masuk akal disusun tenant dari duty itu. Keempat lapis pertama wajib menjadi empat baris terpisah pada manifest; Core menolak manifest yang meringkasnya. Aturan, diagram, dan checklistnya ada di [Rantai keamanan modul transaksi](19-transaction-security-chain.md), dan harus dilewati **sebelum** modul dibangun, bukan sesudah. Nomor hanya untuk master atau dokumen bisnis yang membutuhkan identitas yang dapat dibaca manusia. App mendeklarasikan reference pada manifest dan admin tenant mengaturnya pada **Atur nomor** Core.

Workflow hanya diperlukan untuk approval, exception, keputusan berisiko/irreversible, atau handoff terkontrol. SoD hanya bila pengaju tidak boleh sekaligus memverifikasi/menyetujui. Event hanya untuk fakta setelah commit yang dikonsumsi lintas app.

Prosedur detail dan template proposal ada di `.agents/skills/module-discovery/SKILL.md`.

## Lihat juga

- [Standar module](02-module-standard.md) — yang harus dipenuhi setelah proposal disetujui
- [Number sequence](14-number-sequences.md) — kapan sebuah entitas perlu nomor
- [API dan integration bridge](04-api-and-integration.md) — kontrak API dan event yang dideklarasikan proposal
- [Rantai keamanan modul transaksi](19-transaction-security-chain.md) — apa saja yang wajib ditetapkan untuk tiap transaksi
- [Identity dan access](09-identity-and-access.md) — keamanan, duty, dan SoD yang ikut diputuskan
- [Cara berkontribusi](../onboarding/kontribusi.md) — posisi gate ini dalam alur kerja harian
