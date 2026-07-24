# App ERP Management Asset

Management Asset adalah app bisnis mandiri. Repository ini memiliki API, UI, database, kontrak, dan deployment sendiri.

## Menjalankan lokal

1. Salin `api/.env.example` menjadi `api/.env`, lalu atur database milik app ini.
2. Jalankan `composer install` dan `php artisan migrate` dari `api/`.
3. Jalankan `npm install` dan `npm run dev` dari `ui/`.

API health tersedia pada `GET /api/v1/health`.

Endpoint bisnis belum dibuka sampai integrasi autentikasi dari Core mengirim `TenantContext` tepercaya. Tidak ada endpoint yang menerima `tenant_id` dari input pengguna.
