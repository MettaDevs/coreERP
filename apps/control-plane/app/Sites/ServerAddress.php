<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

/**
 * Alamat mesin server klien: IPv4, IPv6, atau nama host — tanpa skema, jalur, maupun port.
 *
 * Kolomnya `sites.server_address`, dan alasan ia terpisah dari alamat aplikasi ditulis di migration
 * `add_server_address_to_sites`.
 *
 * ## Dirapikan dulu, baru diperiksa
 *
 * Spasi di ujung dan huruf besar dibuang, karena keduanya lahir dari menyalin alamat dari panel penyedia
 * VPS, bukan dari maksud operator. Yang **tidak** dirapikan diam-diam: skema, jalur, dan port. Alamat
 * seperti `https://103.122.2.72:8443/` berarti operator menempelkan alamat aplikasi ke kolom yang salah,
 * dan membuang bagiannya tanpa berkata apa-apa akan menyimpan tebakan.
 */
final class ServerAddress
{
    public const MESSAGE = 'Isi dengan IP atau nama host server saja, misalnya 103.122.2.72 atau vps1.klinik.id — tanpa https://, port, atau garis miring.';

    public static function normalize(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    public static function valid(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Nama host: label huruf, angka, dan tanda hubung, dipisah titik, paling sedikit dua label. Satu
        // label saja (`vps1`) hanya bermakna di jaringan milik seseorang, dan admin.erp tidak berada di sana.
        return strlen($value) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value) === 1
            && preg_match('/^[0-9.]+$/', $value) !== 1;
    }
}
