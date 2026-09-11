# Tiga kebenaran lifecycle

Ini aturan yang paling sering dilanggar orang baru, dan paling mahal ongkos perbaikannya. Baca sekali dengan serius.

Sebuah produk melewati tiga fakta yang **terpisah**. Masing-masing punya sumber kebenaran sendiri. Satu fakta tidak boleh disimpulkan dari fakta sebelumnya.

```
catalogued  →  entitled  →  installed
```

| Fakta | Artinya | Sumber kebenaran |
| --- | --- | --- |
| **Catalogued** | Produknya dikenal platform | Katalog app (`apps`, status `available`) |
| **Entitled** | Tenant ini berhak memakainya | Entitlement tenant (`tenant_app_entitlements`) |
| **Installed** | Migration module-nya berhasil dijalankan untuk tenant ini | Catatan pemasangan module (`core_module_installations`, status `installed`) |

Sampai 10 September 2026 ada fakta keempat — **ready** — dan sumbernya `app_placements` beserta
`runtime_status`. Ia milik jalur hosting container: sebuah artifact harus ditempatkan dan runtime
kedua harus dinyatakan sehat. Jalur itu dibuang bersama app berkontainer terakhir. Module berjalan di
proses yang sama dengan Core; kalau Core hidup, module-nya hidup, jadi tidak ada kesehatan kedua yang
perlu ditanyakan.

## Kenapa ini penting

Godaannya besar: kalau tenant punya entitlement, kelihatannya wajar menampilkan produk itu sebagai "terpasang". **Jangan.** Entitlement cuma berarti tenant berhak. Module-nya bisa saja tidak ada di edisi yang terpasang, atau migration-nya belum pernah berjalan untuk tenant itu.

UI yang menulis "terpasang" wajib membaca catatan pemasangan module. Kalau catatannya belum ada untuk kasus yang sedang kamu kerjakan, **nyatakan itu sebagai gap** — jangan mengarang state.

::: danger Yang dilarang
Compatibility layer, tabel placeholder, atau status UI palsu untuk menutupi fakta yang belum tersedia. Aturan lengkapnya di [Gate fondasi Core](/dev/10-core-foundation-gates).
:::

## Seperti apa di kode

Pada `RegisterBusiness`, entitlement dicatat lebih dulu untuk seluruh app yang dibeli — lalu yang
dipasang hanya yang benar-benar ada sebagai module di runtime ini:

```php
// apps/core/app/Actions/Onboarding/RegisterBusiness.php
foreach ($appIds as $appId) {
    if ($registry->cari($appId) === null) {
        continue;
    }

    app(InstallModule::class)->handle($appId, $tenant->id);
    // ...
}
```

Perhatikan bahwa entitlement sudah dibuat beberapa baris sebelumnya, tapi tidak dipakai sama sekali
untuk menyimpulkan kesiapan. Itu bukan kebetulan: sebuah app yang berhak tetapi tidak ada sebagai
module dilewati, dan ia **tidak** muncul di peluncur — karena peluncur membaca catatan pemasangan.

## Kewajiban pemasangan module

`InstallModule` hanya boleh mencatat status `installed` setelah seluruh tahap berhasil:

1. memvalidasi module ada di registry runtime dan tenant-nya berhak;
2. menjalankan migration module secara idempotent;
3. membuat urutan nomor module untuk tenant itu sebelum data awal disemai;
4. menyemai data awal lewat event `TenantDisiapkan` per module yang benar-benar terpasang;
5. menyimpan kegagalan **tanpa** mengubah entitlement atau memberikan role.

Poin terakhir penting: pemasangan gagal tidak boleh mencabut hak tenant, dan tidak boleh diam-diam memberi akses.

## Lihat juga

- [Gate fondasi Core](/dev/10-core-foundation-gates) — matriks gate dan pemilik kebenaran tiap fondasi
- [Standar module](/dev/02-module-standard) — lifecycle module: install, enable, upgrade, disable, uninstall
- [Release dan on-prem](/dev/03-release-and-on-prem) — provisioning, update, uninstall
- [Mendaftarkan katalog produk](/dev/13-publishing-an-app-release) — kontrak CI untuk mendaftarkan katalog dan release
