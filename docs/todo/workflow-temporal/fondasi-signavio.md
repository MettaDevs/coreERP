# Fondasi pemodelan mengikuti SAP Signavio

Halaman ini mengarahkan desain jangka panjang berdasarkan PDF yang diberikan pemilik pada
7 Oktober 2026. Implementasi pertama tetap satu flow penerimaan aset. Pemisahan model, aturan,
binding tindakan, dan eksekusi disiapkan sejak awal agar kemampuan dapat bertambah per kebutuhan
client tanpa menulis ulang semua konfigurasi.

Executor final mengikuti [gate Flowable vs Temporal](./pemilihan-engine). Sebutan Temporal pada
roadmap adalah cabang awal, bukan keputusan mengikat. Model tetap diikat ke tindakan module;
engine BPMN terpilih tidak membutuhkan interpreter BPMN CoreERP kedua.

## Referensi lokal dan batas kesimpulannya

Sumber: **SAP Signavio Solutions — A practical, detailed, and convenient guide for designing
optimal business processes**, © 2024 SAP, kode dokumen `81847enUS (24/01)`, 21 halaman.
Path lokal: `docs/references/Screenshot/A-Practical-Detailed-and-Convenient-Guide-for-Designing-Optimal-Business-Processes_enUS.pdf`.
SHA-256: `fef834f22f3f1bf1ed17359c5f30df80fac3a30eed93654936637e5e72ffcf76`.
Nomor halaman di bawah adalah nomor PDF, sama dengan penomoran tercetak.

Folder referensi sudah diabaikan Git; PDF tidak menjadi berkas situs, lampiran release, atau
dependency CI. Jangan membuat link situs ke PDF lokal yang tidak tersedia di checkout lain.
Catatan ini cukup untuk mencari salinan yang sama. Ringkasan bukan salinan PDF atau izin
mendistribusikan ulang dokumen SAP.

PDF adalah panduan pemodelan dan konvensi yang dapat disesuaikan perusahaan (hlm. 3), **bukan**
spesifikasi storage/API Signavio, seluruh standar BPMN, atau pemasangan Temporal. Pemetaan ke
CoreERP adalah usulan kita. Jangan menyebut CoreERP kompatibel BPMN 2.0 penuh dari panduan ini.

## Pemetaan prinsip ke rencana

| Panduan SAP | Halaman | Fondasi CoreERP | Pertama dibuat / ditunda |
| --- | --- | --- | --- |
| Happy path, as-is, to-be, implementasi | 5 | Catat proses existing dan perubahan target sebelum ekstraksi controller | Area 0; tidak mulai dari canvas kosong |
| Task dan collapsed subprocess | 6 | Identitas aktivitas terpisah dari binding tindakan; subprocess menunjuk model/versi | Task awal; eksekusi subprocess nanti |
| XOR, AND, OR, event-based, complex gateways | 6 | Jenis gateway dan split/join eksplisit; satu node generic parallel tidak cukup | Tanpa gateway pada flow awal; test tiap jenis sebelum aktif |
| Start, intermediate, end events | 6 | Kejadian bisnis berbeda dari signal/timer Temporal | Start/end awal; intermediate sesuai kebutuhan |
| Data objects, annotations | 7 | Dokumen dan metadata bukan instruksi eksekusi | Rujukan receipt awal; editor objek/annotation bertahap |
| Pools dan lanes | 7 | Peserta/peran model bukan permission atau koneksi DB | Identitas pemilik awal; swimlane nanti |
| Sequence, message, association | 7, 16–17 | Sequence dalam peserta; message antar peserta; association tidak menjalankan langkah | Sequence awal; jenis unsupported ditolak publish executable |
| Katalog/hierarchy level 0–4 | 8–9 | Katalog proses, end-to-end model, subprocess; bukan hierarchy organisasi | Katalog satu proses; tidak mewajibkan lima tingkat |
| Nama unik dan komentar | 11 | Nama tenant dan stable IDs; komentar/review terikat versi | Identitas awal; kolaborasi nanti, penghapusan mengikuti arsip repo |
| Deskripsi dan dictionary peran/data | 12–13 | Dokumentasi aktivitas dan rujukan terdaftar; tidak menduplikasi security role | Rujukan minimal awal; dictionary generik nanti |
| Multimerge, deadlock, siklus subprocess | 14 | Validator semantik per capability | Serial awal; token/join sebelum gateway |
| Pool sederhana, split/join terpisah, start/end konsisten | 15 | Error eksekusi berbeda dari peringatan kerapian | Diterapkan pada subset awal |
| Conditional/default flow, explicit split | 16–17 | Kondisi bukan label; default branch tunggal bila jenis gateway mengizinkan | Bersama conditional gateway |
| Spacing, ukuran, arah, folding, overlap | 18–20 | Layout terpisah dari arti proses; subprocess membantu keterbacaan | Posisi existing awal; DIN-A3 bukan batas runtime universal |
| Checklist konvensi | 21 | Aturan, severity, capability matrix, fixture positif/negatif | Gate desain dan publish awal |

## Model yang stabil sejak flow pertama

React Flow adalah tampilan, Temporal adalah executor. Struktur semantik tidak bergantung pada
komponen React atau SDK Temporal. Pemisahan ini tidak memerlukan engine BPMN kedua.

| Bagian | Aturan dari awal |
| --- | --- |
| Identitas | ID model, versi, element, dan connection tetap; ganti label tidak mengganti ID |
| Scope | Model tenant dan versi published; update template tidak menimpa salinan client |
| Semantik | Jenis activity/event/connection dan input/output terpisah dari label, koordinat, warna |
| Binding | Kode/version capability module dan skema data, bukan nama kelas/SQL/URL bebas |
| Referensi | Receipt/peran/system dari ID terdaftar; reference tidak memberi permission |
| Versi | Schema model, template/client, rules, interpreter, action contract, worker, server dibedakan |
| Dukungan | Capability renderer, validator, executor dinyatakan; ikon tidak berarti runtime tersedia |

Ini logical model, bukan daftar kolom migration final. Reuse tabel existing lebih dulu; flow
awal menyimpan hanya bagian yang dipakai. Graph React Flow existing yang membawa `data.config`
belum otomatis memenuhi bentuk ini. Area 0A memilih format persistensi serta mapping-nya.

Jika target XML BPMN 2.0 dipilih, uji skema/fixture nyata; jangan menamai JSON sendiri sebagai
BPMN. Jika format awal internal, nyatakan interoperabilitas XML belum tersedia dan rencanakan
mapping, bukan menjanjikan round-trip sebelum terbukti. Tidak membuang field unsupported diam-diam.

## Pemodelan tidak sama dengan eksekusi

Model deskriptif dapat menyebut langkah yang belum executable. Jika mode itu belum ada pada
fase pertama, elemen unsupported ditolak secara jelas. Bila mode deskriptif dibuat kemudian,
simpan dengan status berbeda; publish executable memerlukan dukungan semua elemennya.

Tidak ada node placeholder yang mengaku sukses untuk procurement, finance, atau aplikasi luar.
Pada subset awal activity maksimal satu outgoing sequence; percabangan lewat gateway ketika
semantiknya dikirim. Aturan subset ini bukan klaim semua BPMN, karena standar lebih luas.

Pool/lane bisa menggambarkan klinik dan apotek kelak, tetapi tidak memecah database/tenant atau
memberi grant. Message flow tidak otomatis membuka endpoint. Batas [extensibility](./arsitektur)
tetap berlaku. Proses hierarchy tidak menyimpan hierarchy organisasi baru.

## Roadmap per kemampuan

| Tahap | Kemampuan | Gate |
| --- | --- | --- |
| Fondasi dan flow aset | Model/version/layout terpisah, capability matrix, binding minimal, validation, serial/recovery | Area 0A dan 1–6; tidak bergantung kemampuan masa depan |
| Model bisnis bersama | Katalog, dictionary reference, documentation/review, template lifecycle | Kebutuhan nyata, reference tidak menjadi authorization |
| Gateway/subprocess | XOR bila diminta, split/join, subprocess version/data mapping | Test active branches, merge tidak menggandakan tindakan, replay, cancel, anti-siklus |
| Kolaborasi sistem | Pools/lanes, message/event/timer, connector kontrak luar | API integrator siap; authentication/correlation/idempotency/timeouts |
| Pengukuran/pertukaran | Durasi/menunggu/retry, BPMN XML, simulation/DMN bila diminta | Data nyata dan fixture round-trip; simulation bukan runtime |

Tahap sesudah flow aset adalah arah backlog, bukan scope implementasi yang telah disetujui.
Tidak memasang semua library atau membuat field kosong untuk setiap kemampuan masa depan.
Update diarahkan pada validator/renderer/executor/binding yang relevan dan tests-nya. Kemampuan
seperti inclusive join dan interoperabilitas BPMN bisa tetap besar: fondasi mengurangi rewrite
yang dapat dihindari, bukan menjamin setiap update kecil.

## Gate update per bagian

1. Capability/skema baru additive bila mungkin; kode/ID lama tidak dipakai ulang.
2. Fixture model lama dan history Temporal lama diuji dengan kode baru. Converter bila perlu
   eksplisit dan diuji; tidak menulis ulang versi published di tempat.
3. Edit/save tanpa perubahan semantik mempertahankan isi/ID. Perubahan layout tidak mengubah
   fingerprint rencana executable.
4. Publish menghasilkan versi dan kebutuhan capability baru; instance lama tetap memakai model
   dan action contract awal. Worker kompatibel dipertahankan sampai drain.
5. Update template adalah pilihan tenant yang diperiksa, bukan overwrite salinan client.
6. Downgrade unsupported ditolak sebelum aktivasi, bukan menjalankan model dengan langkah hilang.
   Prosedur rollout/rollback mengikuti [pemasangan](./pemasangan).

## Bahan tambahan dan keadaan bukti

PDF cukup untuk arah pemodelan, belum cukup untuk semantik dan interoperabilitas semua elemen.
Pemilik sudah memberikan satu [export XML trial yang diperiksa](./contoh-xml-signavio): procurement
dengan XOR/event-based gateway dan timer/message, ditandai non-executable. Contoh ini menjadi
referensi impor deskriptif; tidak menggantikan fixture executable atau contoh AND/subprocess.

| Bahan | Tujuan | Kapan |
| --- | --- | --- |
| SAP Signavio Process Manager User Guide: modeling, Dictionary, linked subprocess, BPMN XML export | Cara merujuk model/objek dan pertukaran data | Sebelum keputusan format/round-trip |
| SAP Signavio Workspace Admin Guide: modeling conventions/severity | Warning/error vs aturan organisasi | Area 0A; aturan lanjutan menyusul |
| Contoh export **BPMN 2.0 XML**: serial, XOR/AND, linked subprocess | Satu model deskriptif XOR sudah ada; serial minimal, AND dan linked subprocess belum ada | Sebelum capability/round-trip bersangkutan |
| SAP Signavio Process Governance Guide: versions, cases, service tasks, cancellation | Lifecycle workflow executable | Sebelum approval/subprocess/connector |
| OMG BPMN 2.0.2 dan skema XML | Semantik standar token/join/event dan conformance | Sebelum gateway/claim kompatibilitas |

Tautan resmi diperiksa: [konvensi BPMN SAP](https://help.sap.com/docs/signavio-process-manager/workspace-admin-guide/modeling-convention-rules-for-bpmn-2-0),
[ekspor](https://help.sap.com/docs/signavio-process-manager/user-guide/export-intro),
[API import/export](https://help.sap.com/docs/signavio-process-manager/sap-signavio-process-manager-api/import-export-api-reference),
[versi Governance](https://help.sap.com/docs/signavio-process-governance/user-guide/versions).
Bahan tambahan tidak memblokir TODO ini. Jika tidak tersedia ketika gate implementasi memerlukannya,
catat gap dan minta bahan tepat; jangan mengarang struktur. PDF SAP tidak perlu di-commit.
