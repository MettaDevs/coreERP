# TODO Management Aset — V1 tanpa posting GL

Dokumen ini adalah backlog implementasi untuk kebutuhan administrasi aset. Ini
bukan desain kanonik; aturan lintas modul tetap mengikuti
[`docs/dev/README.md`](../../dev/README.md).

Targetnya mengikuti bentuk Fixed assets Dynamics 365 sejauh relevan, tetapi
V1 tidak membuat jurnal buku besar. Aplikasi Management Aset menghitung dan
mengunci penyusutan; backoffice eksternal tetap memiliki COA, financial
dimensions, debit/kredit, serta rekonsiliasi jurnal.

| Urutan | Dokumen | Hasil |
| --- | --- | --- |
| 1 | [00-keputusan-arsitektur.md](00-keputusan-arsitektur.md) | Batas Core, Aset, dan backoffice; fakta penerimaan, penggunaan, PIC, dan lokasi |
| 2 | [01-core-workflow-approval-visual.md](01-core-workflow-approval-visual.md) | Workflow Core yang dapat dikonfigurasi tenant melalui visual designer |
| 3 | [02-transaksi-aset-v1.md](02-transaksi-aset-v1.md) | Register aset dan lifecycle penerimaan sampai pelepasan |
| 4 | [03-penyusutan-dan-bridge-backoffice.md](03-penyusutan-dan-bridge-backoffice.md) | Perhitungan, finalisasi, dan export penyusutan tanpa jurnal GL |
| 5 | [04-ui-monitoring-laporan-dan-gate.md](04-ui-monitoring-laporan-dan-gate.md) | Desain visual, monitoring, laporan, serta gate bukti |

Status memakai `[ ]` belum dikerjakan, `[~]` sedang dikerjakan, dan `[x]`
selesai dengan bukti test. Jangan menandai `[x]` sebelum writer, reader,
failure state, dan test tersedia.
