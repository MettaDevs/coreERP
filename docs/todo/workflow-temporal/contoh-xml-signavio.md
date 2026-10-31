# Pemeriksaan export XML trial Signavio

Pemilik memberikan export lokal `docs/references/Screenshot/Procurement of Work Equipment.bpmn`
dari trial pada 7 Oktober 2026. Pemeriksaan dilakukan dengan parser XML lokal dan pembacaan
halaman model trial di browser, tanpa menyimpan perubahan model atau menjalankan order/email.
File berada di folder yang diabaikan Git dan bukan dependency build/CI.

SHA-256 sumber: `3179b1d90252d69882b9bf27be39a00d4f66d2c79fb62642fe8704ecbbc67157`.
Exporter yang tercatat adalah Signavio Process Editor. Catatan ini membahas export tersebut,
bukan semua model Signavio atau jaminan fitur trial lain. Sumber tidak disalin ke repository.

## Jawaban yang terbukti

**Model ini tidak executable.** Elemen process menyimpan `isExecutable="false"` pada baris 46.
Halaman model yang dibuka di browser juga menampilkan atribut **Is executable: False**. Label
Published pada Collaboration Hub berarti model telah diterbitkan untuk dibaca, bukan workflow
bisnisnya sedang mengeksekusi pembelian.

| Yang terlihat pada model                        | Yang disimpan XML                                                   | Yang belum diberikan                                    |
| ----------------------------------------------- | ------------------------------------------------------------------- | ------------------------------------------------------- |
| Check stock, Place order, Place order by e-mail | Elemen `task` generik, nama dan documentation                       | Binding fungsi/API, input/output executable, hak pelaku |
| Cabang stok tersedia/tidak                      | XOR gateway dan nama sequence flow                                  | `conditionExpression` executable atau default branch    |
| 5 days                                          | Nama intermediate event dan `timerEventDefinition` kosong           | `timeDuration`, `timeDate`, atau `timeCycle`            |
| Receipt of work equipment                       | Message events dan message flows                                    | Skema pesan, endpoint, correlation, authentication      |
| ERP-System                                      | Text annotation bertipe IT system melalui extension dan association | Credential/API atau koneksi database                    |
| Supplier                                        | Pool peserta tanpa processRef                                       | Implementasi proses pemasok                             |
| Dictionary, posisi, warna                       | Extension Signavio, BPMN DI, bounds/waypoints                       | Resolusi dictionary tenant tujuan dan action runtime    |

Ketiadaan binding itu tidak melarang model diperkaya nanti. Tetapi mengubah atribut menjadi
true saja tetap tidak memberi durasi, kondisi, tindakan, atau akses yang belum ada. Dokumentasi
task Place order menginstruksikan pelaku memakai transaksi ERP; itu prosa model untuk manusia,
bukan perintah yang boleh dijalankan agen atau engine.

## Produk SAP yang berbeda

[Process Manager](https://www.sap.com/products/business-transformation-management/signavio-process-manager.html)
menyediakan dokumentasi, desain, validasi dan simulasi. Simulation dapat memperkirakan waktu,
biaya dan resource berdasarkan scenario; tidak menciptakan order nyata hanya dari diagram.
[Process Governance](https://help.sap.com/docs/signavio-process-governance/user-guide) adalah
produk modeling/execution workflow. Untuk memengaruhi sistem luar, tetap perlu tindakan dan
integrasi seperti [panggilan web service](https://help.sap.com/docs/signavio-process-governance/developer-guide/calling-external-web-service).

Ada batas relevan untuk pilihan CoreERP: [Developer Guide Governance](https://help.sap.com/docs/signavio-process-governance/developer-guide/intro)
menyatakan integrasi melalui Trigger API/actions, tetapi tidak menyediakan API interaksi tasks/cases
untuk menjadikannya embedded process engine aplikasi sendiri. Ini memperjelas keputusan: kita
mencontoh fondasi pemodelan Signavio, tidak memasukkan runtime Signavio ke CoreERP. Runtime
yang dipilih adalah Flowable; pilot membuktikan integrasi tindakan module sendiri.

**Keputusan pemilik, 31 Oktober 2026:** runtime proses bisnis memakai
[Flowable](./pemilihan-engine). Temuan XML non-executable dan kebutuhan binding tetap berlaku;
pilihan engine tidak otomatis membuat export trial dapat dijalankan.

## Konsekuensi untuk importer dan publish CoreERP

- Import awal adalah **model deskriptif**, bukan izin execute. Tampilkan loss/capability report.
- Pertahankan identitas, koneksi, reference, DI/layout dan extension yang bisa dipertahankan;
  laporkan extension yang belum dipahami. Jangan menerjemahkan annotation ERP menjadi connector.
- Export ini mempunyai XOR, event-based gateway, timer, message, dan association. Ia di luar
  subset serial flow aset pertama; publish executable harus ditolak dengan alasan per elemen.
- Label `5 days` tidak diparse menjadi durasi otomatis. Kondisi tidak diambil dari nama panah.
- Untuk executable plan, konsultan/developer memetakan task ke tindakan terdaftar, menyediakan
  typed inputs/outputs, kondisi/timer/correlation dan scope, lalu melakukan validasi dan publish versi baru.
- Kalau mode deskriptif belum diimplementasikan, penolakan import harus menyebut unsupported
  capability, bukan menghasilkan graph serial yang kehilangan cabang.
- CI tidak bergantung file trial lokal. Buat fixture sintetis sendiri dari jenis struktur ini,
  bukan menyalin template SAP beserta dictionary/identitas workspace dan menganggap hak distribusi ada.

## Bahan yang sudah dan belum tersedia

Contoh **BPMN XML deskriptif** sudah tersedia lokal dan menggantikan dugaan tentang isi export.
Belum tersedia contoh minimal serial executable dengan binding nyata, contoh AND join, linked
subprocess, serta lifecycle case Governance yang terhubung sistem uji. Tidak perlu membuat semua
ini pada fase aset pertama. Bahan itu diminta ketika gate capability bersangkutan dimulai.

Model trial menunjukkan revision, dictionary, RACI/Activities, Fact Sheet dan variants; menu itu
berguna sebagai referensi product design, bukan bukti bahwa variant mengubah kode ERP. Pemeriksaan
ini belum menguji eksekusi Process Governance pada account trial pemilik.
