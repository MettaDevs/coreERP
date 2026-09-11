# Alur end-to-end: dari daftar bisnis sampai app siap

Satu alur yang ditelusuri sampai ke kode. Kalau kamu paham yang satu ini, sebagian besar arsitektur CoreERP akan masuk akal.

Skenarionya: seseorang mendaftarkan bisnis baru dan memilih beberapa app. Titik masuknya `RegisterBusiness`.

> Berkas: `apps/core/app/Actions/Onboarding/RegisterBusiness.php`

Seluruh alur di bawah berjalan dalam **satu transaksi database**. Efek samping yang menulis di luar transaksi itu sengaja ditunda sampai transaksi selesai — alasannya di [langkah 9](#_9-setelah-commit-baru-memasang-module).

## 1. Validasi pilihan app terhadap katalog

```php
$appIds = CoreApp::query()
    ->whereIn('id', $data['app_ids'])
    ->where('status', 'available')
    ->pluck('id');
```

App yang tidak ada di katalog, atau statusnya bukan `available`, ditolak. Ini kebenaran pertama: **catalogued**.

## 2. Identitas: user, client, tenant

Tiga entitas berbeda dibuat berurutan:

- **User** — orangnya
- **Client** — pemegang kontrak komersial (`legal_name`, `slug`)
- **Tenant** — batas isolasi data, menunjuk ke `client_id`

Tenant bukan organization. Organization dibuat belakangan dan hidup **di dalam** tenant. Kalau bagian ini terasa berlebihan, baca [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy).

## 3. Reference data awal

```php
app(ProvisionDefaultUnitsOfMeasure::class)->forTenant($tenant->id);
```

Tenant baru langsung dapat satuan ukur standar. Reference data platform lain menyusul di langkah 9. Lihat [Satuan ukur](/dev/16-units-of-measure).

## 4. Profile dan placement deployment

```php
$profile = (string) config('coreerp.deployment.profile');
$placement = (string) config('coreerp.deployment.placement');

if ($profile === 'isolated') {
    $placement .= '-'.Str::lower($tenant->id);
}
```

Profile hanya boleh `pooled` atau `isolated`; selain itu melempar exception. Pada `isolated`, nama placement diberi akhiran id tenant sehingga resource-nya terpisah. Ini implementasi langsung dari model pool/silo di [Grand design](/dev/01-grand-design).

## 5. Membership dan hak

Pendaftar menjadi `owner` lewat `TenantMembership`. Lalu entitlement dibuat per app:

```php
foreach ($appIds as $appId) {
    $tenant->entitlements()->create([
        'app_id' => $appId,
        'status' => 'active',
        // ...
    ]);
}
```

Ini kebenaran kedua: **entitled**. Perhatikan yang **tidak** terjadi di sini — tidak ada artifact yang dipasang, tidak ada migration yang jalan, tidak ada status "terpasang" yang ditulis.

## 6. Role dan duty

Role `Owner` dibuat per tenant, lalu duty-nya diambil dari app yang dientitle:

```php
$ownerRole->duties()->sync(
    SecurityDuty::query()->whereIn('app_id', $appIds)->pluck('code'),
);
```

Duty datang dari manifest app, bukan di-hardcode di Core. Model role/duty/privilege/permission dijelaskan di [Identity dan access](/dev/09-identity-and-access).

## 7. Data policy scope

Untuk tiap `AppDataPolicy` milik app yang dientitle, dibuat scope pada role assignment — dengan semua kolom pembatas bernilai `null`:

```php
$ownerAssignment->dataPolicyScopes()->create([
    'tenant_id' => $tenant->id,
    'policy_code' => $policy->code,
    'legal_entity_id' => null,
    'organization_id' => null,
    'hierarchy_id' => null,
    // ...
]);
```

Semua `null` artinya owner melihat seluruh tenant. Nanti admin bisa mempersempitnya per legal entity atau per organization. Satu role assignment boleh punya beberapa scope `grant`/`revoke` — inilah yang membuat satu orang bisa bekerja di dua business unit tanpa identity ganda. Lihat [Query scope dan schema](/dev/08-query-scopes-and-schema).

## 8. Sampai sini, apa yang sudah nyata?

| Fakta | Status |
| --- | --- |
| Catalogued | ✅ divalidasi di langkah 1 |
| Entitled | ✅ dibuat di langkah 5 |
| Installed | ❌ belum |

Tenant sudah punya user, hak, role, dan scope — tapi **belum ada module yang terpasang**. Kalau UI menampilkan "terpasang" pada titik ini, itu bohong.

## 9. Setelah commit, baru memasang module {#_9-setelah-commit-baru-memasang-module}

```php
DB::afterCommit(function () use ($appIds, $idEvent, $tenant): void {
    $registry = app(ModuleRegistry::class);

    foreach ($appIds as $appId) {
        if ($registry->cari($appId) === null) {
            continue;
        }

        app(InstallModule::class)->handle($appId, $tenant->id);

        app(PengirimEventModul::class)->kirim(
            new TenantDisiapkan($idEvent, (string) $tenant->id, (string) $tenant->id, null, ['app_ids' => [$appId]]),
            (string) $tenant->id,
        );
    }

    app(EnsureNumberSequenceDrafts::class)->forReadyTenant($tenant->id);
});
```

Tiga hal yang layak diperhatikan:

**`afterCommit`, bukan di dalam transaksi.** Migration module dan penyemaian data awal berjalan di sini; menjalankannya sebelum commit berarti menulis untuk tenant yang belum ada di database.

**Registry runtime yang ditanya, bukan entitlement.** Id yang tidak ada sebagai folder di `modules/` dilewati tanpa suara: entitlement-nya tercatat, tetapi tidak ada yang bisa dipasang untuknya, dan ia tidak muncul di peluncur.

**Event `TenantDisiapkan` dipancarkan per module yang benar-benar terpasang**, bukan sekali dengan seluruh daftar app. Sekali dengan seluruh daftar akan membuat listener module yang tidak terpasang ikut menjawab dan menyemai data ke tabel yang migration-nya belum pernah dijalankan untuk tenant itu.

Sampai 10 September 2026 di sini ada langkah kesepuluh: sebuah job `DeployAppPlacement` yang menarik
image app, menjalankan migration di container lain, dan menaikkan `runtime_status` menjadi `ready`.
Job itu dibuang bersama jalur hosting container — tidak ada lagi app yang ditempatkan sebagai
container, jadi tidak ada lagi runtime kedua yang perlu dinyatakan sehat.

## Yang bisa kamu bawa dari alur ini

1. Fakta lifecycle tidak disimpulkan dari fakta sebelumnya — masing-masing dibaca dari sumbernya.
2. Efek samping yang menulis data ditunda sampai transaksi commit.
3. Core mengatur policy dan koordinasi; Core **tidak** menulis data app. Itu tetap berlaku setelah module pindah ke runtime Core: satu database yang sama bukan izin untuk saling menulis.
4. Duty dan data policy datang dari manifest app, bukan dari kode Core.
5. Upgrade bukan efek samping onboarding. Ia punya alurnya sendiri dengan compatibility matrix, backup, dan rollback; lihat [Release dan on-prem](/dev/03-release-and-on-prem).

## Lihat juga

- [Tiga kebenaran lifecycle](/onboarding/tiga-kebenaran) — aturan di balik langkah 8 dan 9
- [Identity dan access](/dev/09-identity-and-access) — role, duty, workforce, dan onboarding
- [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy) — kenapa tenant dan organization dipisah
- [Number sequence](/dev/14-number-sequences) — apa yang dikerjakan `EnsureNumberSequenceDrafts`
- [Peta kode ke dokumen](/onboarding/peta-kode) — mencari berkas lain dari dokumen
