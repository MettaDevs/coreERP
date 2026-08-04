# Human Resources

Human Resources menyimpan fakta tenaga kerja: pekerja, jabatan, posisi, dan penugasan pekerja pada posisi. CoreERP tetap menjadi pemilik identity, tenant membership, security role, dan scope organisasi.

## Alur dua business unit

1. Administrator membuat operating unit dan hierarchy pada CoreERP: `/settings/organization`.
2. HR membuat satu pekerja dan menautkannya ke `core_membership_id` dari Core. Email hanya dipakai untuk mencari dan menampilkan anggota.
3. HR membuat dua posisi, masing-masing berada pada operating unit yang berbeda.
4. HR membuat dua penugasan aktif untuk pekerja tersebut. Satu posisi hanya dapat diisi satu pekerja pada periode yang sama.
5. Core dapat memberi role manual per scope, atau automatic rule berbasis posisi. Token aplikasi membawa hasil scope efektif yang ditandatangani.

Data HR tidak membuat identity kedua dan tidak membaca database Core. Nomor pekerja, jabatan, dan posisi diterbitkan oleh layanan Number Sequence Core melalui reference `PEGH`, `JABH`, dan `POSH`.

## Batas versi ini

Termasuk workforce dasar dan integrasi akses posisi. Payroll, cuti, rekrutmen, kompensasi, employee self-service, approval workflow, reporting relationship, temporary access, dan SoD belum dibangun.
