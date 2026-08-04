# Contoh SoD: dekomisioning dan pemusnahan aset

Dokumen ini adalah contoh yang dapat dipakai untuk menjelaskan kontrol ke holding. Ini membedakan **yang sudah tersedia** dari **target yang belum ada**.

## Yang tersedia sekarang

Manifest `management-aset` sekarang mendeklarasikan alur dekomisioning berikut:

| Lapisan | Isi yang sudah ada |
| --- | --- |
| Entry point | form/API dekomisioning; keputusan diterima lewat endpoint internal event |
| Permission | lihat dan buat dekomisioning, serta `Verifikasi usulan dekomisioning aset` (`invoke`) |
| Privilege | `Kelola dekomisioning aset` dan `Verifikasi dekomisioning aset` |
| Duty | `Kelola dekomisioning aset` dan `Verifikasi dekomisioning aset` |
| Batas data | `management-aset.asset-responsibility`: badan hukum + unit penanggung jawab aset |

Artinya: petugas dapat membuat usulan; keputusan verifikator dilakukan dari inbox workflow Core. Aset menerima hasilnya sebagai event bertanda tangan dan tidak menyediakan endpoint keputusan yang dapat dipanggil pengguna.

## Keputusan target

Dekomisioning adalah keputusan operasional bahwa aset tidak lagi dipakai. Pemusnahan adalah salah satu tindak lanjut fisiknya. Keduanya bukan penjualan, scrap, atau penghapusan nilai buku.

| Pihak | Tugas | Hak yang diperlukan | Hasil |
| --- | --- | --- | --- |
| Pengaju | Mengajukan dekomisioning/pemusnahan, alasan, tanggal, kondisi, dan bukti | `Buat usulan pemusnahan aset` | Status `diajukan` |
| Verifikator | Memeriksa bukti dan memutuskan aset berhenti digunakan secara operasional | Permission `verifikasi` baru | Status `diverifikasi` atau `ditolak` |
| Fixed Assets / Finance | Menghapus nilai buku dan membuat posting keuangan bila proses bisnis memerlukannya | Bukan permission Management Aset | Nilai buku dan jurnal diproses di app Finance yang kelak tersedia |

Satu pekerja tidak boleh menjadi pengaju dan verifikator untuk proses yang sama tanpa mitigasi yang disetujui dan tercatat.

## Rantai akses target

```text
Entry point API verifikasi
  -> Permission: Verifikasi usulan pemusnahan (invoke)
  -> Privilege: Verifikasi pemusnahan aset
  -> Duty: Verifikasi dekomisioning aset
  -> Security role: disusun admin tenant
  -> Assignment orang + batas data aset
```

Kode final hanya ditambahkan ke manifest Management Aset setelah resource dan aksi ini diimplementasikan. Core tidak boleh menebak atau membuat duty aplikasi secara hard-code.

## Urutan implementasi

1. Selesai: usulan dekomisioning menyimpan referensi aset serta status `submitted`.
2. Selesai: entry point, permission, privilege, duty, workflow type, nomor `DKMA`, endpoint penerima event, dan inbox idempoten tersedia.
3. Berikutnya: admin tenant membuat rule SoD untuk duty pengaju dan verifier. Core sudah menolak assignment manual yang memegang kedua duty efektif; layar rule, mitigasi, dan audit masih belum diimplementasikan.
4. Selesai: workflow Core memutuskan `approved` atau `rejected`; saat `approved`, Aset otomatis menjadi `decommissioned` dan baru boleh dijual atau dimusnahkan.

## Referensi

- [Dynamics 365 — Dispose of assets](https://learn.microsoft.com/en-us/dynamics365/guidance/business-processes/acquire-to-dispose-retire-dispose-assets)
- [Dynamics 365 — Segregation of duties](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/set-up-segregation-duties)
