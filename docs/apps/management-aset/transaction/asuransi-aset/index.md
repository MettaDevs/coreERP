# Asuransi aset

Asuransi aset mencatat **polis dari perusahaan asuransi dan aset yang ditanggungnya, berapa nilainya, dan untuk periode kapan**. Dari situ layar menjawab dua pertanyaan: berapa nilai yang ditanggung sebuah aset pada satu tanggal, dan aset mana yang belum atau kurang diasuransikan.

Padanannya folder *Insurance* di Business Central: kartu polis (*Insurance*), *Insurance Type*, *Insurance Journal*, *Ins. Coverage Ledger Entry*, FlowField *Total Value Insured*, dan laporan *Insurance - Uninsured FAs*, *Insurance - Analysis*, serta *Insurance - Coverage Details* ([Insure fixed assets](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-how-insure), [Fixed assets insurance reports](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-reports#fixed-assets-insurance-reports)). Source yang dibaca: tabel 5628 `Insurance`, 5629 `Ins. Coverage Ledger Entry`, 5630 `Insurance Type`, report 5626 `Insurance - Uninsured FAs`, report 5620 `Insurance - Analysis`, dan page 5649 `Total Value Insured`. Dynamics 365 F&O menyimpan nilai asuransi sebagai field pada aset (nomor polis, nilai pertanggungan, tanggal asuransi); bentuk BC yang berpolis dan berriwayat dipilih karena satu polis biasanya menanggung banyak aset dan nilainya berubah setiap perpanjangan.

## Yang mudah tertukar

**Tidak ada jurnal ke buku besar.** BC pun tidak memposting asuransi: *Insurance Journal* hanya menulis ke *Insurance Coverage Ledger*. Premi dibayar lewat tagihan vendor biasa di aplikasi finance; premi tahunan di kartu polis hanya informasi.

**Nilai pertanggungan polis bukan jumlah pertanggungan aset.** `nilai_pertanggungan` pada polis adalah plafon polis (*Policy Coverage*). Jumlah nilai aset yang ditanggung pada satu tanggal (*Total Value Insured*) dihitung dari baris pertanggungan. Selisihnya ditampilkan sebagai sisa atau kelebihan nilai polis, seperti *Over/Under Insured* pada *Insurance Statistics* dan laporan *Insurance - Analysis*.

**Kurang diasuransikan diukur terhadap nilai perolehan, bukan nilai buku.** Mengikuti halaman *Total Value Insured* BC yang menampilkan *Acquisition Cost* buku asuransi di samping total pertanggungan. Nilai buku ikut ditampilkan sebagai informasi, seperti laporan *Uninsured FAs* yang memuat nilai perolehan, penyusutan, dan nilai buku.

## Data yang disimpan

| Tabel | Isi |
| --- | --- |
| `aset_m_jenis_asuransi` | Master jenis asuransi (*Insurance Type*). Bentuk master biasa, kode dari Number Sequence (`JASR`, lingkup tenant). |
| `aset_m_polis_asuransi` | Kartu polis: kode (Number Sequence `POLA`, per entitas legal), entitas legal, nama, nomor polis dari penanggung, jenis asuransi, penanggung (`vendor_id`, vendor Core), masa berlaku, premi tahunan, nilai pertanggungan polis, `diblokir`, keterangan. |
| `aset_tr_pertanggungan_asuransi` | Satu aset pada satu polis untuk satu periode: nilai pertanggungan, berlaku mulai, berlaku sampai (kosong = ikut polis), keterangan. |

Penanggung adalah vendor milik Core yang dibaca lewat kontrak `VendorDirectory`, sama seperti vendor penerimaan aset; namanya dibaca ulang saat ditampilkan, tidak disalin. Seluruh kolom berklasifikasi `CustomerContent`: nomor polis dan nama polis adalah data organisasi, bukan data pribadi.

## Pertanggungan adalah riwayat

BC mencatat setiap perubahan nilai sebagai entri ledger baru (positif atau negatif) dan menjumlahkannya. Di sini satu baris membawa nilai untuk satu periode, dan **nilai serta tanggal mulainya tidak pernah disunting**:

| Tindakan | Yang terjadi |
| --- | --- |
| **Tambah aset** | Baris baru. Padanan satu baris *Insurance Journal*. |
| **Ganti nilai mulai tanggal** | Baris lama berakhir sehari sebelum tanggal itu, baris baru membawa nilai baru dan sisa periodenya. Padanan *Index Insurance* untuk satu aset, tanpa persentase. |
| **Akhiri pertanggungan** | Tanggal akhir diisi, misalnya karena aset dijual atau pindah polis. |
| **Arsipkan** | Untuk baris yang salah dicatat. Padanan dua entri reklasifikasi BC. |

**Keputusan:** periode menggantikan entri bertanda karena pertanyaan yang paling sering adalah "berapa yang ditanggung pada tanggal X", dan periode menjawabnya tanpa menjumlahkan seluruh riwayat.

## Rumus

Pertanggungan terhitung pada tanggal D bila periodenya mencakup D **dan** polisnya berlaku pada D serta tidak diarsipkan. Polis yang diblokir tetap menanggung; blokir hanya menolak pertanggungan baru, seperti *Blocked* di BC.

- **Total yang ditanggung (aset, D)** = jumlah nilai pertanggungan aset itu yang terhitung pada D, dari semua polis.
- **Total yang ditanggung (polis, D)** = jumlah nilai pertanggungan polis itu yang terhitung pada D.
- **Selisih plafon** = nilai pertanggungan polis − total yang ditanggung polis. Negatif berarti melebihi plafon.
- **Status aset**: *belum diasuransikan* bila total = 0; *kurang diasuransikan* bila 0 < total < nilai perolehan; selain itu *cukup*.
- **Nilai perolehan** dibaca dari buku komersial aset (buku tanpa master atau ber-lapisan posting `current`, kode paling awal), atau nilai perolehan di register bila aset belum punya buku. Aturannya satu, `Services/CommercialBookValues`, dipakai juga pemeriksaan fisik aset.

"Hari ini" selalu menurut zona pengguna (`RequestContext::timezone()`).

## Aturan yang dijaga, dan alasannya

- **Satu aset tidak ditanggung dua kali oleh polis yang sama pada tanggal yang sama.** Jumlahnya akan terhitung ganda. Polis berbeda boleh menanggung aset yang sama bersamaan (kebakaran dan gempa, misalnya), seperti di BC. Pemeriksaannya berjalan sesudah versi polis diklaim, jadi dua penambahan serentak bergantian.
- **Pertanggungan berada di dalam masa berlaku polis**, dan masa berlaku polis tidak dapat dipersempit sampai mengeluarkan pertanggungan yang sudah tercatat.
- **Aset milik entitas legal polis, dalam jangkauan pengguna, dan masih beredar.** Aset yang sudah dihentikan atau dilepas tidak dapat ditambahkan dan tidak muncul di ringkasan, seperti BC melewatkan aset ber-*Disposal Date* atau *Inactive*.
- **Polis yang masih menanggung aset tidak dapat diarsipkan.** Pertanggungannya diakhiri lebih dulu, supaya aset tidak diam-diam menjadi tidak diasuransikan.
- **Versi baris.** Ubah dan arsipkan polis, serta setiap tindakan atas pertanggungannya, mengklaim versi polis.
- **Jangkauan organisasi.** Polis milik entitas legal tanpa unit kerja, jadi terlihat oleh pengguna yang punya hibah kebijakan `management-aset.asset-responsibility` pada entitas legal itu, unit mana pun (`OrganizationScope::legalEntityQuery`). Baris pertanggungan hanya menampilkan aset dalam jangkauan pengguna; total polis tetap menghitung semuanya, karena total adalah angka polis.

## Layar dan hak akses

| Layar | Menu | Isi |
| --- | --- | --- |
| Asuransi aset | Transaksi → Asuransi aset | Tab **Polis** (daftar, kartu polis, aset yang ditanggung beserta tindakannya) dan tab **Belum dan kurang diasuransikan** (per tanggal, bisa disaring). |
| Jenis asuransi | Master data → Jenis asuransi | Master biasa. |
| Detail aset | Inventarisasi aset → aset → bagian **Asuransi** | Ditanggung hari ini, nilai perolehan, nilai buku, dan riwayat pertanggungan dari seluruh polis. Hanya baca. |

Duty `management-aset.polis-asuransi.manage` (*Kelola asuransi aset*) memuat privilege lihat (termasuk baca register aset dan jenis asuransi untuk pemilih dan ringkasan), susun polis dan pertanggungan, dan arsipkan polis. Menambah, mengakhiri, mengganti nilai, dan mengarsipkan pertanggungan memakai permission `update` polis: pertanggungan adalah isi polis, seperti baris jurnal asuransi di BC. Master jenis asuransi punya duty sendiri, `management-aset.jenis-asuransi.manage`.

## Lampiran

`aset_m_polis_asuransi` terdaftar sebagai jenis record lampiran Core, untuk berkas polis. Hak melampiri mengikuti permission `update` polis dan jangkauan entitas legalnya.

## Endpoint

| Endpoint | Guna |
| --- | --- |
| `GET/POST jenis-asuransi`, `GET/PATCH/DELETE .../{id}` | Master jenis asuransi. |
| `GET/POST polis-asuransi`, `GET polis-asuransi/vendor` | Daftar, buat polis, pemilih penanggung. |
| `GET/PATCH/DELETE polis-asuransi/{id}` | Kartu polis beserta pertanggungannya, ubah, arsipkan. |
| `POST polis-asuransi/{id}/pertanggungan`, `.../{coverageId}/akhiri`, `.../{coverageId}/ganti-nilai`, `DELETE .../{coverageId}` | Tindakan atas pertanggungan. |
| `GET asuransi-aset?aset_id=` | Asuransi satu aset. |
| `GET asuransi-aset/ringkasan` | Aset belum, kurang, atau cukup diasuransikan pada satu tanggal. |

Kontraknya di `contracts/src/paths/asuransi.yaml`.

## Belum ada

- **Buku penyusutan asuransi.** BC memilih satu buku di *FA Setup* (*Insurance Depr. Book*) sebagai sumber nilai perolehan asuransi. Sampai halaman Parameter aset tetap memuat setelan itu, yang dipakai buku komersial. Setelan ini sengaja tidak ditambahkan di PR asuransi karena halaman pengaturan dipegang pekerjaan lain.
- **Pertanggungan otomatis saat aset diterima** (*Automatic Insurance Posting* BC).
- **Index insurance massal** (naik/turun persen untuk seluruh aset satu polis), dan laporan cetak *Insurance - List* / *Coverage Details* lewat mesin laporan Core.

## Test

`tests/Feature/AssetInsuranceTest.php`: nomor polis dan vendor entitas legal; pertanggungan sebagai riwayat dan total pada tanggal; ringkasan belum/kurang diasuransikan terhadap nilai perolehan dan aset yang sudah dilepas tidak ikut; polis diblokir dan aset di luar polis ditolak; polis bertanggungan berjalan tidak dapat diarsipkan dan versi baris wajib; jangkauan entitas legal dan idempotensi; master jenis asuransi dan duty dari manifest sungguhan.
