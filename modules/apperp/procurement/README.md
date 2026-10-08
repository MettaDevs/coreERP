# Procurement

Kerangka pengembangan aplikasi pengadaan, dibuat lewat `module:make`. Module memakai
runtime dan database tenant Core. Belum ada UI, endpoint, tabel bisnis, master, atau
transaksi; folder kosong dipertahankan dengan `.gitkeep` untuk pekerjaan berikutnya.

## Struktur

```text
procurement/
├─ app.yaml
├─ composer.json
├─ manifest/
├─ src/
│  ├─ ModuleServiceProvider.php
│  ├─ Http/Controllers/
│  ├─ Models/
│  ├─ Actions/
│  └─ Services/
├─ database/
│  ├─ migrations/
│  └─ seeders/
├─ routes/
│  ├─ web.php
│  └─ api.php
├─ ui/Pages/
└─ tests/
   ├─ Feature/
   └─ Unit/
```

## Menjalankan di erp-dev

Dari folder repo orkestrasi lokal (`D:\Kerja\erp-dev` pada mesin ini):

```powershell
./start.ps1 -Build -Apps procurement
```

`-Modules procurement` adalah alias yang sama. Tanpa `-Apps`, Procurement ikut ditemukan
bersama module lain. Script mendaftarkan manifest ke katalog database runtime, lalu
menjalankan migration module. Folder migration kosong: tidak ada tabel bisnis dibuat.

Untuk melanjutkan pengembangan dengan Vite setelah image dibangun:

```powershell
./start.ps1 -HotReload -Apps procurement
```

Identitas tampil di katalog aplikasi, bukan pemilih aplikasi yang siap dibuka.
Manifest menyatakan `ui: {}`, sehingga katalog menyimpan `has_ui=true`, tetapi belum
ada menu atau halaman Procurement. Folder UI disiapkan untuk pekerjaan berikutnya.

## Keputusan kerangka

Padanan domain langsung adalah
[Procurement and sourcing di Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/procurement-sourcing-overview).
Referensi itu menjadi acuan penemuan fitur, bukan klaim fitur yang sudah dikirim.

| Kemampuan | Keputusan |
| --- | --- |
| Resource dan lifecycle | Ditunda; belum ada master atau transaksi. |
| Tenant/legal entity/org-unit | Mengikuti runtime Core; scope tiap resource diputuskan saat fitur dirancang. |
| Security dan data policy | Hanya contoh deklarasi template; belum ada resource bisnis atau data policy. |
| Number reference | Deferred; belum ada kode master atau nomor dokumen. |
| Workflow dan SoD | Ditunda sampai proses bisnis disepakati. |
| REST/event/laporan/lampiran/audit | Belum ada permukaan bisnis; tidak menerbitkan contract kosong. |

Validasi katalog saat ini mewajibkan entry point, permission, privilege, dan duty.
Blok `security` karena itu mempertahankan contoh template dengan kode `procurement.example.*`.
Deklarasi tersebut belum melindungi halaman atau endpoint dan bukan rancangan izin bisnis.
Ganti keempat lapis bersama proposal fitur pertama; jangan memberikan duty contoh ke role.

Namespace PHP `Modules\Apperp\Procurement\` dan awalan tabel `procurement_` mengikuti
[peta module](../../README.md). Identitas masuk katalog database melalui manifest,
bukan ditambahkan ke percabangan Core atau script launcher.

## Melanjutkan pengembangan

Ikuti [jalur membangun module](../../../docs/apps/membangun-app-baru.md) dan
[standar module](../../../docs/dev/02-module-standard.md). Gunakan skill `module-discovery`
untuk proposal fitur dan persetujuan keputusan material sebelum membuat master,
transaksi, nomor, workflow, atau integrasi. Master bersama milik Foundation;
module tidak membaca tabel module lain.

Model bisnis nantinya wajib memakai `BelongsToTenant`, `tenant_id`, dan soft delete.
Rute memakai `konteks-module:procurement`; controller memeriksa permission melalui
`RequestContext`. Nama kode ditulis bahasa Inggris; UI dan prosa bahasa Indonesia.
Tambahkan test bersama fitur di `tests/Feature/` atau `tests/Unit/`.

Kerangka ini belum menjadi module bisnis selesai. Pengujian tenant, permission, dan
gate load test proses bisnis dilakukan bersama implementasi fitur tersebut.
