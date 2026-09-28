# Mundur tanpa kehilangan data

Rencana kerja, bukan desain kanonik. Ditulis 15 September 2026 dari pertanyaan pemilik produk: bila
rilis 1.2.0 bermasalah dan server klien dikembalikan ke 1.1.0, apa yang terjadi pada migration dan
transaksi yang sudah ditulis?

Aturannya sudah kanonik di [Rilis dan on-prem](/dev/03-release-and-on-prem#perubahan-skema-dan-mundur)
dan dijaga test. Halaman ini hanya berisi **yang belum dibangun** supaya aturan itu dapat dipakai
operator dari admin.erp.

## Yang sudah berdiri

| | Tempat |
| --- | --- |
| Aturan kompatibel N-1 dan expand/contract | `docs/dev/03-release-and-on-prem.md` |
| Penjaga migration | `apps/core/tests/Feature/Boundary/MigrasiKompatibelMundurTest.php` |
| Gerbang keputusan untuk agen dan pengembang | skill `coreerp-architecture`, bagian "Schema change and rollback gate" |
| Mundur otomatis saat pembaruan gagal, termasuk memulihkan database dari cadangan sebelum migration | `scripts/update.sh` |

## Yang belum ada

| ID | Pekerjaan | Kenapa | Titik singgung |
| --- | --- | --- | --- |
| MK-01 | **Operasi "kembali ke rilis sebelumnya"** di admin.erp dan agen: menjalankan image rilis sebelumnya **tanpa** memulihkan database. Hanya ditawarkan ke rilis yang tercatat pernah sehat di situs itu | Hari ini `upgrade` menolak nomor rilis yang lebih kecil, sehingga mundur sesudah pembaruan yang sukses hanya dapat dilakukan dengan tangan di server | daftar operasi tertutup di PRD on-prem dikelola; AG-01 PRD Harbor (tarik lewat digest) |
| MK-02 | **Batas mundur yang dihitung perakit**: manifest rilis mencatat rilis tertua yang skemanya masih dipakai kode rilis ini, dan admin.erp tidak menawarkan mundur melewati batas itu | Migration `@kontrak` membuat mundur ke rilis sebelum langkah expand-nya merusak; batas itu harus diketahui mesin, bukan diingat operator | PK-02 PRD Harbor (manifest v2) |
| MK-03 | **Pembaruan bergelombang**: situs percobaan diperbarui lebih dulu, gelombang berikutnya menunggu jeda dan tidak adanya kegagalan | Bug yang lolos pemeriksaan kesehatan ketahuan di satu klinik, bukan di semuanya | jendela pembaruan per situs |
| MK-04 | **Sakelar fitur per tenant** yang dapat dimatikan dari admin.erp tanpa rilis | Cara tercepat menghentikan fitur bermasalah, dan satu-satunya yang tidak menyentuh image maupun database | lisensi yang mengunci (daftar app) adalah sakelar tingkat app; ini tingkat fitur |

## Kriteria terima

- Operator dapat mengembalikan satu situs ke rilis sebelumnya dari admin.erp, dan transaksi yang ditulis
  sesudah pembaruan tetap ada.
- admin.erp menolak mundur melewati langkah `@kontrak`, dengan pesan yang menyebut rilisnya.
- Pembaruan ke seluruh situs berhenti sendiri bila gelombang pertama gagal.
- Fitur yang dimatikan sakelarnya tidak dapat dibuka tenant, tanpa rilis baru.
