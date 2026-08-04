# Empat kebenaran lifecycle

Ini aturan yang paling sering dilanggar orang baru, dan paling mahal ongkos perbaikannya. Baca sekali dengan serius.

Sebuah app melewati empat fakta yang **terpisah**. Masing-masing punya sumber kebenaran sendiri. Satu fakta tidak boleh disimpulkan dari fakta sebelumnya.

```
catalogued  →  entitled  →  placed + migrated  →  ready
```

| Fakta | Artinya | Sumber kebenaran |
| --- | --- | --- |
| **Catalogued** | Produknya dikenal platform | Katalog app (`core_apps`, status `available`) |
| **Entitled** | Tenant ini berhak memakainya | Entitlement tenant |
| **Placed + migrated** | Artifact sudah ditempatkan dan migration berhasil | Installation/deployment registry (`app_placements`) |
| **Ready** | Runtime-nya benar-benar sehat | Health check nyata, `runtime_status` + `ready_at` |

## Kenapa ini penting

Godaannya besar: kalau tenant punya entitlement, kelihatannya wajar menampilkan app itu sebagai "terpasang". **Jangan.** Entitlement cuma berarti tenant berhak. Artifact-nya bisa saja belum ditempatkan, migration-nya bisa saja gagal, container-nya bisa saja mati.

UI yang menulis "terpasang" wajib membaca installation/deployment registry. Kalau registry-nya belum ada untuk kasus yang sedang kamu kerjakan, **nyatakan itu sebagai gap** — jangan mengarang state.

::: danger Yang dilarang
Compatibility layer, tabel placeholder, atau status UI palsu untuk menutupi fakta yang belum tersedia. Aturan lengkapnya di [Gate fondasi Core](/dev/10-core-foundation-gates).
:::

## Seperti apa di kode

Cek pada `RegisterBusiness` menunjukkan keempatnya sekaligus. Waktu bisnis baru mendaftar, sistem tidak langsung menganggap app siap — ia memeriksa keempat kolom registry dulu sebelum memutuskan perlu deploy atau tidak:

```php
// apps/control-plane/app/Actions/Onboarding/RegisterBusiness.php
$ready = DB::table('app_placements')
    ->where('app_id', $appId)
    ->where('placement', $placement)
    ->where('artifact_status', 'placed')      // artifact sudah ditempatkan
    ->where('migration_status', 'succeeded')  // migration berhasil
    ->where('runtime_status', 'ready')        // runtime sehat
    ->whereNotNull('ready_at')                // dan kapan sehatnya tercatat
    ->exists();

if ($ready) {
    continue;
}

DeployAppPlacement::dispatch($appId, $placement);
```

Perhatikan bahwa entitlement sudah dibuat beberapa baris sebelumnya, tapi tidak dipakai sama sekali untuk menyimpulkan kesiapan. Itu bukan kebetulan.

## Kewajiban worker deployment

`DeployAppPlacement` hanya boleh menaikkan placement menjadi `ready` setelah seluruh tahap berhasil:

1. memvalidasi module, release, placement, dan entitlement aktif;
2. menjalankan pekerjaan lewat queue setelah transaksi onboarding selesai;
3. memasang artifact dan menjalankan migration secara idempotent;
4. menunggu health check nyata;
5. mencatat setiap percobaan;
6. menyimpan kegagalan **tanpa** mengubah entitlement atau memberikan role.

Poin terakhir penting: deployment gagal tidak boleh mencabut hak tenant, dan tidak boleh diam-diam memberi akses.

## Lihat juga

- [Gate fondasi Core](/dev/10-core-foundation-gates) — matriks gate dan pemilik kebenaran tiap fondasi
- [Standar module](/dev/02-module-standard) — lifecycle app: install, enable, upgrade, disable, uninstall
- [Release dan on-prem](/dev/03-release-and-on-prem) — provisioning, update, uninstall
- [Menerbitkan release app](/dev/13-publishing-an-app-release) — kontrak CI untuk mendaftarkan katalog dan release
- [Alur end-to-end](/onboarding/alur-end-to-end) — keempat kebenaran ini dalam konteks satu alur utuh
