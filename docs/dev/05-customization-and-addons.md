# Customisasi tanpa fork CoreERP

## Prinsip

ERP memang membutuhkan variasi customer. Variasi tersebut tidak boleh menghasilkan branch source POS/Finance/Booking per customer, karena setiap update akan menjadi proyek migrasi tersendiri. Gunakan urutan solusi berikut, dari paling aman sampai paling khusus.

| Level | Gunakan untuk | Contoh |
| --- | --- | --- |
| Konfigurasi | Perbedaan yang dapat dinyatakan sebagai data | custom field, template, tax, role, limit diskon |
| Workflow/rule | Approval dan policy yang tidak mengubah core domain | purchase > 100 juta perlu 2 approval |
| Integration connector | Hubungan dengan sistem eksternal | biometrik, bank, marketplace, mesin produksi |
| Bridge/addon | Logic khusus customer atau integrasi dua module | POS-Booking policy, loyalty Client A |
| Product feature | Kebutuhan berulang beberapa customer | jadikan capability module resmi |
| Core fork | Hanya kontrak bespoke terpisah | Bukan pola SaaS normal |

## Custom data dan rule

Module yang mendukung custom field menyimpan definisi dan value dalam database miliknya sendiri, misalnya `custom_field_definitions`, `custom_field_values`, atau `metadata jsonb` yang dibatasi schema/index-nya. Jangan menambah kolom fisik atau migration custom untuk setiap tenant.

Rule/workflow dijalankan melalui DSL/configuration yang tervalidasi, bukan arbitrary PHP/JavaScript dari customer. Rules harus memiliki audit, version, test scenario, dan kemampuan rollback ke rule sebelumnya.

## Private addon

Private addon adalah release unit biasa dengan publisher/customer namespace:

```text
addons/client-a/loyalty-policy/
├── module.yaml
├── api/                         # service dan image sendiri
├── ui/                          # UI feature sendiri bila perlu
├── database/migrations/
└── contracts/                   # hanya contract publik yang dipakai
```

Addon mendeklarasikan `dependsOn` sebagai map, misalnya `dependsOn: { pos: ^1.2 }`. Ia dapat di-deploy hanya kepada Client A, mempunyai `loyalty_policy_db` sendiri, dan di-upgrade secara independen. Bila client tidak membelinya, addon tidak ada di deployment mereka.

## Customer-authored extension

| Environment | Ketentuan |
| --- | --- |
| Managed cloud | Publisher namespace, signed image, manifest validation, least-privilege service account, contract test, dan security approval wajib. |
| On-prem perpetual | Customer dapat menjalankan sidecar/addon sendiri, tetapi hanya mendapat API/event contract; tanpa query langsung DB atau perubahan source core. Support boundary mengikuti kontrak pembelian. |

Setiap extension memiliki dependency graph, permission, lifecycle install/uninstall, dan compatibility test yang sama dengan vendor module. Usage metering berlaku bila extension dipakai pada SaaS; on-prem perpetual tidak mengirim metering ke vendor. Ini adalah fondasi "AppExchange" CoreERP, tanpa membiarkan arbitrary code merusak control plane atau database product.

## Productization rule

Jika custom feature diminta beberapa tenant dan domainnya umum, pindahkan ke core module/configuration product. Jika hanya masuk akal untuk satu customer, pertahankan sebagai private addon. Keputusan ini dicatat sebagai ADR bersama owner product dan engineering.

## Lihat juga

- [Standar module](02-module-standard.md) — batas app, addon app, dan extension
- [API dan integration bridge](04-api-and-integration.md) — kontrak yang boleh dipakai addon
- [Mendaftarkan katalog produk](13-publishing-an-app-release.md) — pendaftaran addon ke katalog
