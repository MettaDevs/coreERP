# Core Workflow dan Workflow Designer

## Tujuan

Core menyediakan engine workflow generik lintas aplikasi. Approval adalah satu
jenis langkah, bukan nama engine atau halaman. Administrator membuat
konfigurasi workflow dari workflow type yang didaftarkan aplikasi; aplikasi
tetap memiliki dokumen bisnis dan state bisnisnya sendiri.

URL pengaturan Core adalah `/settings/workflows` dan label UI-nya **Workflow**.
Workflow type menyatakan scope `legal_entity` atau `tenant`, schema context yang
boleh dipakai kondisi, serta aplikasi pemilik proses. Workflow asset memakai
scope entitas legal.

## Canvas V1

Canvas menggunakan `@xyflow/react` (MIT) agar Core tidak membuat interaksi
node/edge dari nol. Pengguna dapat menekan **Tambah langkah** dari palette atau
menyusun node langsung di canvas. Setiap node memiliki panel properti dan versi
yang sudah aktif tidak dapat diubah.

Elemen yang dapat dipakai pada V1:

- **Mulai** — titik awal, tepat satu per workflow;
- **Selesai** — titik akhir, minimal satu;
- **Persetujuan** — penerima role, satu anggota, atau beberapa anggota tertentu;
- **Tugas manual** — pekerjaan yang diselesaikan oleh role atau anggota;
- **Keputusan kondisi** — memilih cabang true/false dari field context yang didaftarkan type;
- **Cabang paralel** — menjalankan minimal dua cabang dan menunggu seluruh cabang selesai.

Approval bertingkat dibuat dengan beberapa node persetujuan berurutan, misalnya
`Mulai → Persetujuan Kepala Unit → Persetujuan Direktur → Selesai`.
Pengaju tidak boleh menyetujui instance miliknya sendiri.

Pengaturan penerima dan kebijakan penyelesaian dipisahkan seperti pada Dynamics
365. Role mengirim work item ke seluruh anggota role aktif, sedangkan pilihan
anggota tertentu menyimpan daftar `assignees` berisi membership. Kebijakan
penyelesaian pada setiap node adalah:

- `single` — respons salah satu penerima menentukan hasil;
- `majority` — lebih dari setengah penerima harus merespons dengan hasil yang sama;
- `percentage` — jumlah respons minimum dihitung dari `completion_percentage` (1–100);
- `all` — seluruh penerima harus menyetujui.

Jika persentase belum tercapai, workflow tetap menunggu. Setelah ambang tercapai,
penolakan menghasilkan keputusan ditolak; persetujuan menghasilkan traversal ke
edge berikutnya. Untuk satu penerima, semua kebijakan memiliki hasil yang sama.

Publish ditolak jika graph tidak memiliki Mulai/Selesai, ada node atau koneksi
terputus, ada putaran, kondisi tidak memiliki true/false, cabang paralel kurang
dari dua, atau penerima tugas tidak dapat diresolusikan.

## Kontrak graph

Resource pengaturan:

- `GET /api/v1/workflows/{workflow}/graph` membaca versi draft terbaru atau versi aktif;
- `PUT /api/v1/workflows/{workflow}/graph` mengganti node dan edge pada draft;
- `POST /api/v1/workflows/{workflow}/draft` menyalin versi aktif menjadi draft baru;
- `POST /api/v1/workflows/{workflow}/publish` memvalidasi dan mengaktifkan versi immutable.
- `POST /api/v1/workflows/{workflow}/activate` mengaktifkan kembali versi published;
- `POST /api/v1/workflows/{workflow}/deactivate` menonaktifkan konfigurasi tanpa menghapus riwayat.

Format node menyimpan `id`, `type`, `data.label`, `data.config`, dan posisi.
Format edge menyimpan `source`, `target`, `outcome`, dan condition. Penyimpanan
memakai tabel `workflow_elements` dan `workflow_transitions` yang scoped oleh
versi konfigurasi.

Contoh konfigurasi penerima tertentu:

```json
{
  "assignees": [
    { "type": "member", "id": "membership-a" },
    { "type": "member", "id": "membership-b" }
  ],
  "completion_policy": "percentage",
  "completion_percentage": 60
}
```

Konfigurasi lama `{ "assignee": { "type": "role", "id": "role-id" } }`
tetap didukung untuk kompatibilitas.

Runtime memulai traversal dari Mulai. Approval dan tugas manual membuat work
item; keputusan menyusuri edge berikutnya; kondisi membaca `decision_context`;
cabang paralel menunggu semua pekerjaan aktif sebelum workflow selesai. Keputusan
terminal diterbitkan sekali melalui outbox sebagai
`core.workflow.decision.v1`.

## Elemen roadmap

Kemampuan berikut dicatat untuk tahap berikutnya dan belum dieksekusi V1:

- automated task dan notifikasi;
- timer, batas waktu, dan eskalasi;
- delegasi, reassign, recall, dan request change;
- manual decision dan subworkflow;
- line-item workflow;
- hierarchy, manager routing, dan signing limit;
- kondisi berdasarkan nilai, budget, atau dimensi finansial;
- strategi join paralel selain `all`;
- effective date, simulasi, dan perbandingan versi.

Pemetaan ini mengikuti Workflow editor Dynamics 365 yang menyediakan manual
task, automated task, approval process, approval step, manual/conditional
decision, parallel branch, participant, hierarchy, completion policy, dan
workflow actions ([create workflow](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/create-workflow),
[approval step](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/configure-approval-step-workflow),
[workflow actions](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/workflow-actions)).

## Kontrak aplikasi

Aplikasi mengirim `POST /api/internal/v1/workflow-instances` dengan
`Idempotency-Key`, source document stabil, legal entity, initiator membership,
dan `decision_context` yang sesuai schema type. Core tidak membaca database
aplikasi. Event keputusan dikonsumsi aplikasi secara idempoten untuk mengubah
state dokumen bisnis.
