<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Closure;
use Illuminate\Http\Request;

/**
 * Isian setelan server klien: alamat server, alamat aplikasi, dan jendela pembaruan.
 *
 * Dua pintu memakainya — panel "Server klien" di halaman lingkungan dan halaman rincian server klien —
 * dan keduanya harus menolak hal yang sama dengan kalimat yang sama. Aturan yang disalin ke dua controller
 * menyimpang di salinan yang paling jarang dipakai.
 *
 * Semuanya boleh kosong. Pemasangan tidak menunggu satu pun, dan kriteria terima pemasangan satu perintah
 * menuntut tidak ada isian yang wajib diketik.
 */
final class ServerSettings
{
    /**
     * @return array{server_address: ?string, address: ?string, update_window_start: ?string, update_window_end: ?string}
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
            'address' => ['nullable', 'url:https,http', 'max:255'],
            'update_window_start' => ['nullable', 'date_format:H:i', 'required_with:update_window_end'],
            'update_window_end' => ['nullable', 'date_format:H:i', 'required_with:update_window_start'],
        ], [
            'address.url' => 'Alamat aplikasi harus berupa alamat lengkap, misalnya https://erp.klinik.id.',
            'update_window_start.required_with' => 'Jendela pembaruan butuh jam mulai dan jam selesai.',
            'update_window_end.required_with' => 'Jendela pembaruan butuh jam mulai dan jam selesai.',
        ]);

        return [
            'server_address' => self::text($input['server_address'] ?? null),
            'address' => self::text($input['address'] ?? null),
            'update_window_start' => self::text($input['update_window_start'] ?? null),
            'update_window_end' => self::text($input['update_window_end'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
