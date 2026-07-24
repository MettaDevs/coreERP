# Database

Migration app berada di `migrations/` dan dijalankan oleh Laravel API melalui `api/app/Providers/AppServiceProvider.php`.

Database ini hanya dimiliki Management Asset. Referensi tenant dan unit organisasi disimpan sebagai ID opaque; tidak ada foreign key atau query ke database Core.
