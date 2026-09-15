<?php

declare(strict_types=1);

namespace ControlPlane\Registry;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Robot sistem Harbor yang dipakai konsol ini untuk menerbitkan dan menghapus robot situs.
 *
 * Disimpan di `console_settings`, bukan `.env`: rahasia robot diputar lewat runbook tanpa men-deploy
 * ulang konsol, dan nilainya terenkripsi `Crypt` sehingga isi tabel yang bocor tanpa `APP_KEY` tidak
 * membuka registry. Rahasianya tidak pernah dikembalikan ke layar mana pun — hanya namanya.
 *
 * Izin robot ini sengaja sempit, dan bentuknya diukur di Harbor v2.15.2, bukan ditebak:
 * robot/create, robot/delete, robot/list, robot/read, dan repository/pull, semuanya di project
 * `coreerp`. Harbor menolak robot membuat robot lain yang izinnya lebih luas dari miliknya, jadi
 * repository/pull wajib ada; dan Harbor tidak mengenal izin robot/update, sehingga konsol tidak dapat
 * memutar rahasia robot situs — ia menghapus lalu membuat ulang.
 */
final class RegistrySettings
{
    public const ROBOT_NAME = 'registry.robot_name';

    public const ROBOT_SECRET = 'registry.robot_secret';

    /** @return array{name: string, secret: string}|null */
    public function robot(): ?array
    {
        $rows = DB::table('console_settings')
            ->whereIn('key', [self::ROBOT_NAME, self::ROBOT_SECRET])
            ->pluck('value', 'key');

        $name = $rows[self::ROBOT_NAME] ?? null;
        $secret = $rows[self::ROBOT_SECRET] ?? null;

        if (! is_string($name) || $name === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        try {
            $secret = Crypt::decryptString($secret);
        } catch (DecryptException) {
            // APP_KEY berganti sesudah rahasia disimpan. Diperlakukan sama dengan belum disetel: konsol
            // tidak dapat menerbitkan kredensial, dan halaman Pengaturan menyebut sebabnya.
            return null;
        }

        return ['name' => $name, 'secret' => $secret];
    }

    public function robotName(): ?string
    {
        $name = DB::table('console_settings')->where('key', self::ROBOT_NAME)->value('value');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function storeRobot(string $name, string $secret, ?int $userId): void
    {
        DB::transaction(function () use ($name, $secret, $userId): void {
            foreach ([self::ROBOT_NAME => $name, self::ROBOT_SECRET => Crypt::encryptString($secret)] as $key => $value) {
                DB::table('console_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value, 'updated_at' => now(), 'updated_by' => $userId],
                );
            }
        });
    }

    public function host(): string
    {
        return (string) config('sites.registry_host');
    }

    public function project(): string
    {
        return (string) config('sites.registry_project');
    }
}
