<?php

namespace App\Support\Modules\Contracts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Setelan bawaan log perubahan untuk satu tabel, didaftarkan pemilik tabelnya dari migration.
 *
 * Padanannya Change Log Setup di Business Central, tetapi bawaannya dikirim bersama produk: module menyatakan
 * field mana yang layak dicatat beserta namanya di layar, dan tenant yang belum mengatur apa pun langsung
 * mendapat riwayat untuk field itu. Tenant yang menyimpan setelannya sendiri di layar Log perubahan
 * menggantikan bawaan ini untuk tabel tersebut.
 *
 * Dipanggil dari **migration**, bukan seeder: bawaan baru harus sampai ke tenant yang sudah memasang module,
 * dan migration module berjalan di setiap database tenant. Barisnya ber-`tenant_id` kosong. Field yang
 * tidak lagi disebut tidak dihapus — penghapusan fisik tidak dipakai di repo ini — melainkan dimatikan.
 */
final class ChangeLogDefaults
{
    /**
     * @param  array<string, string>  $fields  kolom => nama yang dibaca pengguna
     */
    public static function register(string $table, string $caption, array $fields, bool $insertion = true, bool $modification = true, bool $deletion = false): void
    {
        $now = now();

        DB::transaction(function () use ($table, $caption, $fields, $insertion, $modification, $deletion, $now): void {
            self::upsert('change_log_setup_tables', ['table_name' => $table], [
                'table_caption' => $caption,
                'log_insertion' => $insertion ? 'some' : 'none',
                'log_modification' => $modification ? 'some' : 'none',
                'log_deletion' => $deletion ? 'some' : 'none',
                'updated_at' => $now,
            ]);

            DB::table('change_log_setup_fields')->whereNull('tenant_id')->where('table_name', $table)
                ->whereNotIn('field_name', array_keys($fields))
                ->update(['log_insertion' => false, 'log_modification' => false, 'log_deletion' => false, 'updated_at' => $now]);

            foreach ($fields as $field => $fieldCaption) {
                self::upsert('change_log_setup_fields', ['table_name' => $table, 'field_name' => $field], [
                    'field_caption' => $fieldCaption,
                    'log_insertion' => $insertion,
                    'log_modification' => $modification,
                    'log_deletion' => $deletion,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * Mematikan bawaan satu tabel, dipakai `down()` migration pendaftarnya. Barisnya tidak dihapus: riwayat
     * lama masih menyebut nama field-nya.
     */
    public static function disable(string $table): void
    {
        DB::table('change_log_setup_tables')->whereNull('tenant_id')->where('table_name', $table)
            ->update(['log_insertion' => 'none', 'log_modification' => 'none', 'log_deletion' => 'none', 'updated_at' => now()]);
    }

    /**
     * @param  array<string, string>  $key
     * @param  array<string, mixed>  $values
     */
    private static function upsert(string $table, array $key, array $values): void
    {
        $updated = DB::table($table)->whereNull('tenant_id')->where($key)->update($values);

        if ($updated === 0) {
            DB::table($table)->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => null, ...$key, ...$values, 'created_at' => $values['updated_at'],
            ]);
        }
    }
}
