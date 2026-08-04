# Berkontribusi ke CoreERP

Panduan lengkapnya ada di dokumentasi, bukan di berkas ini — supaya hanya ada satu tempat yang perlu dijaga tetap benar.

| Kalau kamu mau… | Buka |
| --- | --- |
| Baru bergabung dan belum tahu mulai dari mana | [docs/onboarding/index.md](docs/onboarding/index.md) |
| Menyiapkan lingkungan lokal | [docs/onboarding/setup.md](docs/onboarding/setup.md) |
| Tahu aturan kerja dan alur PR | [docs/onboarding/kontribusi.md](docs/onboarding/kontribusi.md) |
| Tahu kapan pekerjaan boleh disebut selesai | [docs/onboarding/definition-of-done.md](docs/onboarding/definition-of-done.md) |
| Membuat app, master, transaksi, atau integrasi baru | [docs/dev/18-module-discovery-and-decision-gate.md](docs/dev/18-module-discovery-and-decision-gate.md) |

Untuk membaca semuanya sebagai situs dengan pencarian:

```bash
cd docs && npm install && npm run docs:dev
```

## Tiga aturan yang paling sering dilanggar

1. **Jangan query database lintas app.** Pakai REST/OpenAPI atau event/AsyncAPI.
2. **Jangan hardcode nama** perusahaan, orang, atau modul besar. Semuanya konfigurasi di database.
3. **Jangan menyimpulkan status lifecycle.** Katalog, entitlement, installation, dan runtime health adalah empat fakta terpisah.

Aturan main harian yang berlaku untuk semua repo ada di [AGENTS.md](AGENTS.md).
