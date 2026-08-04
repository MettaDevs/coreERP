# Ringkasan: seberapa kokoh fondasinya, dan apa urutannya

## Vonis

**Desainnya kuat; permukaan yang benar-benar terbangun masih kecil.**

Dokumen kanonikmu sudah memilih model yang tepat dan konsisten — organization
directory dengan hierarchy berpurpose dan berversi, security berbasis tanggung
jawab, empat kebenaran lifecycle yang terpisah. Itu bukan hal biasa; kebanyakan
proyek ERP salah di lapisan ini dan baru sadar setelah bertahun-tahun.

Masalahnya bukan arah, melainkan jarak antara dokumen dan kode. Dari 227 temuan
yang lolos verifikasi, mayoritas berbentuk sama: **sesuatu yang sudah diputuskan
dengan benar di `docs/dev`, tetapi belum ada di migration atau kode.**

## Skor per dimensi

| Dimensi | Skor | Ringkas |
| --- | --- | --- |
| Organisasi | 5/10 | Bentuknya benar; hampir semua yang menggantung padanya belum ada |
| Keamanan dan akses | 4/10 | Rantai role→duty→privilege→permission nyata, tetapi app tidak bisa bertanya hak, dan organization scope hampir tidak ditegakkan |
| Lifecycle dan deployment | 3/10 | Empat kebenaran dipisah dengan benar, tetapi worker deployment belum bisa jalan di environment mana pun yang dihasilkan repo ini |
| Fondasi finansial | 3/10 | Number sequence dan fiscal calendar sangat kuat; selebihnya kosong |
| Layanan platform | 1/10 | Workflow, dokumen, event, batch, data management — semuanya nol |
| App Management Aset | 2/10 | Baru master data klasifikasi; belum ada record aset itu sendiri |
| App Procurement | 1/10 | Belum ada repo, belum ada apa pun |

**Keseluruhan: sekitar 3/10 terhadap baseline Dynamics 365 F&O.**

## Yang benar-benar kokoh

Ini bukan basa-basi — bagian ini lebih baik daripada kebanyakan ERP komersial:

- **Number sequence.** Continuous dan non-continuous, preallocation durable milik
  Control Plane, pool continuous dengan `FOR UPDATE SKIP LOCKED`, reset fiskal,
  rekonsiliasi lewat outbox app, dan uji konkurensi dua koneksi di PostgreSQL
  sungguhan. Dokumennya bahkan mencatat kenapa SQLite tidak boleh dipakai menguji
  ini. Satu-satunya cacat yang ditemukan: penerbitan nomor tidak menerima tanggal
  dokumen, sehingga resolusi periode fiskal selalu memakai `now()` (`MISS-FIN-01`).
- **Pemisahan katalog, entitlement, installation, dan readiness.** Empat fakta,
  empat sumber kebenaran, dan launcher yang membaca registry.
- **Organization directory dan hierarchy berversi.** Identitas dulu, penempatan
  belakangan; closure per versi; purpose many-to-many.
- **Isolasi tenant di app.** Management Aset memakai composite foreign key
  `(tenant_id, id)`, jadi induk lintas tenant ditolak database, bukan hanya service.

## Jalur kritis

Urutan ini bukan daftar keinginan. Setiap langkah membuka langkah berikutnya, dan
melewatinya berarti menulis ulang data nanti.

| # | Kemampuan | Kenapa ia menggerbangi yang berikutnya |
| --- | --- | --- |
| 1 | Deployment yang benar-benar jalan ujung ke ujung | Selama worker tidak bisa memasang app mana pun, tidak ada klaim lifecycle yang bisa dibuktikan. Ini juga menghapus state `ready` palsu yang sekarang ditulis migration. |
| 2 | Contract authorization untuk app + penegakan organization scope | Tanpa ini app tidak bisa bertanya "boleh tidak user ini pada organisasi itu", dan setiap query daftar di setiap app nanti harus ditulis ulang saat scope ditegakkan. |
| 3 | Event backbone (outbox, broker, inbox) | Aturanmu melarang query lintas database app. Event adalah **satu-satunya** jalur integrasi yang sah, dan ia belum ada. Semua app setelah ini bergantung padanya. |
| 4 | Party dan address book | Vendor, customer, worker, dan lokasi aset semuanya party. Kalau tiga app terlanjur bikin model alamat sendiri, memperbaikinya berarti migrasi tiga database sekaligus. |
| 5 | Reference data: currency, UoM, unit conversion | Setiap field uang dan kuantitas di setiap app. Menambahkannya belakangan berarti mengubah tipe kolom transaksi yang sudah terisi. |
| 6 | Atribut legal entity + penanda konsolidasi/eliminasi | Larangan jurnal harian pada legal entity konsolidasi tidak dapat ditegakkan surut setelah ada transaksi. |
| 7 | Workflow dan approval engine | Dokumen Procurement tidak punya arti tanpa approval. Menambahkannya belakangan mengubah bentuk status setiap tabel transaksi. |
| 8 | Document management dan attachment | Foto dan sertifikat aset, kontrak dan penawaran vendor. |
| 9 | Financial dimension | Dipakai lintas app, dan ikut ke setiap baris transaksi. |
| 10 | Workforce: worker, job, position | Dibutuhkan Asset (custodian, teknisi) dan Procurement (pemohon, approver), serta automatic role assignment. |

## Gelombang

Setiap gelombang punya kriteria selesai berupa **bukti yang bisa dijalankan**,
bukan kolom status.

### Gelombang 0 — jujurkan platformnya

Deployment jalan ujung ke ujung, manifest benar-benar dibaca Core, state `ready`
palsu dihapus, contract authorization untuk app, organization scope ditegakkan.

*Selesai bila:* satu app terpasang lewat worker dari artifact hasil CI, health
check nyata, dan launcher menampilkannya karena registry — bukan karena
migration yang menuliskan `ready`.

### Gelombang 1 — substrat bersama

Party dan address, currency, UoM, reference data, atribut legal entity termasuk
penanda konsolidasi, event backbone.

*Selesai bila:* dua app bertukar satu fakta bisnis lewat event, dan satu vendor
serta satu customer dapat ditelusuri ke party yang sama.

### Gelombang 2 — layanan platform

Workflow dan approval, document management, batch dan recurring job, notifikasi,
data import.

*Selesai bila:* satu dokumen melewati approval nyata dengan delegasi, eskalasi,
dan riwayat yang tersimpan.

### Gelombang 3 — Management Aset tumbuh

Workforce, working-time calendar, functional location, record aset, atribut,
counter, maintenance plan, lalu work order.

*Catatan:* sebagian besar master data dan planning aset **tidak menunggu**
gelombang 1 dan 2. Functional location, hierarki aset, atribut, dan counter bisa
dikerjakan paralel karena tidak menyentuh uang maupun approval.

### Gelombang 4 — Finance

Chart of accounts, ledger, financial dimension, pajak dan lokalisasi Indonesia,
fixed asset, budget.

### Gelombang 5 — Procurement

Baru di sini Procurement punya pijakan: vendor, produk, UoM, currency, pajak,
budget, workflow, dan penerimaan barang semuanya sudah ada.

## Yang harus kamu putuskan

Beberapa hal tidak bisa saya putuskan sendiri dan akan mengubah rencana:

1. **Party dan address itu Core atau app tersendiri?** D365 menaruhnya di platform,
   tetapi aturanmu bilang Core memegang policy dan app memegang data bisnis.
   Audit sendiri tidak konsisten — ia mengusulkan `core-party`, `party-master`,
   dan `global-address-book` sekaligus.
2. **Finance/GL masuk lingkup atau tidak?** Kalau tidak, Procurement berhenti di
   penerimaan barang dan tidak pernah sampai ke faktur.
3. **Satu negara atau banyak negara?** Kalau hanya Indonesia, lokalisasi pajak
   boleh menyatu dengan Finance. Kalau tidak, ia harus jadi feature pack sejak awal.
4. **On-prem kapan?** Ia mengubah cara release dan lisensi dirancang, bukan
   sekadar target deploy tambahan.
5. **Pemecahan permission dan duty per resource** untuk setiap app baru, mengikuti
   gate keputusan pada skill `coreerp-architecture`.

## Risiko struktural terbesar

**Workflow dan master data bersama melawan aturan "tanpa database lintas app".**
Workflow engine harus melihat dokumen milik banyak app, dan master data bersama
harus dibaca semua app — sementara aturannya melarang query lintas database.
Jawabannya harus ditetapkan sekali di awal (kemungkinan besar: Core memegang
state workflow dan mesin approval, app memegang dokumennya dan hanya menerima
callback; master data bersama dimiliki satu pemilik dan disebarkan lewat event ke
projection lokal tiap app). Kalau ini tidak diputuskan sebelum gelombang 2, setiap
app akan menyelesaikannya dengan caranya sendiri.
