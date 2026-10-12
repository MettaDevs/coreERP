# Pemasangan dan operasional

Pekerjaan ini adalah rencana, bukan perintah memasang langsung pada server dev atau client.
Lock engine/versi pada gate 1G [pemilihan engine](./pemilihan-engine) sebelum dependency produk
diubah. Daftar di bawah adalah cabang Temporal; Flowable mempunyai service Java/persistence
dan adapter API yang dirinci setelah pilot. Jangan memasang dua stack sebagai dependency wajib.

## Komponen

| Komponen | Keputusan awal |
| --- | --- |
| `temporal/sdk` | Tambahan Composer yang harus diuji dengan PHP/Laravel aktual |
| RoadRunner | Worker terpisah yang memuat Core dan module; tidak mengganti web FrankenPHP |
| `ext-grpc` | Diperlukan client PHP; periksa image tempat client berjalan |
| `ext-protobuf` | Rekomendasi upstream, ukur sebelum menyatakan wajib |
| Temporal Server | Self-host untuk pembuktian; dev CLI hanya smoke awal |
| Persistence dan visibility | PostgreSQL dengan database/credential terpisah dari data bisnis |
| Temporal UI | Operator teknis, akses internal; bukan inbox pengguna bisnis |
| Dispatcher | Queue/perintah Core yang mengirim outbox durable |

Tidak mewajibkan Elasticsearch atau Kubernetes di percobaan awal. Cocokkan dukungan versi
PostgreSQL, Server, SDK, RoadRunner/plugin, PHP, dan ekstensi dari sumber resmi pada [riset](./riset).
Pin image digest, lockfile, checksum binary, dan feature flags; jangan gunakan `latest` pada paket rilis.

Fondasi Signavio tidak otomatis menambah library: React Flow tetap renderer awal. Jika area 0A
memilih parser/modeler BPMN, catat package/versi/lisensi, dukungan XML/layout, dan bukti konversi
terlebih dahulu. Temporal tidak menjadi BPMN parser hanya karena model mempunyai task/gateway.
Dependency tambahan menunggu keputusan WT-12 dan kebutuhan yang nyata.

## Bukti yang harus disimpan

- Matriks versi beserta tanggal, link rilis, lisensi/notice, checksum, dan hasil instalasi image bersih.
- Smoke SDK client → server → worker → hasil, dari jaringan container yang sesungguhnya.
- PHP extension list dan health check; test tenant tidak boleh memakai database Temporal.
- CPU, peak RSS worker/server, koneksi DB, ukuran history/payload, pertumbuhan disk, dan throughput.
- Nama perintah start/stop worker, dispatch, replay-test, health, serta cara menyimpan log tanpa secret.

Perintah seperti `composer require temporal/sdk` dari quickstart bukan perintah final repository:
pilih versi dahulu. Jangan menjalankan test seluruh Core serial untuk mengecek instalasi.

## Deployment repository

Periksa file existing berikut sebelum mengedit; daftar ini bukan janji semua harus berubah:

- `apps/core/composer.json` dan lockfile untuk SDK.
- `deploy/perakit/Dockerfile` untuk runtime/extension/binary; mengikuti skill `coreerp-harbor`.
- `deploy/compose.edition.yaml` serta compose SaaS yang relevan untuk service/worker/network.
- `D:/Kerja/erp-dev/start.ps1` untuk runtime lokal; perubahan di luar repo dicatat sebagai dependency pekerjaan.

Service workflow tidak berarti membuat container/API/database sendiri per module. Source aset
tetap module dalam runtime Core; worker adalah peran eksekusi Core. Perubahan admin.erp, API agen,
atau laporan health pusat memakai skill `coreerp-konsol` dan kontrak terkait bila scope meluas.

## SaaS, on-prem, dan lingkungan

Pembuktian mulai lokal. Deployment dev mengikuti keputusan hasil spike, bukan langsung memasang
ke semua instalasi. Sebelum memakai dev1/dev2, baca alias di SSH config dan verifikasi runtime;
tidak menyalin alamat dari chat. On-prem harus dapat berjalan tanpa Temporal Cloud, telemetry
wajib, atau unduhan saat start. Paket harus membawa image/binary yang diperlukan.

Namespace/queue/routing berasal dari konfigurasi deployment, bukan nama client/module yang
ditulis mati di engine. Jangan mengasumsikan satu namespace per tenant menjamin isolasi jaringan
atau RBAC. Payload history dibatasi pada ID dan data minimum; hindari data pasien maupun secret.

## Operasional sebelum release

- Backup/restore konsisten untuk DB bisnis dan Temporal; uji bagaimana intent yang belum terkirim dipulihkan.
- Retention history, archival bila diperlukan, dan audit bisnis ditetapkan dengan alasan.
- Monitor outbox tertua, queue lag, activity retry, workflow gagal, serta ketidaksesuaian proyeksi hasil.
- Start/drain/restart worker dan upgrade schema dilakukan lewat prosedur berversi.
- Sediakan jalur penghentian start baru; drain/recovery instance lama tetap tersedia.
- Periksa kebutuhan capability/schema/action binding model terhadap versi worker sebelum aktivasi;
  rollout additive tidak mengubah versi published client atau template salinannya.
- Bila dependency tidak sehat, simpan intent untuk diproses atau tolak dengan jelas sesuai keputusan produk;
  jangan diam-diam pindah ke executor lain dan menjalankan tindakan dua kali.

Tidak ada angka minimum resource yang disahkan sebelum [uji](./verifikasi). Biaya operasional
on-prem adalah gate keputusan lanjut, bukan catatan yang boleh diabaikan setelah demo berhasil.
