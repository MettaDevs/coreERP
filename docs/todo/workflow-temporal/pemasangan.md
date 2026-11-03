# Pemasangan dan operasional Flowable

Pekerjaan ini adalah rencana, bukan perintah memasang langsung pada server dev atau client.
[Flowable sudah dipilih](./pemilihan-engine). Gate 1G membuktikan versi, distribusi, adapter dan
operasi sebelum dependency produk diubah. Jangan memasang stack engine alternatif.

## Komponen

| Komponen                 | Keputusan arah                                                                      |
| ------------------------ | ----------------------------------------------------------------------------------- |
| Flowable engine          | Layanan Java platform; versi/distribusi/API dibuktikan di pilot                     |
| Persistence engine       | PostgreSQL dengan database/credential terpisah dari data bisnis                     |
| Adapter Core             | Client API untuk deployment, start, query, task/job dan korelasi                    |
| Worker PHP               | Mengambil/lock external worker job dan memanggil layanan module dalam runtime Core  |
| Editor dan inbox         | Reuse UI Core setelah mapping model dan task lifecycle terbukti                     |
| Modeler/console tambahan | Keputusan paket/lisensi terpisah; Work/Design tidak dianggap gratis dari engine OSS |
| Dispatcher               | Queue/perintah Core yang mengirim outbox durable                                    |

Worker dapat memakai proses CLI/queue Core sesuai hasil pilot, tanpa mengganti web runtime.
Tidak ada kewajiban SDK PHP Temporal, gRPC atau RoadRunner untuk integrasi ini. Cocokkan versi
Java, Flowable, PostgreSQL dan PHP serta API yang benar-benar tersedia dari [riset](./riset).
Pin image digest, lockfile dan dependency; jangan gunakan `latest` pada paket rilis.

Fondasi Signavio tidak otomatis menambah library: React Flow tetap renderer awal. Jika area 0A
memilih parser/modeler BPMN, catat package/versi/lisensi, dukungan XML/layout, dan bukti konversi
terlebih dahulu. Flowable mengeksekusi BPMN; adapter tidak membuat interpreter BPMN kedua.
Dependency tambahan menunggu keputusan WT-12 dan kebutuhan yang nyata.

## Bukti yang harus disimpan

- Matriks versi beserta tanggal, link rilis, lisensi/notice, checksum, dan hasil instalasi image bersih.
- Deploy BPMN → start → external worker job PHP → commit module → completion dari jaringan container nyata.
- API acquire/lock/complete/fail dan health check; test bisnis tidak memakai database engine.
- CPU, peak RSS worker/server, koneksi DB, ukuran history/payload, pertumbuhan disk, dan throughput.
- Nama perintah start/stop/drain worker, dispatch, pemulihan job dan log tanpa secret.
- Deduplikasi start/operasi, termasuk crash saat respons start atau ack job hilang.

Perintah instalasi ditulis setelah versi/distribusi teruji; jangan menambah SDK yang tidak
digunakan adapter. Jangan menjalankan test seluruh Core serial untuk mengecek instalasi.

## Deployment repository

Periksa file existing berikut sebelum mengedit; daftar ini bukan janji semua harus berubah:

- `apps/core/composer.json` dan lockfile hanya bila client library tambahan dipilih.
- `deploy/perakit/Dockerfile` bila runtime/worker Core memerlukan perubahan; mengikuti `coreerp-harbor`.
- `deploy/compose.edition.yaml` serta compose SaaS yang relevan untuk service/worker/network.
- `D:/Kerja/erp-dev/start.ps1` untuk runtime lokal; perubahan di luar repo dicatat sebagai dependency pekerjaan.

Service workflow tidak berarti membuat container/API/database sendiri per module. Source aset
tetap module dalam runtime Core; worker adalah peran eksekusi Core. Engine adalah layanan platform
per lingkungan/cluster, bukan kewajiban satu engine per tenant. Perubahan admin.erp, API agen,
atau laporan health pusat memakai skill `coreerp-konsol` dan kontrak terkait bila scope meluas.

## SaaS, on-prem, dan lingkungan

Pembuktian mulai lokal. Deployment dev mengikuti keputusan hasil spike, bukan langsung memasang
ke semua instalasi. Sebelum memakai dev1/dev2, baca alias di SSH config dan verifikasi runtime;
tidak menyalin alamat dari chat. On-prem menjalankan engine lokal tanpa cloud vendor, telemetry
wajib, atau unduhan saat start. Paket harus membawa image/binary yang diperlukan.

Tenant, topic job, routing dan koneksi berasal dari registry/konfigurasi tepercaya, bukan nama
client/module yang ditulis mati di adapter Core. Tenant ID engine tidak menjamin authorization
atau RBAC; API engine hanya jaringan internal. Variabel/history memakai ID dan data minimum,
tanpa data pasien maupun secret yang tidak diperlukan.

## Operasional sebelum release

- Backup/restore database bisnis dan Flowable beserta hubungan operation/instance; uji outbox yang belum terkirim.
- Retention history, archival bila diperlukan, dan audit bisnis ditetapkan dengan alasan.
- Monitor outbox tertua, queue lag, job retry/dead-letter, lock kedaluwarsa dan ketidaksesuaian hasil.
- Start/drain/restart worker dan upgrade schema dilakukan lewat prosedur berversi.
- Sediakan jalur penghentian start baru; drain/recovery instance lama tetap tersedia.
- Periksa kebutuhan capability/schema/action binding model terhadap versi worker sebelum aktivasi;
  rollout additive tidak mengubah versi published client atau template salinannya.
- Bila dependency tidak sehat, simpan intent untuk diproses atau tolak dengan jelas sesuai keputusan produk;
  jangan diam-diam pindah ke executor lain dan menjalankan tindakan dua kali.

Tidak ada angka minimum resource yang disahkan sebelum [uji](./verifikasi). Biaya operasional
on-prem adalah gate keputusan lanjut, bukan catatan yang boleh diabaikan setelah demo berhasil.
