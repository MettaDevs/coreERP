# PRD: pembuktian satu flow aset

Satu instance proses mewakili satu permintaan penyelesaian penerimaan aset. Ia mengoordinasikan
tindakan module, bukan menyimpan master aset atau menghitung jurnal sendiri.

Runtime belum dikunci: [pilot Flowable vs Temporal](./pemilihan-engine) berjalan sesudah aturan
flow aset diputuskan. Integrasi penuh menunggu verdict, bukan semua module settle. Uraian
activity/outbox adalah usulan awal cabang Temporal; binding akhir mengikuti engine terpilih.

Grand design mengikuti [fondasi Signavio](./fondasi-signavio). Katalog, dictionary, subprocess,
dan variasi proses dapat berkembang per kebutuhan. Model tidak bergantung pada struktur React
Flow atau SDK Temporal; flow pertama membuktikan subset eksekusi serial.

## Kebutuhan pengguna

Pengguna menyelesaikan penerimaan tanpa kehilangan pekerjaan ketika worker atau jaringan mati.
Admin dapat mengenali proses menunggu, gagal, atau selesai. Tim dapat memperluas urutan proses
kemudian tanpa menulis ulang mekanisme retry dan pemulihan. Tidak ada tuntutan untuk menambah
tahap persetujuan pada penerimaan biasa di fase ini.

## Satu flow yang diusulkan

| Tahap | Pemilik | Data/dampak |
| --- | --- | --- |
| Draft penerimaan | Module aset | Dokumen, baris, versi, legal entity, unit, nilai, dan permission existing |
| Permintaan penyelesaian | Core + module | Validasi akses, simpan intent dan versi dokumen, kunci idempoten, outbox dalam satu commit |
| Dispatch | Core | Mulai workflow dengan ID tetap; ulang pengiriman tidak membuat proses kedua |
| Penyelesaian | Activity memanggil layanan module | Periksa precondition, buat aset dan posting perolehan secara atomik |
| Hasil | Module + Core | Dokumen selesai dan hasil proses diproyeksikan ke layar |

Perubahan dari HTTP sinkron ke asynchronous adalah keputusan WT-02: respons dapat berarti
diterima untuk diproses, bukan aset sudah lahir. Tentukan status code, polling, timeout pengguna,
dan pesan sebelum implementasi. Endpoint/path baru di sini belum ditetapkan.

## Gate penemuan dan keputusan

| Kemampuan | Usulan | Alasan | Persetujuan yang masih diperlukan |
| --- | --- | --- | --- |
| Resource/lifecycle | Intent penyelesaian; lifecycle penerimaan tetap milik module | Replay tidak boleh menjalankan controller dengan Request palsu | Perilaku asynchronous dan kapan draft dikunci |
| Scope | Tenant + environment + legal entity + unit dari konteks tepercaya | Tenant saja belum menjamin koneksi database worker benar | Resolusi environment di background |
| Security/data policy | Pakai hak buat aset dan policy tanggung jawab existing | Workflow tidak memberi hak baru | Semantik pencabutan hak sebelum activity berjalan |
| Nomor | Tidak ada nomor bisnis proses baru; nomor penerimaan/aset existing | ID teknis workflow bukan dokumen bisnis | Tidak ada selama reference existing tidak berubah |
| Workflow/SoD | Tidak menambah approval wajib; pertahankan parameter SoD existing pada proses lain | Pembuktian eksekusi tidak perlu approver tambahan | Approval baru ditunda |
| REST/event/audit | Kontrak fungsi internal, outbox untuk Temporal, status untuk UI | Efek bisnis dan komunikasi bukan satu transaksi terdistribusi | Endpoint dan perubahan status API |
| Deploy | Service Temporal dan worker terpisah, source module tetap dalam repo/runtime Core | Tidak mengembalikan bentuk app lama per module | Distribusi dan lifecycle on-prem |
| Pemodelan | Identitas/semantik terpisah dari layout; schema dan capability versioned | PDF Signavio hlm. 6–17 membedakan elemen dan arti koneksi | WT-12: format dan interoperability |

Pemetaan ke BC adalah **adaptasi** workflow event/response dan automated posting, bukan menyalin
engine Microsoft. Flowable dan Temporal dibandingkan sebagai runtime, bukan fitur Microsoft
atau paket UI SAP. Rujukan ada di [riset](./riset).

## Di luar scope

- Tidak mengganti workflow dekomisioning existing atau memigrasikan instance approval yang berjalan.
- Tidak membuka SQL, PHP, URL bebas, atau credential input di editor bisnis.
- Tidak memecah database per legal entity atau memberi module koneksi ke tabel module lain.
- Tidak mengganti feed finance v1, mapping akun, pembulatan, atau rumus penyusutan.
- Tidak membuat flow kedua, parallel gateway baru, atau connector luar sebelum flow ini lolos.

Hierarchy katalog proses bukan hierarchy organisasi; lane bukan grant. Tujuan update per bagian
dibuktikan gate schema/capability/replay, bukan janji sembarang node baru selalu tanpa migration.

## Kriteria produk

Penerimaan yang diterima dapat ditelusuri ke satu instance dan satu versi proses. Retry sesudah
commit mengembalikan hasil tersimpan. Penolakan validasi tidak membuat aset. Status finance
`held` tetap berarti masalah pemetaan, bukan kegagalan penciptaan aset; `pending` bukan ack
finance. Menonaktifkan penggunaan baru tidak menghapus data atau meninggalkan proses berjalan
tanpa worker. Release penuh menunggu [verifikasi](./verifikasi), termasuk pengukuran biaya on-prem.
