# Integrasi sistem eksternal ke modul CoreERP

> **Status:** panduan desain. Endpoint hanya dipublikasikan pada OpenAPI modul setelah diimplementasikan dan modul berstatus `ready` pada placement tenant.

Panduan ini berlaku untuk modul CoreERP apa pun yang menerima data dari atau mengirim data ke sistem eksternal milik tenant. Sistem eksternal dan modul CoreERP tetap memiliki database serta pemilik data masing-masing. Tidak ada query atau foreign key lintas database.

## Tentukan pemilik data lebih dahulu

Sebelum membuat API, tulis satu kalimat: *sistem mana yang menjadi pemilik fakta ini?* Sistem eksternal tetap pemilik dokumen/proses asalnya; modul CoreERP hanya menyimpan source reference yang dibutuhkan prosesnya. Bila kedua sistem perlu state mapping, retry state, atau orkestrasi khusus, buat integration connector/bridge dengan database sendiri.

## Setup koneksi

Administrator tenant membuat koneksi pada modul yang menerima atau mengirim integrasi. Koneksi mempunyai credential machine-to-machine, tenant tepercaya, permission yang minimum, rate limit, dan organization scope yang diizinkan.

`tenant_id` tidak pernah diterima dari URL, query, atau body request. Ia berasal dari token koneksi yang diverifikasi platform.

Sebelum data operasional dikirim, administrator memetakan reference stabil sistem eksternal ke nilai domain CoreERP yang sah, misalnya:

| Reference sistem eksternal | Tujuan CoreERP |
| --- | --- |
| `legal_entity_ref` | satu `legal_entity_id` |
| kombinasi `legal_entity_ref` dan `operating_unit_ref` | satu `legal_entity_id` dan satu `org_unit_id` bila relevan |
| `category_ref`, `location_ref`, atau reference domain lain | record milik modul tujuan |

Mapping berlaku per koneksi. Server yang menentukan hierarchy purpose dan published version efektif bila policy scope membutuhkan descendants. Caller tidak mengirim parent, depth, atau `hierarchy_id`.

## Pemetaan organisasi: acuan Dynamics 365

Pemisahan ini mengikuti praktik Dynamics 365 Finance and Operations (F&O), dengan keputusan CoreERP yang lebih aman untuk integrator:

- F&O memperlakukan company/legal entity sebagai konteks hukum, bisnis, keamanan, dan visibilitas data. Integrasi OData bekerja pada data entity yang dipublikasikan dan konteks company-nya harus dipilih secara eksplisit bila entity tersebut company-specific.
- Operating unit membagi sumber daya dan proses operasional. Ia bukan pengganti legal entity.
- Dataverse business unit adalah batas ownership/security untuk record. Ia bukan padanan satu-banding-satu untuk company/legal entity F&O.
- Karena itu CoreERP tidak menerima ID internal organisasi dari caller. Administrator memetakan reference stabil pihak ketiga pada koneksi, lalu server menyelesaikan dan memvalidasi scope organisasi setiap request.

Jangan memakai akses lintas legal entity sebagai default. Beri koneksi hanya legal entity dan operating unit yang benar-benar diperlukan. Untuk event yang company-specific, aktifkan atau subscribe per legal entity yang diizinkan.

Sumber Microsoft:

- [Company concept in Dataverse](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/data-entities/company-data)
- [Open Data Protocol (OData) for Finance and Operations](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/odata)
- [Create an operating unit](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/tasks/create-operating-unit)
- [Data events](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/business-events/data-events)

## Kontrak request

Setiap modul memakai resource domainnya sendiri. URL hanya menyatakan resource, misalnya:

```http
POST /api/v1/<resource>
Authorization: Bearer <machine-token>
Idempotency-Key: <UUID>
```

Konteks tenant datang dari token. Kode atau reference external pada request hanya dipakai untuk mencari mapping koneksi; ID CoreERP diselesaikan server dan disimpan sebagai opaque reference.

Struktur minimum body:

```json
{
  "source": {
    "document_id": "DOC-001",
    "line_id": "1",
    "occurred_at": "2026-07-24T00:00:00Z"
  },
  "source_context": {
    "legal_entity_ref": "LEGAL-001",
    "operating_unit_ref": "UNIT-001"
  },
  "data": {}
}
```

`data` adalah schema domain milik modul yang didokumentasikan dalam OpenAPI modul tersebut. `legal_entity_ref` wajib ketika fakta mempunyai konsekuensi hukum atau akuntansi; `operating_unit_ref` hanya dikirim bila proses operasionalnya membutuhkan unit tersebut.

## Validasi, response, dan event

Server memvalidasi token koneksi, tenant, readiness modul, permission, mapping, scope organisasi, dan schema domain sebelum menyimpan data. Request asynchronous mengembalikan `202 Accepted` beserta resource yang dapat dipoll:

```http
GET /api/v1/<resource>/{id}
Authorization: Bearer <machine-token>
```

`Idempotency-Key` wajib untuk `POST`. Selain itu, source link `(connection_id, document_id, line_id)` harus unik. Retry dengan source yang sama tidak boleh membuat fakta kedua.

Setelah commit, modul menulis outbox event `<module>.<aggregate>.<action>.v1`. Sistem eksternal dapat polling atau menerima webhook outbound bertanda tangan bila webhook dikonfigurasi. Event tidak menggantikan API command.

| Kondisi | Status | Kode error |
| --- | --- | --- |
| Token koneksi tidak ada/tidak valid | 401 | `unauthenticated` |
| Koneksi tidak berhak pada organization hasil mapping | 403 | `organization_scope_forbidden` |
| Mapping, field, atau nilai request tidak valid | 422 | `validation_error` |
| Source record sudah dipakai dengan payload berbeda | 409 | `source_conflict` |
| Batas request terlampaui | 429 | `rate_limit_exceeded` |

Response memakai envelope `data` atau `error`; detail internal, credential, dan data tenant lain tidak pernah dikembalikan.

## Lihat juga

- [API dan integration bridge](04-api-and-integration.md) — kontrak dasar yang diperluas di sini
- [Standar module](02-module-standard.md) — batas kepemilikan data module
- [Number sequence](14-number-sequences.md) — idempotency dan penerbitan nomor
