# Verifikasi, failure, dan keputusan lanjut

Test harus membuktikan efek bisnis, bukan hanya status engine. Database bisnis adalah oracle
jumlah aset, nomor, nilai, dan posting. Temporal history membuktikan jalur eksekusi; keduanya
harus cocok. Semua pengujian menggunakan fixture percobaan, bukan data sistem lama.

[Pilot Flowable vs Temporal](./pemilihan-engine) memakai fixture dan invariant sama. Replay
khusus Temporal; Flowable menguji deployment/lifecycle, job retry/binding dan versi proses lama.
Gate model/client/schema berlaku pada keduanya.

## Matriks wajib

| Skenario | Bukti hasil yang diharapkan |
| --- | --- |
| Penyelesaian normal, baris berjumlah lebih dari satu unit | Aset sejumlah unit; nilai buku/perolehan dan jurnal konsisten |
| Klik/retry HTTP berulang dengan intent sama | Satu operasi dan workflow; hasil yang sama |
| Dua worker/dua instance API memproses dokumen sama | Satu commit bisnis; pihak kalah membaca hasil atau konflik yang tepat |
| Worker mati sebelum commit bisnis | Tidak ada aset/posting parsial; retry melanjutkan |
| Worker mati setelah commit sebelum ack activity | Retry mengembalikan hasil existing; nol nomor/aset/posting tambahan |
| Dispatcher mati setelah workflow start sebelum ack outbox | Start ulang tidak membuat workflow kedua |
| Temporal tidak tersedia saat submit | Intent/outbox tidak hilang; respons sesuai keputusan produk |
| Validasi permanen gagal: versi, vendor, scope, baris | Tidak ada efek bisnis; retry tidak tanpa batas |
| Mapping akun belum lengkap | Penerimaan selesai, posting held sesuai existing; bukan otomatis dibalik |
| Hak pengaju dicabut, module berubah, draft diedit | Hasil sesuai keputusan area 0, akses tidak melebar |
| Tenant/environment A → B → A pada worker sama, termasuk exception | Tidak ada koneksi/konteks tenant sebelumnya tertinggal |
| Cancel beradu dengan completion | Salah satu outcome sah; tidak ada aset dihapus fisik |
| Worker baru membaca history lama; graph client sudah diubah | Replay kompatibel, instance tetap versi awal |
| Backup/restore dan restart tanpa jaringan luar | Recovery teruji; self-host tidak membutuhkan Temporal Cloud |
| Pengguna tanpa hak membuka status/retry atau payload tenant lain | Ditolak; history/status tidak membocorkan data |
| Model dibuat renderer lama, dibaca/ditulis renderer baru tanpa edit semantik | ID dan data tidak berubah/hilang, rencana executable sama |
| Hanya posisi/warna/label presentasi berubah | Fingerprint semantik tidak berubah dan instance tidak dimulai ulang |
| Model memuat gateway/message/subprocess yang belum didukung | Publish executable ditolak dengan node penyebab; tidak menjadi no-op |
| Template bawaan diperbarui, tenant punya salinan sendiri | Salinan tenant tetap, update pilihan menghasilkan versi baru |
| Downgrade tidak mendukung capability versi model aktif | Aktivasi ditolak sebelum menghilangkan langkah proses |
| XML seperti export trial: process non-executable, task unbound, timer/condition tidak lengkap | Hanya deskriptif atau ditolak; tidak start workflow/tindakan bisnis |

## Gate pemodelan mengikuti Signavio

Fixture menguji logical model area 0A, bukan hanya bentuk JSON React Flow. Uji nama/deskripsi,
jenis koneksi, reference, start/end, reachability, implicit split, dan scope. Sinkronkan validator
publish backend dengan batas UI; server tidak mempercayai ikon yang dapat digambar browser.
Konvensi layout tidak menjadi authorization atau alasan mengubah hasil bisnis.

Untuk tambahan gateway nanti, buktikan XOR hanya mengambil cabang terpilih, AND join menunggu
cabang yang diwajibkan, merge tidak menggandakan activity, dan deadlock salah kombinasi ditolak.
Subprocess harus terikat versi, punya pemetaan input/output, menolak reference cycle, serta
mempunyai perilaku cancel/failure yang diuji. Ini gate sebelum capability aktif, bukan scope
implementasi serial pertama. XML interoperability memerlukan fixture SAP/OMG dan test round-trip;
tanpa fixture, catat belum terverifikasi. Referensi halaman PDF di [fondasi Signavio](./fondasi-signavio).

## Oracle SQL

Tentukan nama tabel/kolom dari migration aktif sebelum menulis script oracle. Gunakan hubungan
penerimaan → detail → aset dan posting ID dari hasil durable. Query langsung pada database runtime
yang sama, bukan API yang sedang diuji atau artisan host yang memakai database lain.

- Hitung aset per detail dibanding `jumlah`, serta kelompokkan kunci penciptaan dan nomor untuk duplikasi.
- Hitung satu posting per operasi bisnis dan cocokkan debit/kredit, currency precision, dan nilai perolehan.
- Cocokkan tenant/legal entity/unit aset dan posting dengan intent; lakukan juga pada tenant umpan yang tidak diberi akses.
- Bandingkan daftar asset IDs/posting ID dari retry terhadap commit pertama, bukan hanya jumlahnya.
- Pastikan rollback meninggalkan nol efek parsial; pending/held finance diperiksa terpisah dari sukses receipt.
- Simpan SQL, output agregat, seed, run ID, versi, dan waktu; jangan menyimpan credential atau data pribadi.

Checker oracle juga diuji: masukkan duplikasi/kesalahan scope pada fixture terisolasi dan pastikan
ia gagal, lalu bersihkan fixture dengan prosedur test. Dua checker identik yang salah bukan bukti.

## Perintah dan lapisan test

Gunakan composer/npm scripts existing. Test terarah dapat memakai filter; test Temporal nyata
harus memerlukan service dan melaporkan ketidaktersediaan sebagai belum terverifikasi, bukan pass.
Nama filter baru ditulis setelah test ada, bukan command khayalan di rencana.

Dari `apps/core`, gate suite penuh mengikuti instruksi repo:

```bash
php artisan test --parallel --exclude-group=serial
php artisan test --group=serial
```

Untuk putaran cepat saja, boleh tambahkan `--exclude-group=lambat`. Test DB Core memakai database
per proses; namespace/task queue fixture Temporal harus terisolasi per run/proses juga. Lifecycle
Temporal penuh dapat diberi grup serial bila benar-benar tidak dapat diisolasi, dengan alasan.

Jika route/contract berubah, dari `apps/core`:

```bash
python contracts/bundle.py
python contracts/check-contract-coverage.py
```

Formatter/lint/type check hanya scope berubah dahulu. UI harus rebuild/recreate container dan
dilihat di localhost:8000; manifest/migration diverifikasi dari container, mengikuti AGENTS.md.

## Beban dan performa

Target gate repository dipakai untuk flow ini: 1000+ VU, 100+ tenant, 2+ instance API di belakang
load balancer, PostgreSQL asli, dan 90 detik pada beban penuh. Worker Temporal nyata ikut berjalan.
Pisahkan submit latency, durasi sampai hasil bisnis, throughput activity, retry rate, dan queue lag.
Kebenaran diperiksa dengan SQL setelah run: nol kebocoran tenant, nomor ganda, eskalasi hak, atau
5xx aplikasi. Timeout transport yang tidak mewakili commit juga diuji recovery-nya.

Ambil baseline sinkron existing pada fixture/hardware yang sama. Latensi dinilai pada concurrency
yang masih tertahan; jangan menjadikan titik jenuh SLA. Angka p95/batas resource perlu diputuskan
sebelum gate akhir, bukan diisi agar hasil tampak lulus. Tidak ada jaminan Temporal mempercepat
panggilan fungsi langsung. Ukur server/worker RSS, CPU, DB IO/koneksi, pertumbuhan history, dan
biaya setiap pemasangan on-prem.

## Laporan akhir

Catat apa yang berubah, versi yang didukung, matriks test dan bukti, limit yang ditemukan, serta
runbook recovery. Pilih lanjut jika manfaat eksekusi tahan gangguan terukur dan beban operasional
dapat ditanggung. Pilih tunda jika kompatibilitas/isolasi/recovery belum terbukti; jangan mengganti
backend otomatis agar test tetap hijau. Flow lain menunggu keputusan ini.
