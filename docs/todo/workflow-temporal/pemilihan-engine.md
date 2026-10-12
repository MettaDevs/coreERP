# Gate pemilihan engine: satu pilot aset

Rencana awal berfokus pada Temporal. Setelah kebutuhan diperjelas menjadi alur visual yang
dikonfigurasi client dan benar-benar dijalankan, runtime dibuka untuk pembandingan Flowable dan
Temporal. Ini belum keputusan memasang salah satu ke produk. Folder `workflow-temporal`
dipertahankan agar tautan tidak putus; nama folder tidak menentukan engine final.

## Urutan dan kebutuhan

Pilot dapat dimulai sekarang setelah aturan satu flow aset diputuskan. Integrasi penuh menunggu
hasil pilot dan verdict, bukan menunggu semua module settle. Arti tindakan dan invariant data
harus stabil; urutan tahap opsional adalah bagian yang dapat dikonfigurasi.

Developer menyediakan satu template default teruji. Client menyalin dan menyesuaikan capability
yang didukung, tanpa fork source. Publish menghasilkan versi baru, instance berjalan tetap memakai
versi awal. Template alternatif ditambah sesuai kebutuhan nyata; approval wajib tidak ditambahkan
pada flow pertama hanya untuk menunjukkan editor.

## Perbandingan berbasis bukti

| Dimensi | Flowable open-source engine | Temporal self-host |
| --- | --- | --- |
| Model executable | BPMN XML diproses engine untuk elemen yang didukung | Workflow kode; graph visual membutuhkan interpreter terbatas milik CoreERP |
| Tugas manusia | Engine mempunyai task; inbox/permission CoreERP perlu integrasi | Mekanisme tugas/inbox bisnis tetap disediakan CoreERP |
| Runtime PHP | Service Java/API; binding ke tindakan CoreERP harus dibuktikan | SDK PHP/worker RoadRunner; kompatibilitas runtime diuji |
| Recovery | Job/retry/history sesuai konfigurasi; efek module tetap idempoten | Durable history/replay/timer/retry; efek activity tetap idempoten |
| Versioning | Model/deployment dan instance versioning/migration diuji | Model client ditambah worker/interpreter replay versioning |
| Editor | Engine OSS tidak otomatis mencakup semua Work/Design/form builder komersial | Editor existing dapat digunakan; bukan modeler BPMN penuh |
| On-prem | Service Java dan persistence; ukur footprint/upgrade | Server/persistence/worker/SDK; ukur footprint/upgrade |
| Lisensi utama | Apache 2.0 engine; audit dependency/notices versi terpilih | MIT server/SDK/RoadRunner; audit dependency/notices |

Sumber: [Flowable engine](https://github.com/flowable/flowable-engine),
[BPMN dan REST](https://www.flowable.com/open-source/docs/bpmn/ch02-GettingStarted/),
[paket trial](https://documentation.flowable.com/latest/getting-started),
[SDK PHP Temporal](https://github.com/temporalio/sdk-php),
[versioning](https://docs.temporal.io/develop/php/workflows/versioning).
Loan App menunjukkan case/task nyata pada Flowable Work, bukan bukti UI itu dapat dibundel gratis
atau CoreERP sudah terintegrasi.

Kisah [T-Systems ImageMaster](https://www.flowable.com/success-stories/t-systems/content-workflow-software)
dan [Soignez-moi.ch](https://www.flowable.com/success-stories/soignez-moi/reshaping-healthcare-interactions)
adalah bukti vendor tentang aplikasi yang mengintegrasikan Flowable. Itu tidak membuktikan
kompatibilitas PHP, isolasi tenant, performa, atau lisensi paket CoreERP kita.

n8n menjadi pembanding otomasi luar, bukan pilihan bebas untuk editor yang dikonfigurasi pelanggan:
[ketentuan resminya](https://support.n8n.io/article/can-i-use-your-license-for-my-use-case)
memerlukan lisensi komersial pada penggunaan tersebut. Engine sendiri terbatas tetap fallback
bila operasional kandidat tidak layak; bukan membangun seluruh semantik BPMN dari nol.

## Pilot yang adil

1. Kunci domain: satu penerimaan/request, jumlah aset/nilai, versi, actor/scope, idempotency,
   cancel, dan commit aset/posting yang tetap atomik.
2. Fixture/hardware/batas transaksi sama untuk kedua kandidat. Model/transport boleh berbeda;
   arti bisnis dan layanan module harus sama, bukan dua implementasi bisnis.
3. Flowable membuktikan API task/job/binding aktual pada versi dipilih; jangan menganggap XML
   deskriptif trial otomatis executable. Perintah luar lewat boundary berkontrak; module internal
   tetap fungsi dan tidak HTTP ke Core.
4. Temporal membuktikan snapshot/interpreter/activity/replay. Hello World salah satu kandidat
   tidak dibandingkan dengan penerimaan penuh kandidat lain.
5. Uji normal, retry, crash setelah commit sebelum ack, competing executions, konteks A → B → A,
   update model/worker saat menunggu, penghentian start baru; oracle SQL di [verifikasi](./verifikasi).
6. Catat pekerjaan integrasi/editor/parser, test/recovery, CPU/RSS/history disk, upgrade/rollback,
   lisensi dan on-prem. Verdict berdasarkan hasil tersimpan, bukan kesan demo.

Pilot area 1.8 memakai harness terisolasi yang memulai proses kandidat dan memanggil layanan
domain area 2. Endpoint/status UI dan outbox produksi belum diaktifkan. Buktikan retry/crash
commit/domain/versioning/isolation di harness; catat skenario dispatch/UI yang belum teruji.
Verdict 1G memilih kandidat untuk integrasi penuh, bukan menyatakan fitur siap rilis. Seluruh
matriks dispatch/outbox/UI tetap wajib pada area 3–6 sebelum release.

## Verdict sebelum dependency produk

| Gate | Keputusan |
| --- | --- |
| Kebenaran | Nol aset/nomor/posting ganda, efek parsial, kebocoran scope; kegagalan belum lulus |
| Customization | Template/client version, binding, limits dan konfigurasi lama terbukti |
| Operasional | Dependency, backup/restore, resource dan recovery dapat ditanggung setiap pemasangan |
| Biaya implementasi | Bagian yang ditulis/rawat, terutama interpreter BPMN vs adapter/API |
| Lisensi | Paket yang dikirim boleh didistribusikan; UI trial bukan asumsi OSS |
| Pilihan | Engine/versi, alasan, gap/batas dan persetujuan pemilik dicatat |

Tidak ada dua engine sebagai fallback otomatis. Setelah verdict area implementasi hanya memakai
kandidat terpilih dan dokumen diperbarui. Istilah Temporal spesifik pada rencana awal adalah
cabang kandidat, bukan kewajiban bila Flowable dipilih.
