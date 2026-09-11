# Cetakan module

Bentuk minimal satu module CoreERP: manifest, satu migration bertabel berawalan, satu model
bertenant, satu rute, satu halaman, dan satu test yang benar-benar membuktikan penyaringan
tenant.

Folder ini **bukan** module. Ia tidak ikut dipindai registry maupun penjaga batas — keduanya
mencari `modules/<penerbit>/<module>/`, satu tingkat lebih dalam — dan manifestnya ber-id
`change-me`, yang ditolak `ModuleRegistry` sekalipun foldernya tersalin ke tempat yang salah.

Pint dan PHPStan **tetap** membacanya: keduanya menyapu `modules/` apa adanya, tanpa peduli
berapa tingkat dalamnya. Itu disengaja dan sebaiknya tetap begitu. Cetakan yang tidak lulus
pemeriksaan akan melahirkan module yang tidak lulus juga, dan yang menanggungnya orang yang
sedang membuat module pertamanya — saat ia paling tidak bisa membedakan kesalahannya sendiri
dari kesalahan cetakannya.

## Cara memakainya

Jangan menyalin folder ini dengan tangan, dan jangan menyalin module yang sudah jadi. Yang
pertama menghasilkan penanda yang terlewat; yang kedua ikut membawa keputusan domain module
itu beserta awalan tabelnya.

```powershell
cd apps/core
php artisan module:make kelola-contoh --nama="Kelola Contoh" --awalan=kelola_
```

Perintah itu menyalin folder ini, mengganti tiap penanda menurut bentuknya, mendaftarkan
awalan tabelnya pada tabel pemetaan di `../README.md`, dan menambahkan package-nya ke
`composer.json` Core. Langkah terakhirnya — `composer update <penerbit>/<module>` — dicetak
perintahnya sendiri, karena hanya itu yang membuat kelas module benar-benar bisa dimuat.

## Penanda yang diganti

| Penanda | Bentuk | Contoh hasil |
| --- | --- | --- |
| `penerbit-contoh` | id penerbit, huruf kecil | `apperp` |
| `PenerbitContoh` | penerbit, StudlyCase | `Apperp` |
| `change-me` | id module, huruf kecil dan tanda hubung | `kelola-contoh` |
| `ChangeMe` | module, StudlyCase | `KelolaContoh` |
| `Change Me` | nama tampilan | `Kelola Contoh` |
| `change_me_` | awalan tabel | `kelola_` |

Keempat bentuk terakhir memang berbeda satu sama lain, dan itu sebabnya penggantiannya
dilakukan per bentuk, bukan sebagai satu `str_replace` buta: sebuah penggantian yang
mencampur `change-me` dengan `change_me_` menghasilkan nama tabel atau kode izin yang rusak,
dan rusaknya baru terlihat saat migration jalan.

Berkas ini sendiri **tidak** ikut tersalin ke module baru; isinya bercerita tentang cetakan,
bukan tentang module yang dibuat darinya.

## Yang harus diganti sesudahnya

Module yang keluar dari cetakan ini lulus seluruh penjaga batas apa adanya — folder, manifest,
namespace, awalan tabel, dan penyaringan tenantnya sudah benar — tetapi isinya belum menjadi
module. Yang diganti sesudahnya:

- `app.yaml` — identitas, menu, dan empat lapis keamanan yang sebenarnya, bukan `contoh`.
- `database/migrations/` — tabel yang sebenarnya. Semua tetap berawalan, semua membawa
  `tenant_id`, dan semua memakai penghapusan lunak.
- `src/Models/Contoh.php`, `src/Http/Controllers/`, `routes/web.php`, `ui/Pages/Daftar.tsx` —
  domainnya sendiri.
- `tests/Feature/PenyaringanTenantTest.php` — pertahankan keempat testnya dan tambahkan yang
  lain di sebelahnya. Keempatnya menjaga hal yang tidak boleh regresi di module mana pun.

Jalur lengkap dari nol sampai module terdaftar di katalog ada di
[Membangun modul baru](../../docs/apps/membangun-app-baru.md).
