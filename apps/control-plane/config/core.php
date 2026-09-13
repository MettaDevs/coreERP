<?php

/*
 * Ke mana konsol ini memerintah, dan dengan kunci apa ia membuktikan boleh memerintah.
 *
 * Pembagiannya sudah dikunci rancangan: **pusat admin memerintah, Core mengerjakan.** Yang tahu
 * cara menjalankan migration module, membaca registry module, dan menyemai data awal hanyalah
 * Core. Jadi melahirkan pelanggan bukan `INSERT` dari sini melainkan satu panggilan HTTP ke sana —
 * kalau tidak, alur pembuatan tenant punya dua salinan, dan yang menyimpang di antara keduanya
 * adalah rantai izin.
 *
 * Kedua nilai di bawah dibaca dari env dan **tidak punya nilai bawaan yang bekerja**. Itu
 * disengaja: token yang punya nilai bawaan adalah token yang ikut terbawa ke mesin yang tidak
 * pernah menyetelnya, dan tidak ada seorang pun yang menyadarinya sampai ia dipakai.
 */
return [

    /*
     * Alamat runtime Core, tanpa garis miring di ujung.
     *
     * Salah setel di sini adalah sebab paling sering layar "Pelanggan baru" gagal — dan gagalnya
     * menyamar sebagai gangguan jaringan. Karena itu alamat ini ikut disebut apa adanya di setiap
     * pesan galat yang lahir dari panggilan gagal: yang harus terbaca operator adalah "alamat ini
     * yang dicoba", bukan "entah kenapa".
     */
    'base_url' => rtrim((string) env('COREERP_URL', 'http://localhost:8000'), '/'),

    /*
     * Kunci yang dikirim sebagai `Authorization: Bearer`, dan Core yang memeriksanya.
     *
     * Ia bukan token app module. Middleware `internal-app` yang sudah ada menuntut tiga header
     * sekaligus dan memeriksa bahwa app itu terpasang pada sebuah tenant — pusat admin bukan app
     * module, tidak terpasang di tenant mana pun, dan justru sedang bekerja pada tenant yang belum
     * punya apa-apa. Memaksakannya berarti menerbitkan kredensial app palsu untuk setiap pelanggan.
     */
    'token' => (string) env('CONTROL_PLANE_TOKEN', ''),

    /*
     * Batas menunggu, dalam detik.
     *
     * Melahirkan tenant menjalankan migration di sisi Core dan itu memang lama — satu lingkungan
     * demo terukur di bawah sepuluh detik pada mesin pengembang, dan mesin yang lebih sibuk akan
     * lebih lambat. Batas bawaan Guzzle (30 detik) ditulis di sini apa adanya supaya ia dapat
     * dinaikkan tanpa menyentuh kode ketika seseorang menemukannya terlalu pendek.
     */
    'timeout' => (int) env('COREERP_TIMEOUT', 30),

    /*
     * Batas menunggu khusus penyiapan lingkungan, dalam detik.
     *
     * Angkanya sepuluh kali lipat yang di atas, dan bukan karena berjaga-jaga. Menyiapkan
     * lingkungan membuat databasenya lebih dulu, menjalankan seluruh migration Core ke
     * dalamnya, lalu memasang tiap module yang dibeli tenantnya — masing-masing dengan
     * migration dan data awalnya sendiri. Yang di atas mengukur satu migration ke database
     * yang sudah ada; yang ini mengukur database yang belum ada sama sekali.
     *
     * Terputus di sini tidak membatalkan apa pun di sisi Core — perintahnya berjalan sampai
     * selesai dan memang aman diulang. Yang rusak hanya kepercayaan operator pada layarnya.
     */
    'provision_timeout' => (int) env('COREERP_PROVISION_TIMEOUT', 300),

    /*
     * Proxy yang boleh dipercaya header `X-Forwarded-*`-nya. Kosong berarti tidak satu pun.
     *
     * Dibaca `AppServiceProvider`, bukan `bootstrap/app.php` — alasannya tertulis di sana, dan ia
     * bukan selera: closure middleware berjalan sebelum berkas env dimuat.
     */
    'trusted_proxies' => env('COREERP_TRUSTED_PROXIES'),

];
