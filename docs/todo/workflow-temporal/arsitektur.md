# Arsitektur usulan dan batas keselamatan

Engine final menunggu [verdict pilot](./pemilihan-engine). Halaman ini mempertahankan cabang
Temporal awal; history/replay/activity SDK tidak diwajibkan bila Flowable dipilih. Invariant
scope, outbox, idempotency dan commit bisnis diuji pada kedua kandidat. Flowable menjalankan
BPMN melalui service Java/API dan binding Core, tanpa interpreter BPMN kedua.

Temporal menyimpan history eksekusi; CoreERP menyimpan fakta bisnis dan konfigurasi client.
Kedua penyimpanan tidak menjadi satu transaksi ACID. Setiap batas pengiriman membutuhkan recovery
yang dapat dibuktikan. Desain ini belum diimplementasikan.

Fondasi mengikuti [panduan Signavio](./fondasi-signavio): editor menghasilkan model semantik,
binding module memberi arti tindakan, Temporal menjalankan rencana executable. Graph React Flow
tidak langsung dijadikan contract model bisnis.

## Kepemilikan

| Bagian | Pemilik dan aturan |
| --- | --- |
| Draft/versi proses, permission, inbox bisnis | Foundation Workflow existing; jangan membangun editor/inbox kedua |
| Transport dan eksekusi Temporal | Kode Core, detail SDK tidak bocor ke module melalui kontrak |
| Perintah penyelesaian dan hasil | Module aset; engine memanggil layanan module yang didaftarkan |
| Nomor, aset, buku aset, jurnal perolehan | Layanan existing pemilik masing-masing, satu transaksi module |
| History replay, retry, timer | Temporal; status bisnis di Core bukan salinan bebas history Temporal |

Lokasi adapter dan kontrak baru diputuskan lewat boundary Platform/Foundation sebelum penambahan
namespace. Jangan mengimpor Foundation ke Platform atau membuat Core menulis tabel module lewat
SQL. Activity memanggil tindakan yang didaftarkan module, bukan class/path/URL dari input client.

## Bentuk data minimum, belum nama migration final

| Record | Isi minimum | Keunikan |
| --- | --- | --- |
| Intent penyelesaian | Tenant, environment, legal entity, receipt ID/version, actor, correlation, request ID, status, hasil | Satu operasi bisnis per dokumen/versi; retry tidak membuat operasi baru |
| Outbox dispatch | Intent ID, kind start/signal, workflow ID, payload/version, percobaan, jadwal retry | Message ID tetap |
| Hubungan instance | ID proses Core, Temporal workflow/run ID, versi graph dan interpreter | Identitas terikat tenant/environment |

Utamakan reuse tabel existing setelah memeriksa migration dan model aktif. Model/version/binding
minimum diperlukan sejak awal, tetapi tidak membuat katalog/dictionary lengkap, semua gateway,
atau checkpoint kedua hanya demi flow tunggal. Registry dapat memakai deklarasi module existing;
nama tampilan client tetap data.
Record bisnis tenant-owned membawa `tenant_id`; scope lain sesuai maknanya. Data sementara juga
mengikuti kebijakan arsip repo; history Temporal punya retention sendiri, tidak menggantikan audit bisnis.

Area 0A menetapkan schema model versioned: stable IDs, jenis elemen/koneksi, binding/version,
documentation/reference minimum, serta layout terpisah. Ini belum nama kolom migration final.
Publish memeriksa capability renderer/validator/executor dan menghasilkan snapshot executable
dengan fingerprint semantik. Format BPMN XML belum diklaim sebelum keputusan dan fixture.
Subprocess/gateway/message unsupported gagal publish dengan lokasi kesalahan; tidak dihapus
saat load/save dan tidak dijalankan sebagai no-op. Mode deskriptif, bila ada, dibedakan dari executable.

## Urutan commit dan pemulihan

1. Endpoint memeriksa session, module terpasang, permission, policy organisasi, dan versi dokumen.
2. Dalam transaksi database tenant, catat intent dan outbox serta status/kunci penyuntingan yang disepakati.
   Jangan menahan transaksi sambil menunggu gRPC atau approval.
3. Dispatcher membaca outbox dan memulai workflow dengan ID tetap. Respons hilang sesudah start berarti
   cari workflow existing, bukan start dengan ID baru. Temporal mati berarti intent tetap tersimpan.
4. Workflow menerima snapshot versi graph. Untuk flow awal graph terbatas dan tervalidasi; membaca
   ulang setelan tenant setiap replay dilarang. I/O, UUID/waktu nyata, dan lookup berada di activity.
5. Activity masuk ke konteks tenant/environment tepercaya, lalu memanggil layanan penyelesaian module.
   Layanan memeriksa lock/precondition dan hasil idempoten sebelum membuat nomor, aset, dan posting.
6. Commit pembuatan aset dan posting menjadi satu. Simpan hasil operasi dalam commit yang sama agar
   crash sebelum ack activity dapat dijawab dengan hasil existing pada retry.
7. Proyeksi status proses ke UI dapat menyusul. Jika ack/status gagal, rekonsiliasi memakai hasil bisnis,
   bukan mengulang pembuatan aset. Finance `held`/`pending` tidak disamakan dengan proses gagal.

`TenantRunnerCore` hanya mengikat scope model saat ini. Worker RoadRunner hidup lama: resolusi
koneksi environment, purge/reset koneksi, instance scoped, dan pemulihan konteks pada exception
harus dibuktikan dengan test lintas job. Namespace/task queue tidak memberi authorization bisnis.

## Authorization saat submit dan saat activity

Pengaju berasal dari konteks login, bukan payload browser. Perintah durable menyimpan identitas
pengaju dan hasil keputusan akses tanpa menyimpan session/token mentah. Putuskan sebelum implementasi:
apakah eksekusi ulang memerlukan hak pengaju masih aktif, atau perintah yang diterima berjalan
dengan kewenangan layanan yang terbatas. Jangan mencampur keduanya. Semua jalur tetap memeriksa
tenant, entity, unit, status dokumen, module aktif, dan pembatasan environment. Worker tidak
mendapat izin mutasi universal. Secret hanya di runtime, tidak di graph atau history payload.

## Satu pemilik eksekusi

Workflow approval existing tetap mengelola proses yang belum dipindah. Untuk flow penerimaan,
pilih Temporal sebagai satu executor. Jangan membiarkan `WorkflowRuntime::advance()` dan Temporal
menjalankan tindakan yang sama. Jika kelak approval dipakai, adapter menggunakan inbox existing
dan outbox signal; callback yang sama tidak boleh menutup dokumen dua kali.

## Extensibility dan kontrak

Module internal memanggil kontrak fungsi dan event internal. Sistem luar hanya API/event yang
diterbitkan; tidak diberi credential Temporal atau akses database. Request/perintah memakai REST,
fakta selesai memakai event. Callback harus memeriksa signature/credential, replay, tenant,
environment, correlation, instance, dan hak pengirim sebelum mengirim signal ke engine.

Flow awal tidak punya connector luar. API module untuk integrator masih mempunyai gap yang dicatat
di [rencana API integrator](/todo/api-untuk-integrator/). Jangan menganggap endpoint sesi browser
sebagai API server-to-server. Feed finance v1 yang sudah disepakati tetap; perubahan kontrak luar
harus memperbarui kedua sisi dan melewati gate contract. Tidak ada fork atau source per client.

## Versioning, pembatalan, dan rollback

Pisahkan versi graph client, versi interpreter/worker, versi server/schema Temporal, dan versi
contract. Instance menunjuk versi graph tetap. Uji replay history lama sebelum worker baru aktif.
Menonaktifkan start baru tidak menghentikan worker yang masih menuntaskan instance lama.

Cancel hanya sah sebelum commit efek bisnis. Sesudah aset tercipta, pemulihan memakai tindakan
domain pembalikan/koreksi yang ada, bukan DELETE. Jangan menawarkan restart/reset operator yang
melewati invariant. Rollback image Core tidak berarti restore database Temporal atau memundurkan
schema; prosedur harus menyebut pasangan versi kompatibel dan nasib instance berjalan.
