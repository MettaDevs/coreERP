# Referensi

Sumber di luar CoreERP yang membentuk keputusan arsitekturnya, plus dokumen konsep lama yang disimpan sebagai catatan sejarah.

## Rujukan aktif

| Sumber | Kenapa dipakai |
| --- | --- |
| [Model organisasi Dynamics 365](/references/dynamics-365-organization-model) | Asal model legal entity, operating unit, dan organization hierarchy CoreERP |
| [Microsoft Learn — Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/) | Rujukan resmi untuk gate penemuan module |
| [AWS SaaS Architecture Fundamentals](https://docs.aws.amazon.com/id_id/whitepapers/latest/saas-architecture-fundamentals/saas-architecture-fundamentals.pdf) | Model control plane / application plane dan pool/silo |
| [Citus — Designing SaaS database with PostgreSQL](https://learn.microsoft.com/en-us/postgresql/citus/designing-saas?view=citus-14) | Pola isolasi data multi-tenant |

Salinan lokal PDF AWS ada di `docs/saas-architecture-fundamentals.pdf` dan `docs/references/saas-architecture-fundamentals.pdf`.

::: tip Sebelum membuat sesuatu yang baru
Gate penemuan module mewajibkan proposal yang merujuk Dynamics 365. Kalau tidak ada padanan resminya, nyatakan itu terus terang — jangan mengarang klaim kesetaraan. Lihat [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate).
:::

## Dokumen historis

Dokumen berikut mencatat pemikiran awal dan **sudah digantikan** oleh [desain kanonik](/dev/). Disimpan supaya keputusan lama bisa ditelusuri, bukan untuk diikuti.

- [Konsep awal](/references/KOnsepawal)
- [Konsep directory](/references/konsepDirectory)

::: warning
Kalau dokumen historis bertentangan dengan [desain kanonik](/dev/), yang kanonik yang menang. Asumsi awal bahwa seluruh module adalah Composer package dalam satu Laravel runtime dan satu data-plane bersama **sudah tidak berlaku**.
:::

## Lihat juga

- [Indeks desain kanonik](/dev/)
- [Grand design dan boundary platform](/dev/01-grand-design)
