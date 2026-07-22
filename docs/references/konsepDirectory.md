> **Status: konsep historis.** Struktur target yang kanonik terdapat pada [docs/dev/06-worktree-target.md](dev/06-worktree-target.md). Dokumen ini dipertahankan sebagai catatan struktur awal.

/ERP METTA
│
├── /backend-laravel
│   ├── app/ (Sistem Core SaaS, Auth, Multi-tenant logic)
│   ├── routes/web.php
│   ├── composer.json (Menarik modul-modul di bawah ini untuk tes lokal)
│   └── /packages (Folder Modul Mentah)
│       ├── /module-pos (Backend POS)
│       ├── /module-booking (Backend Booking)
│       └── /module-studio
│
└── /frontend-react
    ├── package.json
    ├── .env.development (VITE_ENABLE_POS=true, VITE_ENABLE_BOOKING=true)
    ├── src/
    │   ├── /core (Login UI, Dashboard Utama, Sidebar)
    │   └── /modules (Folder Modul Mentah UI)
    │       ├── /pos (Komponen UI Kasir)
    │       ├── /booking (Komponen UI Booking)
    │       └── /studio
    └── vite.config.js


/packages
└── /module-pos
    ├── composer.json           <-- 1. Identitas & Dependensi Modul
    ├── /src
    │   ├── /Http
    │   │   └── /Controllers    <-- 2. Logika Bisnis (Contoh: PosController.php)
    │   ├── /Models             <-- 3. Model Database (Contoh: Transaction.php)
    │   └── /Providers
    │       └── PosServiceProvider.php <-- 4. "Jembatan" Penghubung ke Core
    ├── /routes
    │   └── api.php             <-- 5. Rute/Endpoint Khusus POS
    └── /database
        └── /migrations         <-- 6. Struktur Tabel Khusus POS


/frontend-react
├── package.json
├── .env                  <-- Variabel pengontrol modul (VITE_ENABLE_POS=true)
├── vite.config.js
└── /src
    ├── /core             <-- 1. Inti SaaS (Selalu ikut di-build)
    │   ├── /components   <-- UI Global (Sidebar, Navbar, Modal standar)
    │   ├── /contexts     <-- State Global (AuthContext, TenantContext)
    │   ├── /layouts      <-- Kerangka halaman (DashboardLayout)
    │   └── App.jsx       <-- 2. Entry Point & Router Utama
    │
    └── /modules          <-- 3. Folder Plugin/Modul (Bisa dibongkar pasang)
        ├── /pos
        │   ├── /components  <-- UI Khusus POS (ProductCard, CartList)
        │   ├── /pages       <-- Halaman Utama POS (PosTerminal.jsx)
        │   ├── /services    <-- API Caller khusus POS (posApi.js)
        │   └── index.jsx    <-- 4. Pintu Keluar Modul (Export routes & config)
        │
        └── /booking
            ├── /components
            ├── /pages
            └── index.jsx
