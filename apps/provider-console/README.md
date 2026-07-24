# Provider Console

React SPA untuk operasi vendor: tenant SaaS, entitlement, placement, release, dan observability. Aplikasi ini hanya memakai Control Plane API; tidak memuat UI bisnis module ERP.

Rujukan: [grand design](../../docs/dev/01-grand-design.md), [API governance](../../docs/dev/04-api-and-integration.md), dan [target worktree](../../docs/dev/06-worktree-target.md).

Versi awal menyediakan pembacaan katalog aplikasi melalui `GET /api/v1/provider/apps`. Salin `.env.example` ke `.env` bila URL Control Plane berbeda, lalu jalankan `npm run dev`.
