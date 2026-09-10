<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan rilis berhenti menyebut dua image dan mulai menyebut satu image edisi.
 *
 * `api_image` dan `ui_image` berasal dari masa setiap app berjalan sebagai dua container.
 * Modul kini berjalan di dalam runtime Core dan UI-nya ikut dibangun ke dalam shell, jadi
 * tidak ada image UI yang dibangun siapa pun dan tidak ada image API yang berdiri sendiri.
 * Yang dirilis satu: image edisi.
 *
 * **Baris rilis lama punya jalan, dan tidak ada baris yang dihapus.** `edition_image` diisi
 * dari `api_image` karena image API adalah image yang benar-benar menjalankan kode app;
 * image UI hanya membawa berkas statis yang sekarang ikut ke dalam image edisi. Digest UI
 * tidak dipindahkan ke kolom mana pun: ia menunjuk artifact yang tidak dibangun lagi, dan
 * menyimpan sidik jari sesuatu yang tidak bisa ditarik siapa pun hanya membuat orang
 * berikutnya percaya artifact itu masih ada.
 *
 * Ketiga nama layanan dilonggarkan, bukan dibuang. `DeployAppPlacement` dan
 * `app:render-proxy-config` masih membacanya untuk app yang memang masih berjalan sebagai
 * container sendiri, dan keduanya sudah menangani nilai kosong. Yang berubah: rilis baru
 * tidak wajib mengarang nama layanan yang tidak ada.
 *
 * Setiap langkah dijaga pemeriksaan keberadaan kolom supaya migration ini aman dijalankan
 * dua kali. Pembaruan on-prem dijalankan admin pelanggan yang tidak punya cara tahu apakah
 * sebuah perintah sudah pernah jalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('app_releases', 'edition_image')) {
            // Ditambahkan nullable lebih dulu supaya baris lama sempat diisi sebelum
            // kolomnya diwajibkan.
            Schema::table('app_releases', function (Blueprint $table): void {
                $table->string('edition_image', 500)->nullable();
            });
        }

        if (Schema::hasColumn('app_releases', 'api_image')) {
            DB::table('app_releases')
                ->whereNull('edition_image')
                ->update(['edition_image' => DB::raw('api_image')]);
        }

        Schema::table('app_releases', function (Blueprint $table): void {
            // `change()` membuang modifier yang tidak disebut ulang, jadi panjang kolom
            // ditulis lagi apa adanya.
            $table->string('edition_image', 500)->nullable(false)->change();
            $table->string('api_service', 120)->nullable()->change();
            $table->string('ui_service', 120)->nullable()->change();
            $table->string('database_service', 120)->nullable()->change();
        });

        $kolomLama = array_values(array_filter(
            ['api_image', 'ui_image'],
            static fn (string $kolom): bool => Schema::hasColumn('app_releases', $kolom),
        ));

        if ($kolomLama !== []) {
            Schema::table('app_releases', function (Blueprint $table) use ($kolomLama): void {
                $table->dropColumn($kolomLama);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('app_releases', 'api_image')) {
            Schema::table('app_releases', function (Blueprint $table): void {
                $table->string('api_image', 500)->nullable();
                // `ui_image` kembali sebagai nullable dan tetap kosong. Digest image UI
                // tidak disimpan di mana pun setelah maju, dan mengarang satu digest lebih
                // buruk daripada mundur dengan kolom kosong.
                $table->string('ui_image', 500)->nullable();
            });
        }

        if (Schema::hasColumn('app_releases', 'edition_image')) {
            DB::table('app_releases')
                ->whereNull('api_image')
                ->update(['api_image' => DB::raw('edition_image')]);

            Schema::table('app_releases', function (Blueprint $table): void {
                $table->dropColumn('edition_image');
            });
        }

        // Ketiga nama layanan dibiarkan nullable. Rilis yang tercatat setelah migration ini
        // maju memang tidak punya nama layanan, jadi mewajibkannya kembali berarti menolak
        // mundur atau mengarang nama; keduanya lebih merusak daripada kolom yang longgar.
    }
};
