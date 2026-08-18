# API, event, dan integrasi module

## Satu aturan utama per jenis komunikasi

| Kebutuhan | Standar | Contoh |
| --- | --- | --- |
| Request/response browser, mobile, partner, dan admin | REST/JSON + OpenAPI 3.1 | `POST /api/v1/orders` |
| Dampak lintas database yang boleh async | Event + outbox/inbox + AsyncAPI | `pos.payment-captured.v1` |
| Streaming/latency internal yang terbukti tidak memenuhi SLO setelah optimasi | gRPC + Protobuf melalui ADR | Bukan standar v1 |
| Komposisi read model lintas module | REST BFF dahulu; GraphQL gateway melalui ADR | Dashboard holding |

REST adalah perintah atau permintaan data: "buat order" atau "tampilkan catalog". Event adalah fakta masa lalu: "order sudah dibayar". Event bukan pengganti REST, dan REST bukan transaction coordinator lintas database.

## Event backbone untuk banyak module

Event broker adalah backbone publish/subscribe bersama. Ia mencegah pola integration satu-ke-satu yang akan tumbuh tidak terkendali ketika module bertambah. Setiap module hanya mendeklarasikan event yang dipublish dan event yang disubscribe dalam AsyncAPI-nya.

```mermaid
flowchart LR
    POS[POS API + pos_db] -->|domain events| BUS[Event broker]
    BOOK[Booking API + booking_db] -->|domain events| BUS
    PUR[Purchasing API + purchasing_db] -->|domain events| BUS

    BUS --> ACC[Accounting API + accounting_db]
    BUS --> REP[Reporting projection]
    BUS --> NOTIF[Notification service]
    BUS --> ADDON[Customer addon]
```

Bridge bukan kewajiban bagi setiap pasangan module. Ia hanya digunakan bila integration memerlukan state mapping, orkestrasi, atau policy khusus. Contoh: POS-Booking Bridge menyimpan relasi booking ke sales intent dan mengorkestrasi alur pembayaran. Sebaliknya, Accounting atau Reporting biasanya cukup menjadi consumer event publik dari banyak module tanpa bridge per pasangan.

## Kontrak wajib

Setiap API versioned memakai prefix `/api/v1`. OpenAPI mendefinisikan auth scheme, request, response, error, pagination, idempotency key, dan rate limit. Event memakai nama `module.aggregate.action.vN`, metadata minimum berikut:

```json
{
  "id": "evt_01...",
  "type": "pos.payment-captured.v1",
  "occurred_at": "2026-07-20T10:00:00Z",
  "tenant_id": "ten_01...",
  "legal_entity_id": "org_legal_01...",
  "org_unit_id": "org_01...",
  "correlation_id": "req_01...",
  "data": {}
}
```

`tenant_id` wajib pada event tenant-owned. `legal_entity_id` wajib ketika fakta mempunyai konsekuensi hukum/akuntansi, sedangkan `org_unit_id` dipakai bila fakta dimiliki operating unit. Ketiganya mengikuti [model tenant dan organisasi](01a-tenant-and-org-hierarchy.md); producer tidak menebak legal entity dari posisi organization pada hierarchy saat event dikonsumsi kemudian.

Producer menyimpan payload ke `outbox_events` dalam transaksi yang sama dengan data bisnis. Publisher mengirimkannya setelah commit. Consumer menyimpan message ID pada inbox/processed-events sehingga retry tidak menciptakan efek ganda.

### Kontrak dijaga pemeriksa, bukan kedisiplinan

**Tidak ada satu test pun yang gagal ketika sebuah endpoint absen dari kontrak.** Itu sebabnya endpoint tak terdokumentasi bisa bertahan lama sementara seluruh test hijau — dan kenapa setiap app wajib punya pemeriksa cakupan yang jalan di CI, bukan hanya Core.

Pemeriksa membandingkan rute yang benar-benar terdaftar terhadap kontraknya, dua arah: rute tanpa kontrak, dan kontrak tanpa rute. Tiga hal menentukan apakah ia berguna:

- **Baca rute dari framework, bukan dari teks berkas rute.** Rute yang didaftarkan lewat loop tidak pernah muncul sebagai literal, jadi pencocokan teks melapor bersih sambil melewatkan puluhan rute. `php artisan route:list --json` adalah daftar yang berwenang.
- **Mekarkan jalur bertemplat yang parameternya ber-`enum`** sebelum membandingkan. Satu jalur bertemplat sah mendokumentasikan beberapa resource; dibandingkan apa adanya ia melaporkan endpoint yang sudah terdokumentasi sebagai hilang. Pemeriksa yang sering salah memberi peringatan akan berhenti dipercaya lalu diabaikan.
- **Sebut celah yang ditunda, jangan maafkan diam-diam.** Celah yang diketahui dan ada pemiliknya masuk daftar pengecualian beserta alasannya, dicetak tiap kali pemeriksa jalan. Entry yang tidak lagi cocok dengan rute hidup harus gagal, supaya pengecualian basi tidak memaafkan rute lain yang kelak memakai jalur itu.

Kebalikannya juga berlaku: **kode tidak boleh menerima field yang dilarang kontraknya.** Kalau skema memakai `additionalProperties: false` dan field itu tidak ada di dalamnya, ia tidak akan pernah tiba lewat jalur yang sah. Handler yang tetap memvalidasinya mengiklankan kemampuan yang tidak ada, dan pembaca berikutnya menyimpulkan penerbitnya bisa mengirimkannya. Kalau field itu memang ditunda, yang menunggu adalah kodenya, bukan kontraknya.

Implementasi rujukan: `contracts/check-contract-coverage.py` di Control Plane dan di app Management Aset.

## POS dan Booking tanpa shared database

```mermaid
sequenceDiagram
    participant B as Booking API / booking_db
    participant X as POS-Booking Bridge / bridge_db
    participant P as POS API / pos_db

    B->>B: booking confirmed + outbox
    B-->>X: booking.confirmed.v1
    X->>P: POST /api/v1/sales-intents
    P->>P: create sales intent + outbox
    P-->>X: pos.payment-captured.v1
    X->>B: POST /api/v1/bookings/{id}/payment-confirmations
```

Bridge menyimpan mapping `booking_id <-> pos_sales_intent_id`, inbox, retry state, dan audit di `bridge_db`. POS dan Booking tetap dapat di-install sendiri. Bridge hanya bisa enabled bila keduanya available dan versi contract kompatibel.

## Extension point

Module hanya boleh membuka extension point yang eksplisit:

- Read API untuk data yang diizinkan.
- Command API dengan authorization dan idempotency.
- Domain event publish/subscribe yang versioned.
- UI host SDK untuk navigation, route, dan permitted widgets.
- Webhook outbound dengan signature dan retry.

Addon tidak boleh mendapat database credential module lain, meng-import model internal, atau menambahkan route ke service module lain.

## Lihat juga

- [Standar module](02-module-standard.md) — kepemilikan database yang membuat kontrak ini perlu
- [Integrasi sistem eksternal](12-external-module-integration.md) — penerapan kontrak untuk pihak luar
- [Reporting dan read replica](07-reporting-and-replicas.md) — konsumsi event untuk laporan gabungan
- [Query scope dan schema](08-query-scopes-and-schema.md) — field scope yang dibawa envelope event
