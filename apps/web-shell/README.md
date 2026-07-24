# Web Shell

React SPA yang menjadi header bersama dan launcher aplikasi. Ia membaca `GET /api/v1/launch-manifest` dari Control Plane, lalu hanya menampilkan aplikasi yang siap dibuka oleh user aktif.

Web Shell tidak memiliki domain atau database aplikasi bisnis. Klik aplikasi mengarahkan user ke UI artifact app tersebut dengan session SSO yang sama.

Untuk development, salin `.env.example` ke `.env` bila Control Plane tidak berjalan di `http://127.0.0.1:8000`, lalu jalankan `npm run dev`. Vite meneruskan request `/api` ke Control Plane agar launcher tetap memakai session yang sama.
