# Penyusutan dan bridge backoffice eksternal

## [~] Model penyusutan di Management Aset

Management Aset memiliki depreciation profile dan asset book/value model.
Profile menentukan metode, frekuensi, kalender/tahun penyusutan, konvensi awal,
masa manfaat, nilai residu, serta parameter metode. V1 mendukung:

- straight-line service life;
- reducing balance;
- manual schedule;
- consumption, hanya bila pembacaan pemakaian dan satuannya tersedia.

Book menyimpan nilai perolehan, akumulasi penyusutan, NBV, profil yang dipakai,
dan jadwal per periode. Satu aset dapat mempunyai lebih dari satu book bila
kebutuhan pelaporan mengharuskannya; setiap book tetap merupakan lifecycle nilai
yang independen.

## [~] Proposal, finalisasi, dan koreksi

1. Job periodik membangun proposal penyusutan dari book/profile dan kalender
   fiskal legal entity.
2. Pengguna meninjau nilai dan exception.
3. Bila tenant mengaktifkan workflow type finalisasi penyusutan, proposal
   menunggu hasil workflow; bila tidak, jalur finalisasi tanpa approval dicatat.
4. Finalisasi membuat baris periode immutable serta `depreciation_posting_export`
   immutable dengan `posting_id` stabil.
5. Koreksi setelah final memakai adjustment atau reversal baru yang merujuk baris
   asal; baris final lama tidak diedit atau dihapus.

Perhitungan memakai unit pengguna yang berlaku pada tanggal/periode penyusutan,
bukan unit penerima atau PIC. Perubahan unit di tengah periode harus memiliki
aturan alokasi yang dipilih tenant sebelum rilis: satu unit pada akhir periode
atau prorata berdasarkan tanggal efektif. Jangan menetapkan salah satunya diam-diam.

## [ ] Kontrak ke backoffice

> **Digantikan** oleh [Feed posting finance](/todo/feed-posting-finance/) (21 September 2026).
> Keputusan di bawah, bahwa V1 tidak mengirim debit, kredit, maupun akun, sudah dibalik di sana
> (K-04). Bagian ini dibiarkan sebagai riwayat.

Export memuat `posting_id`, versi kontrak, tenant, legal entity, asset dan book,
periode/tanggal, unit pengguna efektif, nilai penyusutan, currency, nilai
perolehan, akumulasi, NBV, status, dan referensi correction/reversal bila ada.

V1 tidak mengirim atau menyimpan debit, kredit, main account, COA, financial
dimension combination, maupun nomor jurnal. Metode transport belum dikunci;
kontrak harus kompatibel untuk API pull, webhook/event, atau push adapter di
fase integrasi. Saat adapter dipilih, acknowledgement idempoten dari backoffice
wajib menyimpan external journal/reference dan status rekonsiliasi.

Rujukan Dynamics: book melacak lifecycle nilai secara independen; depreciation
profile menentukan metode dan frekuensi. Lihat `FIN-30` pada
`docs/todo/general/05-fondasi-finansial.md` untuk gap platform yang masih
memblokir bagian tertentu.
