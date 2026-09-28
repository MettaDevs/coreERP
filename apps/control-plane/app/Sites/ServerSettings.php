<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Closure;
use Illuminate\Http\Request;

/**
 * Isian setelan server klien: alamat server dan jendela pembaruan.
 *
 * Dua pintu memakainya — panel "Server klien" di halaman lingkungan dan halaman rincian server klien —
 * dan keduanya harus menolak hal yang sama dengan kalimat yang sama. Aturan yang disalin ke dua controller
 * menyimpang di salinan yang paling jarang dipakai.
 *
 * Alamat aplikasi tidak ada di sini. Ia diturunkan dari lingkungannya (`Site::appUrl()`), dan alamat milik
 * klien sendiri belum didukung. Isian yang masih mengirim `address` diabaikan.
 *
 * Keduanya boleh kosong saat disimpan. Alamat server baru diwajibkan saat perintah pasang dibuat, karena record
 * DNS alamat aplikasi menunjuk ke sana (`ClientServerSetup::issueInstallCommand`).
 */
final class ServerSettings
{
    /**
     * @return array{server_address: ?string, update_window_start: ?string, update_window_end: ?string}
     */
    public static function fromRequest(Request $request): array
    {
        $request->merge(['server_address' => ServerAddress::normalize(self::text($request->input('server_address')))]);

        $input = $request->validate([
            'server_address' => ['nullable', 'string', 'max:255', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! ServerAddress::valid($value)) {
                    $fail(ServerAddress::MESSAGE);
                }
            }],
            'update_window_start' => ['nullable', 'date_format:H:i', 'required_with:update_window_end'],
            'update_window_end' => ['nullable', 'date_format:H:i', 'required_with:update_window_start'],
        ], [
            'update_window_start.required_with' => 'Jendela pembaruan butuh jam mulai dan jam selesai.',
            'update_window_end.required_with' => 'Jendela pembaruan butuh jam mulai dan jam selesai.',
        ]);

        return [
            'server_address' => self::text($input['server_address'] ?? null),
            'update_window_start' => self::text($input['update_window_start'] ?? null),
            'update_window_end' => self::text($input['update_window_end'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
