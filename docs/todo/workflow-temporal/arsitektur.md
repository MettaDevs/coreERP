# Arsitektur usulan dan batas keselamatan

Flowable ditetapkan melalui [keputusan pemilik](./pemilihan-engine), 31 Oktober 2026.
Pilot membuktikan versi, adapter, scope, outbox, idempotency dan commit bisnis sebelum integrasi.
Flowable menjalankan BPMN melalui service Java/API dan binding Core, tanpa interpreter BPMN kedua.

Flowable menyimpan keadaan/history proses; CoreERP menyimpan fakta bisnis dan konfigurasi tenant.
Kedua penyimpanan tidak menjadi satu transaksi ACID. Setiap batas pengiriman membutuhkan recovery
yang dapat dibuktikan. Desain ini belum diimplementasikan.

Fondasi mengikuti [panduan Signavio](./fondasi-signavio): editor menghasilkan model semantik,
binding module memberi arti tindakan, Flowable menjalankan BPMN executable. Graph React Flow
tidak langsung dijadikan contract model bisnis.

## Kepemilikan

| Bagian                                             | Pemilik dan aturan                                                |
| -------------------------------------------------- | ----------------------------------------------------------------- |
| Draft/versi proses, permission, inbox bisnis       | Foundation Workflow existing; jangan membangun editor/inbox kedua |
| Adapter dan komunikasi Flowable                    | Kode Core; detail API engine tidak bocor ke kontrak module        |
| Perintah penyelesaian dan hasil                    | Module aset; engine memanggil layanan module yang didaftarkan     |
| Nomor, aset, buku aset, jurnal perolehan           | Layanan existing pemilik masing-masing, satu transaksi module     |
| Token proses, job, retry, timer dan history engine | Flowable; status bisnis di Core bukan salinan bebas status engine |

Lokasi adapter dan kontrak baru diputuskan lewat boundary Platform/Foundation sebelum penambahan
namespace. Jangan mengimpor Foundation ke Platform atau membuat Core menulis tabel module lewat
SQL. Worker PHP memanggil tindakan yang didaftarkan module, bukan class/path/URL dari input tenant.

## Bentuk data minimum, belum nama migration final

| Record              | Isi minimum                                                                                               | Keunikan                                                                |
| ------------------- | --------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| Intent penyelesaian | Tenant, environment, legal entity, receipt ID/version, actor, correlation, request ID, status, hasil      | Satu operasi bisnis per dokumen/versi; retry tidak membuat operasi baru |
| Outbox dispatch     | Intent ID, jenis pesan, operation ID, payload/version, percobaan, jadwal retry                            | Message ID tetap                                                        |
| Hubungan instance   | ID proses Core, Flowable process definition ID/version dan instance ID, business key, versi binding/rules | Identitas terikat tenant/environment                                    |

Utamakan reuse tabel existing setelah memeriksa migration dan model aktif. Model/version/binding
minimum diperlukan sejak awal, tetapi tidak membuat katalog/dictionary lengkap, semua gateway,
atau checkpoint kedua hanya demi flow tunggal. Registry dapat memakai deklarasi module existing;
nama tampilan client tetap data.
Record bisnis tenant-owned membawa `tenant_id`; scope lain sesuai maknanya. Data sementara juga
mengikuti kebijakan arsip repo; history Flowable punya retention sendiri, tidak menggantikan audit bisnis.

Area 0A menetapkan schema model versioned: stable IDs, jenis elemen/koneksi, binding/version,
documentation/reference minimum, serta layout terpisah. Ini belum nama kolom migration final.
Publish memeriksa capability renderer/validator/executor dan menghasilkan snapshot executable
dengan fingerprint semantik dan process definition ID. BPMN executable menjadi target Flowable;
mapping model existing dan subset yang didukung dibuktikan pada area 0A.
Subprocess/gateway/message unsupported gagal publish dengan lokasi kesalahan; tidak dihapus
saat load/save dan tidak dijalankan sebagai no-op. Mode deskriptif, bila ada, dibedakan dari executable.

## Urutan commit dan pemulihan

1. Endpoint memeriksa session, module terpasang, permission, policy organisasi, dan versi dokumen.
2. Dalam transaksi database tenant, catat intent dan outbox serta status/kunci penyuntingan yang disepakati.
   Jangan menahan transaksi sambil menunggu HTTP engine, tugas manusia atau callback.
3. Dispatcher memulai versi definisi Flowable yang diikat ke intent. Operation ID/business key
   tetap dipakai untuk korelasi, tetapi bukan asumsi jaminan uniqueness. Buktikan claim,
   deduplikasi start pada boundary engine dan rekonsiliasi jika respons start hilang.
   Timeout tidak boleh langsung membuat instance kedua. Engine mati tidak menghilangkan outbox.
4. Flowable menjalankan definisi BPMN tervalidasi dan membuat external worker job untuk tindakan
   terdaftar. Worker PHP mengambil/lock job melalui API versi terpilih, bukan interpreter graf kedua.
5. Worker masuk ke konteks tenant/environment tepercaya, lalu memanggil layanan penyelesaian module.
   Layanan memeriksa lock/precondition dan hasil idempoten sebelum membuat nomor, aset, dan posting.
6. Commit pembuatan aset dan posting menjadi satu. Simpan hasil operasi dalam commit yang sama agar
   crash sebelum ack job dapat dijawab dengan hasil existing pada retry.
7. Proyeksi status proses ke UI dapat menyusul. Jika ack/status gagal, rekonsiliasi memakai hasil bisnis,
   bukan mengulang pembuatan aset. Finance `held`/`pending` tidak disamakan dengan proses gagal.

`TenantRunnerCore` hanya mengikat scope model saat ini. Worker PHP dapat hidup lama: resolusi
koneksi environment, purge/reset koneksi, instance scoped, dan pemulihan konteks pada exception
harus dibuktikan dengan test lintas job. Tenant ID/business key/topic engine tidak memberi authorization bisnis.

## Authorization saat submit dan saat eksekusi

Pengaju berasal dari konteks login, bukan payload browser. Perintah durable menyimpan identitas
pengaju dan hasil keputusan akses tanpa menyimpan session/token mentah. Putuskan sebelum implementasi:
apakah eksekusi ulang memerlukan hak pengaju masih aktif, atau perintah yang diterima berjalan
dengan kewenangan layanan yang terbatas. Jangan mencampur keduanya. Semua jalur tetap memeriksa
tenant, entity, unit, status dokumen, module aktif, dan pembatasan environment. Worker tidak
mendapat izin mutasi universal. Secret hanya di runtime, tidak di graph atau variabel/history proses.

## Satu pemilik eksekusi

Workflow approval existing tetap mengelola proses yang belum dipindah. Untuk flow penerimaan,
Flowable menjadi satu executor. Jangan membiarkan `WorkflowRuntime::advance()` dan Flowable
menjalankan tindakan yang sama. Jika kelak approval dipakai, adapter menggunakan inbox existing
dan penyampaian hasil task yang idempoten; callback yang sama tidak boleh menutup dokumen dua kali.

## Konfigurasi tenant dan koordinasi lintas module

Developer mendaftarkan tindakan beserta skema input/output, permission, versi dan batas efeknya.
Operator memilih paket default saat membuat tenant; Core menerapkannya setelah pemasangan module
melalui jalur yang diaudit. Konsol tidak menulis database engine/tenant secara langsung.
Admin yang berwenang menerbitkan penyesuaian sebagai versi baru. Update default produk tidak
menimpa salinan tenant atau memigrasikan proses berjalan diam-diam.

Healthcare memiliki fakta pelayanan/tagihan/pembayaran; Finance memiliki AR/AP, alokasi,
posting profile dan GL; Procurement memiliki permintaan/pesanan/penerimaan. Aturan penjamin dan
loket penerima uang terpisah dari urutan proses. Satu status workflow tidak menggantikan status
pelayanan, pembayaran, stok, pengakuan, jurnal atau penutupan periode.

Setiap layanan menjaga transaksi lokalnya. Perintah idempoten dan outbox/inbox menghubungkan
hasil yang sudah tersimpan. Kegagalan GL tidak menghapus fakta penerimaan uang yang sah.
Koreksi/refund/reversal adalah tindakan domain, bukan rollback seluruh perjalanan pasien.
Integrasi wajib tidak boleh hilang hanya karena node pembukuan dihapus dari diagram.

## Extensibility dan kontrak

Module internal memanggil kontrak fungsi dan event internal. Sistem luar hanya API/event yang
diterbitkan; tidak diberi credential Flowable atau akses database. Request/perintah memakai REST,
fakta selesai memakai event. Callback harus memeriksa signature/credential, replay, tenant,
environment, correlation, instance, dan hak pengirim sebelum meneruskan hasil ke engine.

Flow awal tidak punya connector luar. API module untuk integrator masih mempunyai gap yang dicatat
di [rencana API integrator](/todo/api-untuk-integrator/). Jangan menganggap endpoint sesi browser
sebagai API server-to-server. Feed finance v1 yang sudah disepakati tetap; perubahan kontrak luar
harus memperbarui kedua sisi dan melewati gate contract. Tidak ada fork atau source per client.

## Versioning, pembatalan, dan rollback

Pisahkan versi model tenant/template, BPMN process definition, rules, action contract, adapter,
serta engine/schema. Instance dan subprocess mengikat versi yang disepakati; jangan mengikuti
latest berdasarkan key ketika konfigurasi berubah. Uji instance/job lama dengan worker baru.
Migrasi instance membutuhkan mapping dan tindakan eksplisit.
Menonaktifkan start baru tidak menghentikan worker yang masih menuntaskan instance lama.

Cancel hanya sah sebelum commit efek bisnis. Sesudah aset tercipta, pemulihan memakai tindakan
domain pembalikan/koreksi yang ada, bukan DELETE. Jangan menawarkan restart/reset operator yang
melewati invariant. Rollback image Core tidak berarti restore database Flowable atau memundurkan
schema; prosedur harus menyebut pasangan versi kompatibel dan nasib instance berjalan.
