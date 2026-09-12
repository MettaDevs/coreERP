<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sebuah akun dapat lahir dengan kata sandi yang bukan milik pemiliknya.
 *
 * Sampai hari ini setiap akun di sini dibuat orangnya sendiri lewat layar pendaftaran, jadi kata
 * sandinya tidak pernah dilihat siapa pun kecuali dia. Pintu operator mengubah itu: operator
 * mengetikkan nama dan email pelanggan, sistem membuatkan kata sandi sementara, dan kata sandi itu
 * **melewati tangan operator** — dibacakan di telepon atau ditempelkan ke chat.
 *
 * Kolom ini yang membuat keadaan itu berumur pendek. Selama ia menyala, satu-satunya tempat yang
 * dapat dituju pemiliknya adalah layar ganti kata sandi; ia padam pada detik kata sandinya benar
 * benar diganti, dan sejak itu operator tidak lagi memegang apa pun.
 *
 * Bawaannya `false`, dan itu bukan sekadar nilai default yang aman. Ia yang menjamin pendaftaran
 * mandiri — beserta setiap akun yang sudah ada — tidak berubah perilakunya sama sekali: penjaga
 * yang membaca kolom ini melepaskan siapa pun yang tidak ditandai sebelum memeriksa apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
