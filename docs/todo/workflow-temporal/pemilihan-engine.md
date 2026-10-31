# Keputusan engine: Flowable dan gate integrasi

**Keputusan pemilik, 31 Oktober 2026: workflow proses bisnis CoreERP memakai Flowable.**
Cakupannya adalah proses visual per tenant, termasuk arah lintas Healthcare, Procurement dan
Finance. Keputusan ini menggantikan pembandingan engine sebelumnya. Temporal tidak menjadi
dependency atau executor alternatif pada rencana aktif.

Ini keputusan arah produk, bukan bukti engine telah dipasang atau integrasi telah lulus.
Versi, distribusi, adapter PHP, editor, kapasitas dan operasi tetap dibuktikan melalui pilot.
Folder `workflow-temporal` dipertahankan agar tautan lama tetap berlaku.

## Urutan dan kebutuhan

Pilot dapat dimulai sekarang setelah aturan satu flow aset diputuskan. Integrasi penuh menunggu
hasil pilot Flowable dan gate kesiapan integrasi, bukan semua module settle. Arti tindakan dan invariant data
harus stabil; urutan tahap opsional adalah bagian yang dapat dikonfigurasi.

Developer menyediakan satu template default teruji. Client menyalin dan menyesuaikan capability
yang didukung, tanpa fork source. Publish menghasilkan versi baru, instance berjalan tetap memakai
versi awal. Template alternatif ditambah sesuai kebutuhan nyata; approval wajib tidak ditambahkan
pada flow pertama hanya untuk menunjukkan editor.

## Pilihan dan batas Flowable

| Dimensi          | Keputusan arah                                                 | Bukti sebelum integrasi                                   |
| ---------------- | -------------------------------------------------------------- | --------------------------------------------------------- |
| Model executable | BPMN dijalankan Flowable; tidak membuat interpreter BPMN kedua | Mapping model existing, binding dan subset yang didukung  |
| Tugas manusia    | Adapter memakai inbox dan permission CoreERP                   | Task lifecycle, assignment dan pemeriksaan hak            |
| Runtime PHP      | Layanan platform Java/API; worker PHP memanggil layanan module | API job/task dan kompatibilitas runtime versi terpilih    |
| Recovery         | Job/retry/history engine; efek module tetap idempoten          | Crash sebelum/sesudah start, commit dan ack               |
| Versioning       | Definisi proses, rules dan action contract terikat versi       | Instance/subprocess lama tidak mengikuti latest diam-diam |
| Editor           | Reuse editor existing; Work/Design diputuskan terpisah         | Modeler tambahan dan hak bundling tidak diasumsikan       |
| On-prem          | Engine lokal dan persistence tanpa cloud wajib                 | Resource, backup/restore, upgrade dan rollback            |
| Lisensi          | Engine Apache 2.0; audit dependency/notices versi terpilih     | Paket engine/editor yang benar-benar dikirim              |

Sumber: [Flowable engine](https://github.com/flowable/flowable-engine),
[BPMN dan REST](https://www.flowable.com/open-source/docs/bpmn/ch02-GettingStarted/),
[paket trial](https://documentation.flowable.com/latest/getting-started),
[external worker](https://www.flowable.com/open-source/docs/bpmn/ch07b-BPMN-Constructs#external-worker-task),
[deployment dan versi proses](https://www.flowable.com/open-source/docs/bpmn/ch06-Deployment).
Loan App menunjukkan case/task nyata pada Flowable Work, bukan bukti UI itu dapat dibundel gratis
atau CoreERP sudah terintegrasi.

Kisah [T-Systems ImageMaster](https://www.flowable.com/success-stories/t-systems/content-workflow-software)
dan [Soignez-moi.ch](https://www.flowable.com/success-stories/soignez-moi/reshaping-healthcare-interactions)
adalah bukti vendor tentang aplikasi yang mengintegrasikan Flowable. Itu tidak membuktikan
kompatibilitas PHP, isolasi tenant, performa, atau lisensi paket CoreERP kita.

n8n menjadi pembanding otomasi luar, bukan pilihan bebas untuk editor yang dikonfigurasi pelanggan:
[ketentuan resminya](https://support.n8n.io/article/can-i-use-your-license-for-my-use-case)
memerlukan lisensi komersial pada penggunaan tersebut. Jika operasional Flowable belum layak,
catat gap dan hentikan perluasan. Mengganti engine memerlukan keputusan pemilik baru, bukan
fallback otomatis ke interpreter buatan sendiri.

## Konfigurasi per tenant dan proses lintas module

Developer mendaftarkan tindakan, skema masukan/hasil, permission dan versi sekali. Operator
admin.erp memilih paket default saat membuat tenant; Core menerapkannya setelah module terkait
terpasang melalui jalur yang diaudit. Konsol tidak menulis database engine atau tenant langsung.
Admin yang berwenang menyesuaikan alur dan kondisi dari kemampuan terdaftar. Konfigurasi yang
belum lengkap tidak diaktifkan sebagai proses yang siap berjalan.

Template proses, aturan penjamin/pembayaran, loket penerima uang, posting profile dan tujuan
integrasi adalah konfigurasi berbeda. Tidak membuka PHP, SQL, credential atau URL bebas pada
editor. Update default tidak menimpa salinan tenant; publish menghasilkan versi baru.

Healthcare memiliki fakta layanan/tagihan/pembayaran; Finance memiliki AR/AP, alokasi, posting
profile dan GL; Procurement memiliki permintaan/pesanan/penerimaan. Engine mengoordinasikan
tindakan dan menunggu hasil, bukan menghitung jurnal atau menulis tabel module.
Pembayaran pasien tidak otomatis menjadi AP atau pendapatan. Perluasan arah ini tidak
memasukkan seluruh module tersebut ke implementasi pilot aset.

## Pilot Flowable

1. Kunci domain: satu penerimaan/request, jumlah aset/nilai, versi, actor/scope, idempotency,
   cancel, dan commit aset/posting yang tetap atomik.
2. Gunakan fixture dan layanan domain yang sama dengan jalur sinkron existing; jangan membuat
   implementasi bisnis kedua hanya untuk demo engine.
3. Flowable membuktikan API task/job/binding aktual pada versi dipilih; jangan menganggap XML
   deskriptif trial otomatis executable. Perintah luar lewat boundary berkontrak; module internal
   tetap fungsi dan tidak HTTP ke Core.
4. Buktikan deduplikasi start, termasuk respons start hilang. Jangan menganggap business key
   saja menjamin uniqueness/idempotency tanpa membuktikan guard pada versi Flowable terpilih.
   Hello World bukan bukti integrasi penerimaan aset.
5. Uji normal, retry, crash setelah commit sebelum ack, competing executions, konteks A → B → A,
   update model/worker saat menunggu, penghentian start baru; oracle SQL di [verifikasi](./verifikasi).
6. Catat pekerjaan integrasi/editor/parser, test/recovery, CPU/RSS/history disk, upgrade/rollback,
   lisensi dan on-prem. Keputusan lanjut/tunda berdasarkan hasil tersimpan, bukan kesan demo.

Pilot area 1.8 memakai harness terisolasi yang memulai proses Flowable dan memanggil layanan
domain area 2. Endpoint/status UI dan outbox produksi belum diaktifkan. Buktikan retry/crash
commit/domain/versioning/isolation di harness; catat skenario dispatch/UI yang belum teruji.
Gate 1G memeriksa kesiapan Flowable untuk integrasi penuh, bukan menyatakan fitur siap rilis. Seluruh
matriks dispatch/outbox/UI tetap wajib pada area 3–6 sebelum release.

## Gate sebelum dependency produk

| Gate               | Keputusan                                                                            |
| ------------------ | ------------------------------------------------------------------------------------ |
| Kebenaran          | Nol aset/nomor/posting ganda, efek parsial, kebocoran scope; kegagalan belum lulus   |
| Customization      | Template/client version, binding, limits dan konfigurasi lama terbukti               |
| Operasional        | Dependency, backup/restore, resource dan recovery dapat ditanggung setiap pemasangan |
| Biaya implementasi | Adapter/API, editor/mapping dan tindakan domain yang perlu dirawat                   |
| Lisensi            | Paket yang dikirim boleh didistribusikan; UI trial bukan asumsi OSS                  |
| Kesiapan           | Versi/distribusi Flowable, alasan, gap/batas dan hasil pilot dicatat                 |

Flowable sudah dipilih pemilik. Area 1G bukan pembandingan ulang engine, melainkan gate bukti
sebelum integrasi penuh. Tidak ada dua engine atau executor fallback otomatis. Kesiapan versi,
lisensi, isolasi, recovery dan operasional tetap harus terbukti sebelum release.
