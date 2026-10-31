# PRD: pembuktian satu flow aset

Satu instance proses mewakili satu permintaan penyelesaian penerimaan aset. Ia mengoordinasikan
tindakan module, bukan menyimpan master aset atau menghitung jurnal sendiri.

Runtime sudah dipilih pemilik: [Flowable](./pemilihan-engine), 31 Oktober 2026. Pilot berjalan
sesudah aturan flow aset diputuskan. Integrasi penuh menunggu bukti versi, adapter dan recovery,
bukan pemilihan ulang engine atau semua module settle. Dokumen ini masih rencana implementasi.

Grand design mengikuti [fondasi Signavio](./fondasi-signavio). Katalog, dictionary, subprocess,
dan variasi proses dapat berkembang per kebutuhan. Model tidak bergantung pada struktur React
Flow atau API engine; flow pertama membuktikan subset BPMN executable serial pada Flowable.

## Arah produk lintas module

Satu source melayani variasi tenant. Developer mendaftarkan tindakan bisnis, input/output,
permission, versi dan batas transaksi sekali. Operator admin.erp memilih paket default saat
membuat tenant; Core menerapkan konfigurasi melalui jalur yang diaudit setelah module terpasang.
Admin yang berwenang mengubah urutan, kondisi, tugas dan loket yang didukung melalui editor dan
tabel aturan. Publish menghasilkan versi baru; default produk tidak menimpa salinan tenant.

Healthcare memiliki pelayanan/tagihan/pembayaran, Procurement memiliki permintaan/pesanan/
penerimaan, dan Finance memiliki AR/AP, alokasi, posting profile serta GL. Engine mengatur
urutan dan penantian hasil. Pembayaran, penyerahan obat, pengakuan, posting jurnal dan tutup buku
tetap fakta berbeda. Keputusan engine ini tidak menjadikan integrasi semua module scope pilot aset.
Dasar pemeriksaan source ada di [riset sistem lama](./riset#pemeriksaan-sistem-lama-31-oktober-2026).

## Kebutuhan pengguna

Pengguna menyelesaikan penerimaan tanpa kehilangan pekerjaan ketika worker atau jaringan mati.
Admin dapat mengenali proses menunggu, gagal, atau selesai. Tim dapat memperluas urutan proses
kemudian tanpa menulis ulang mekanisme retry dan pemulihan. Tidak ada tuntutan untuk menambah
tahap persetujuan pada penerimaan biasa di fase ini.

## Satu flow yang diusulkan

| Tahap                   | Pemilik                             | Data/dampak                                                                                |
| ----------------------- | ----------------------------------- | ------------------------------------------------------------------------------------------ |
| Draft penerimaan        | Module aset                         | Dokumen, baris, versi, legal entity, unit, nilai, dan permission existing                  |
| Permintaan penyelesaian | Core + module                       | Validasi akses, simpan intent dan versi dokumen, kunci idempoten, outbox dalam satu commit |
| Dispatch                | Adapter Core                        | Mulai versi definisi Flowable terikat operasi; deduplikasi start harus dibuktikan          |
| Penyelesaian            | Worker PHP memanggil layanan module | Periksa precondition, buat aset dan posting perolehan secara atomik                        |
| Hasil                   | Module + Core                       | Dokumen selesai dan hasil proses diproyeksikan ke layar                                    |

Perubahan dari HTTP sinkron ke asynchronous adalah keputusan WT-02: respons dapat berarti
diterima untuk diproses, bukan aset sudah lahir. Tentukan status code, polling, timeout pengguna,
dan pesan sebelum implementasi. Endpoint/path baru di sini belum ditetapkan.

## Gate penemuan dan keputusan

| Kemampuan            | Usulan                                                                               | Alasan                                                        | Persetujuan yang masih diperlukan                    |
| -------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------- | ---------------------------------------------------- |
| Resource/lifecycle   | Intent penyelesaian; lifecycle penerimaan tetap milik module                         | Retry tidak menjalankan controller dengan Request palsu       | Perilaku asynchronous dan kapan draft dikunci        |
| Scope                | Tenant + environment + legal entity + unit dari konteks tepercaya                    | Tenant saja belum menjamin koneksi database worker benar      | Resolusi environment di background                   |
| Security/data policy | Pakai hak buat aset dan policy tanggung jawab existing                               | Workflow tidak memberi hak baru                               | Semantik pencabutan hak sebelum job berjalan         |
| Nomor                | Tidak ada nomor bisnis proses baru; nomor penerimaan/aset existing                   | ID teknis workflow bukan dokumen bisnis                       | Tidak ada selama reference existing tidak berubah    |
| Workflow/SoD         | Tidak menambah approval wajib; pertahankan parameter SoD existing pada proses lain   | Pembuktian eksekusi tidak perlu approver tambahan             | Approval baru ditunda                                |
| REST/event/audit     | Kontrak fungsi internal, outbox untuk Flowable, status untuk UI                      | Efek bisnis dan komunikasi bukan satu transaksi terdistribusi | Endpoint, deduplikasi start dan perubahan status API |
| Deploy               | Engine Java dan worker PHP sebagai layanan platform; module tetap dalam runtime Core | Tidak mengembalikan bentuk app lama per module                | Versi/distribusi Flowable dan lifecycle on-prem      |
| Pemodelan            | BPMN executable; metadata/layout dan binding berversi                                | Model visual perlu pemetaan ke tindakan terdaftar             | WT-12: konversi editor dan interoperability          |

Pemetaan ke BC adalah **adaptasi** workflow event/response dan automated posting, bukan menyalin
engine Microsoft. Flowable adalah pilihan runtime CoreERP, bukan fitur Microsoft atau paket UI
SAP. Rujukan ada di [riset](./riset).

## Di luar scope

- Tidak mengganti workflow dekomisioning existing atau memigrasikan instance approval yang berjalan.
- Tidak membuka SQL, PHP, URL bebas, atau credential input di editor bisnis.
- Tidak memecah database per legal entity atau memberi module koneksi ke tabel module lain.
- Tidak mengganti feed finance v1, mapping akun, pembulatan, atau rumus penyusutan.
- Tidak membuat flow kedua, parallel gateway baru, atau connector luar sebelum flow ini lolos.

Hierarchy katalog proses bukan hierarchy organisasi; lane bukan grant. Tujuan update per bagian
dibuktikan gate schema/capability/versi instance, bukan janji semua node baru tanpa migration.

## Kriteria produk

Penerimaan yang diterima dapat ditelusuri ke satu instance dan satu versi proses. Retry sesudah
commit mengembalikan hasil tersimpan. Penolakan validasi tidak membuat aset. Status finance
`held` tetap berarti masalah pemetaan, bukan kegagalan penciptaan aset; `pending` bukan ack
finance. Menonaktifkan penggunaan baru tidak menghapus data atau meninggalkan proses berjalan
tanpa worker. Release penuh menunggu [verifikasi](./verifikasi), termasuk pengukuran biaya on-prem.
