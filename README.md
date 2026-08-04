# CoreERP

CoreERP adalah monorepo untuk platform ERP modular API-first.

```text
apps/          aplikasi platform dan UI host
modules/       release unit bisnis mandiri
integrations/  bridge lintas module
packages/      SDK dan contract lintas aplikasi
deploy/        manifest deployment dan release tooling
docs/          desain kanonik
```

## Baru bergabung?

Mulai dari [panduan onboarding](docs/onboarding/index.md): jalur baca, setup lingkungan lokal, glosarium, dan cara berkontribusi.

## Situs dokumentasi

Seluruh dokumen di `docs/` dirender sebagai situs dengan pencarian dan navigasi.

**Membaca** — ikut nyala bersama stack lokal `erp-dev` di `http://localhost:18090`.

**Menulis** — jalankan dev server dengan hot reload:

```bash
cd docs && npm install && npm run docs:dev
```

`npm run docs:build` memvalidasi seluruh tautan antar dokumen; build gagal bila ada tautan yang putus.

## Dokumentasi desain

Mulai dari [desain kanonik](docs/dev/README.md), lalu gunakan dokumen sesuai pekerjaan:

- [Grand design dan boundary platform](docs/dev/01-grand-design.md)
- [Standar module](docs/dev/02-module-standard.md)
- [Release, on-prem, dan Customer Edition Manifest](docs/dev/03-release-and-on-prem.md)
- [API dan integration bridge](docs/dev/04-api-and-integration.md)
- [Target worktree](docs/dev/06-worktree-target.md)

Laravel Control Plane saat ini berada di `apps/control-plane`; jalankan perintah Composer atau NPM dari direktori tersebut.

Flow signup bisnis, invite code, role assignment, dan identity monitor dijelaskan di [Identity and Access](docs/dev/09-identity-and-access.md).
