# Dokumen siklus aset

Halaman ini untuk developer. Isinya cara aset dihentikan pemakaiannya dan dilepas.

Ada empat jenis dokumen, semuanya tersimpan di `tr_dokumen_siklus_aset` dan memakai controller yang sama:

| Dokumen | Gunanya |
| --- | --- |
| `permintaan-pembelian-aset` | Usulan pengadaan |
| `dekomisioning-aset` | Usulan menghentikan pemakaian |
| `penjualan-aset` | Melepas dengan cara dijual |
| `pemusnahan-aset` | Melepas dengan cara dimusnahkan |

## Urutannya tidak bisa dilompati

```
in_use ──[dekomisioning disetujui]──> decommissioned ──[dijual / dimusnahkan]──> disposed
```

Aturan yang menjaganya:

- Dokumen dekomisioning **ditolak** kalau asetnya sudah `decommissioned` atau `disposed`.
- Dokumen penjualan dan pemusnahan **ditolak** kalau asetnya belum `decommissioned`.

Jadi tidak ada jalan menjual aset yang persetujuan penghentiannya belum keluar.

## Persetujuan datang dari Core, bukan dari sini

Dokumen dekomisioning tidak menyetujui dirinya sendiri. Saat dibuat, app mengajukannya ke workflow milik Core lewat kontrak `MesinWorkflow`, lalu menunggu.

Core menjalankan alur persetujuan yang dikonfigurasi admin tenant — siapa approver-nya, berapa tahap, dan sebagainya — lalu memancarkan keputusannya sebagai event `KeputusanWorkflowDiambil`. Amplop `core.workflow.decision.v2` tetap ditulis ke outbox untuk penerima yang berada di luar proses.

Yang perlu dipahami saat menulis kode di sini:

- App **tidak tahu dan tidak boleh tahu** siapa approver-nya. Itu urusan Core.
- Keputusan bisa datang **berhari-hari kemudian**. Karena itu id korelasi disimpan pada dokumen sejak awal — membacanya dari permintaan yang sedang berjalan tidak mungkin, karena permintaan itu sudah lama selesai.
- Pengaju **tidak bisa menyetujui dokumennya sendiri**. Yang menegakkannya Core, dan itu berlaku karena pengajunya benar-benar dicatat saat pengajuan.
- Keputusan yang sama tidak diterapkan dua kali: `aset_processed_core_events` menyimpan id event yang sudah diproses.

Setelah `approved` diterima, aset menjadi `decommissioned`.

## Aturan yang dijaga

**Entitas dan unit kerja dokumen harus sama dengan asetnya.** Dokumen yang mengaku milik badan hukum lain daripada asetnya ditolak. Ini menutup jalan memindahkan aset antar badan hukum lewat pintu belakang.

**Pembuatan dokumen idempoten.** Sama seperti master: `Idempotency-Key` wajib, kunci yang sama mengembalikan dokumen yang sama.

**Dokumen dekomisioning dan pengajuannya lahir bersama-sama.** Keduanya satu transaksi: kalau pengajuannya gagal, dokumennya tidak jadi dibuat dan nomornya tidak jadi terbit. Pengulangan pengiriman tetap ada untuk dokumen lama yang sempat tersimpan tanpa instance, dari masa pengajuan masih berjalan di luar transaksi.

**Aset menjadi `disposed` hanya di langkah pelepasan.** Membuat dokumen penjualan tidak langsung membuat asetnya lepas; statusnya berubah saat pelepasan benar-benar dicatat.

## Yang belum dirancang

`permintaan-pembelian-aset` rutenya sudah ada tetapi isinya belum dikerjakan. Perilaku yang akan dijanjikannya belum diputuskan, jadi tiga endpoint-nya sengaja **belum masuk kontrak**. Celah itu tercatat di daftar `DEFERRED` pada `contracts/check-contract-coverage.py` dan dicetak tiap kali pemeriksa jalan — supaya ia tidak terlupa, bukan supaya ia dimaafkan.

Jangan menulis kontraknya sebelum perilakunya diputuskan; kontrak yang mendahului keputusan menggambarkan bentuk yang tidak bisa diandalkan pemanggil.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Http/Controllers/transaksi/DokumenSiklusAset/DokumenSiklusAsetController.php` | Keempat jenis dokumen |
| `src/Listeners/TerapkanKeputusanDekomisioning.php` | Penerapan keputusan workflow |
| `src/Services/PersetujuanAset.php` | Pengajuan ke Core lewat kontrak |
| `api/app/Http/Controllers/transaksi/PermintaanPengadaanAset/PermintaanPengadaanAsetController.php` | Permintaan pembelian — rutenya ada, isinya belum dikerjakan |
| `contracts/asyncapi.yaml` | Kontrak event yang diterima |
| `ui/src/transactions/_shared/LifecycleDocumentPage.tsx` | Layar, satu untuk semua jenis |

## Halaman terkait

- [Register aset](/apps/management-aset/transaction/register-aset/) — status hidup aset
- [API dan integrasi](/dev/04-api-and-integration) — bentuk event dan tanda tangannya
- [Identity dan access](/dev/09-identity-and-access) — permission
