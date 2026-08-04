# Peta app dan kemampuan Core

Audit menyebut 52 nama app, tetapi banyak yang merupakan nama berbeda untuk hal
yang sama — `core-finance-gl`, `finance-general-ledger`, dan `finance-gl`
misalnya. Dokumen ini menggabungkannya menjadi satu peta, dan memisahkan mana
yang **kemampuan Core** dan mana yang **app bisnis dengan repository sendiri**.

Pemisahnya satu pertanyaan: *apakah ini policy dan koordinasi lintas tenant, atau
data bisnis milik satu domain?* Yang pertama milik Core, yang kedua milik app.

## Kemampuan Core

Hidup di repository platform ini. Bukan release unit tersendiri.

| Kemampuan | Keadaan | Kenapa Core |
| --- | --- | --- |
| Number sequence | **Ada** | Nomor dokumen lintas app, counter harus punya satu writer |
| Fiscal calendar | **Ada** | Konsekuensi legal entity, dipakai semua app finansial |
| Organization directory + hierarchy | **Ada** | Identitas organisasi dipakai setiap app sebagai reference opaque |
| Security metadata + role assignment | **Ada** | Satu sumber identity dan hak untuk semua app |
| Contract authorization untuk app | Belum | App tidak boleh menebak hak; ia harus bertanya |
| Event backbone: outbox, broker, inbox | Belum | Satu-satunya jalur integrasi yang sah antar-app |
| Workflow dan approval engine | Belum | Approval melintasi banyak app; mesinnya tidak boleh diduplikasi |
| Document management dan attachment | Belum | Lampiran melekat pada record app mana pun |
| Batch dan recurring job | Belum | Penjadwalan yang terlihat tenant, bukan queue internal |
| Notifikasi dan alert | Belum | Saluran ke pengguna dipakai bersama |
| Data management: import dan export | Belum | Setiap app perlu onboarding data massal |
| Print dan report framework | Belum | Format dokumen resmi dan statutory |
| Reference data: currency, UoM, country, format alamat | Belum | Dipakai setiap app; tidak boleh ada dua daftar satuan |
| Feature management per tenant | Belum | Menyalakan kemampuan tanpa rilis ulang |
| Gateway, rate limit, contract registry | Belum | Governance API lintas app |
| Observability dan metering | Belum | Korelasi lintas app dan penagihan |

### Yang statusnya belum diputuskan

**Party dan global address book.** D365 menaruhnya di platform: legal entity,
operating unit, team, customer, vendor, worker, dan contact semuanya party dalam
satu address book. Tetapi ia juga jelas-jelas data bisnis. Pilihannya:

- **Core memegangnya** — konsisten dengan D365, satu alamat untuk semua app,
  tetapi Core jadi menyimpan data bisnis.
- **App `party-master` tersendiri** — konsisten dengan aturanmu, tetapi setiap app
  harus menyimpan projection party lokal lewat event.

Rekomendasi saya: **app `party-master` tersendiri**, dengan Core hanya menyimpan
kaitan organisasi ke party. Alasannya, alamat dan kontak akan tumbuh menjadi
domain penuh (alamat berjangka waktu, purpose alamat, hierarki party), dan itu
bukan sifat Control Plane. Tapi ini keputusanmu.

## App bisnis

Satu repository per app, pola `app-erp-<app-key>`.

### Gelombang 1

| App | Isi | Tidak memiliki |
| --- | --- | --- |
| `party-master` | Party, tipe party, alamat berjangka waktu beserta purpose-nya, informasi kontak, format alamat per negara | Vendor dan customer sebagai peran bisnis — itu milik app masing-masing |
| `pim` | Produk, released product, varian dan dimensi produk, item group, atribut produk, kategori procurement, konversi satuan spesifik produk | Harga beli dan jual, stok |

### Gelombang 3

| App | Isi | Tidak memiliki |
| --- | --- | --- |
| `management-aset` | Record aset, tipe aset, functional location berhierarki, lifecycle model dan state, atribut, counter dan pembacaan, spare part, maintenance job type, checklist, maintenance plan dan round, maintenance request, work order beserta job line dan lifecycle, fault registration, downtime, KPI | Nilai buku dan penyusutan — itu `fixed-assets`. Pembelian spare part — itu `procurement` |
| `hrd` | Worker, job, position, worker-position assignment, position hierarchy type, position relationship | Security role dan permission — itu Core |
| `inventory` | Site, warehouse, lokasi dan bin, on-hand, pergerakan stok, penerimaan, inspeksi kualitas | Costing akuntansi — itu `finance-gl` |

### Gelombang 4

| App | Isi | Tidak memiliki |
| --- | --- | --- |
| `finance-gl` | Chart of accounts, main account, ledger, journal dan voucher, posting profile, financial dimension dan dimension set, account structure, period close, year-end, **konsolidasi dan elimination rule** | Dokumen sumber milik app lain |
| `fixed-assets` | Asset book, value model, depreciation profile, akuisisi, penyusutan, revaluasi, disposal, low-value pool | Data teknis aset — itu `management-aset` |
| `tax-id` | Faktur pajak, NPWP, PPN, PPh 21/22/23, e-Faktur/Coretax, e-Bupot | Posting ledger |
| `budget` | Budget register entry, budget control, budget check | Ledger |

### Gelombang 5

| App | Isi | Tidak memiliki |
| --- | --- | --- |
| `procurement` | Vendor sebagai peran bisnis, vendor group, purchasing policy, purchase requisition, RFQ dan bid, purchase order dan versinya, purchase agreement, trade agreement, product receipt | Party dan alamat vendor, produk, stok, ledger, pajak |
| `accounts-payable` | Vendor invoice, invoice matching dua dan tiga arah beserta toleransi, invoice pool, prepayment, retur dan credit note | Pembayaran bank |

### Kemudian

`accounts-receivable`, `sales`, `project-accounting`, `cost-accounting`,
`cash-bank-management`, `production`.

## Kenapa Procurement tidak bisa jadi app kedua

D365 Procurement adalah modul dengan dependensi terbanyak di seluruh F&O. Satu
purchase requisition menyentuh: vendor, produk, kategori procurement, UoM,
currency, pajak, financial dimension, budget control, workflow approval,
purchasing policy yang di-scope hierarchy, site pengiriman, dan akhirnya ledger.

Dari daftar itu, **tidak satu pun sudah ada.** Itu sebabnya repositorinya masih
kosong dan contract-nya masih `/health` plus `hello`.

Yang bisa dikerjakan lebih dulu tanpa menunggu apa pun: `management-aset` terus
tumbuh di jalur master data dan planning — functional location, hierarki aset,
atribut, counter, maintenance plan. Semuanya tidak menyentuh uang maupun approval.
