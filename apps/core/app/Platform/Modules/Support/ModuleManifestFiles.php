<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Manifest lengkap sebuah module: `app.yaml` ditambah setiap berkas di folder `manifest/`.
 *
 * `app.yaml` memuat identitas module dan menunya. Daftar berkode — kebijakan data, entry point,
 * permission, privilege, duty, referensi nomor, dan jenis workflow — boleh ditulis di sana, tetapi
 * module yang dikerjakan banyak orang menaruhnya di `manifest/`, satu berkas per fitur yang
 * dikelompokkan per area. Laporan tidak termasuk: katalognya dibaca dari definisi laporan module
 * lewat `ModuleReportProvider::catalog()`. Bentuknya meniru Business Central: `app.json` hanya memuat
 * identitas, dan setiap objek adalah berkas sendiri di folder areanya. Tujuannya sama dengan
 * `routes/api/<fitur>.php`: orang yang mengerjakan fitur berbeda tidak menyunting berkas yang sama,
 * jadi merge mereka tidak bentrok.
 *
 * Penggabungannya hanya menyambung daftar: isi `app.yaml` lebih dulu, lalu berkas `manifest/`
 * menurut jalurnya. Urutan itu tidak menentukan isi katalog, karena pendaftaran mencocokkan setiap
 * baris menurut kodenya.
 *
 * Dua hal ditolak, bukan diabaikan:
 *
 * - Kunci selain daftar berkode di berkas `manifest/`. Identitas atau menu yang ditulis di sana
 *   tidak pernah dibaca, dan penulisnya akan mengira perubahannya berlaku.
 * - Kode yang dinyatakan dua kali. Validasi katalog juga menolaknya, tetapi tanpa menyebut di
 *   mana; setelah daftarnya tersebar di puluhan berkas, pesan tanpa nama berkas tidak bisa
 *   ditindaklanjuti.
 */
final class ModuleManifestFiles
{
    /**
     * Daftar berkode yang boleh ditulis di berkas `manifest/`: kunci level atas beserta kunci
     * daftar di bawahnya, atau null bila kunci level atas itu sendiri daftarnya.
     */
    private const LISTS = [
        'security' => ['data_policies', 'entry_points', 'permissions', 'privileges', 'duties'],
        'number_sequences' => ['references'],
        'workflow_types' => null,
    ];

    /**
     * @return array<mixed>
     *
     * @throws RuntimeException bila sebuah berkas bukan YAML yang sah, bentuknya salah, atau ada
     *                          kode yang dinyatakan dua kali
     */
    public static function read(string $folder): array
    {
        $manifest = self::parse($folder.'/app.yaml', 'app.yaml');
        $origins = [];

        foreach (self::paths() as $path) {
            self::remember($origins, $path, self::entries($manifest, $path, 'app.yaml'), 'app.yaml');
        }

        foreach (self::fragmentFiles($folder) as $name) {
            $fragment = self::parse($folder.'/'.$name, $name);
            self::assertOnlyLists($fragment, $name);

            foreach (self::paths() as $path) {
                $entries = self::entries($fragment, $path, $name);
                self::remember($origins, $path, $entries, $name);
                $manifest = self::append($manifest, $path, $entries);
            }
        }

        return $manifest;
    }

    /** @return list<list<string>> */
    private static function paths(): array
    {
        $paths = [];

        foreach (self::LISTS as $key => $children) {
            foreach ($children ?? [null] as $child) {
                $paths[] = $child === null ? [$key] : [$key, $child];
            }
        }

        return $paths;
    }

    /** @return array<mixed> */
    private static function parse(string $file, string $name): array
    {
        $content = Yaml::parseFile($file);

        if (! is_array($content)) {
            throw new RuntimeException(sprintf('`%s` harus berupa map di level teratas.', $name));
        }

        return $content;
    }

    /**
     * Berkas di `manifest/` beserta subfoldernya, sebagai jalur relatif yang terurut.
     *
     * @return list<string>
     */
    private static function fragmentFiles(string $folder): array
    {
        if (! is_dir($folder.'/manifest')) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder.'/manifest', RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $name = 'manifest/'.str_replace('\\', '/', substr($file->getPathname(), strlen($folder.'/manifest/')));

            // Ditolak, bukan dilewati: berkas `.yml` yang diam-diam tidak dibaca membuat isinya
            // hilang dari katalog tanpa satu pun kesalahan.
            if ($file->getExtension() === 'yml') {
                throw new RuntimeException(sprintf('`%s` tidak dibaca; berkas manifest memakai akhiran `.yaml`.', $name));
            }

            if ($file->getExtension() === 'yaml') {
                $files[] = $name;
            }
        }

        sort($files);

        return $files;
    }

    /** @param array<mixed> $fragment */
    private static function assertOnlyLists(array $fragment, string $name): void
    {
        $unknown = [];

        foreach ($fragment as $key => $value) {
            if (! array_key_exists($key, self::LISTS)) {
                $unknown[] = (string) $key;

                continue;
            }

            $children = self::LISTS[$key];

            if ($children === null || $value === null) {
                continue;
            }

            if (! is_array($value) || array_is_list($value)) {
                throw new RuntimeException(sprintf('`%s`: `%s` harus berupa map berisi %s.', $name, $key, implode(', ', $children)));
            }

            foreach (array_keys($value) as $child) {
                if (! in_array($child, $children, true)) {
                    $unknown[] = $key.'.'.$child;
                }
            }
        }

        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                '`%s` memuat `%s`, yang tidak dibaca dari berkas di `manifest/`. Yang boleh di sana hanya daftar %s; identitas dan menu module tetap di `app.yaml`.',
                $name,
                implode('`, `', $unknown),
                implode(', ', array_map(static fn (array $path): string => '`'.implode('.', $path).'`', self::paths())),
            ));
        }
    }

    /**
     * @param  array<mixed>  $tree
     * @param  list<string>  $path
     * @return list<mixed>
     */
    private static function entries(array $tree, array $path, string $name): array
    {
        $value = $tree;

        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return [];
            }

            $value = $value[$key];
        }

        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException(sprintf('`%s`: `%s` harus berupa daftar.', $name, implode('.', $path)));
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $origins
     * @param  list<string>  $path
     * @param  list<mixed>  $entries
     */
    private static function remember(array &$origins, array $path, array $entries, string $name): void
    {
        foreach ($entries as $entry) {
            $code = is_array($entry) ? ($entry['code'] ?? null) : null;

            // Entri tanpa kode dibiarkan lewat: validasi katalog yang melaporkannya, dengan
            // aturan yang sama untuk berkas mana pun.
            if (! is_string($code)) {
                continue;
            }

            $key = implode('.', $path).' '.$code;

            if (isset($origins[$key])) {
                throw new RuntimeException(sprintf(
                    'Kode `%s` pada `%s` dinyatakan dua kali: di `%s` dan `%s`.',
                    $code,
                    implode('.', $path),
                    $origins[$key],
                    $name,
                ));
            }

            $origins[$key] = $name;
        }
    }

    /**
     * @param  array<mixed>  $manifest
     * @param  list<string>  $path
     * @param  list<mixed>  $entries
     * @return array<mixed>
     */
    private static function append(array $manifest, array $path, array $entries): array
    {
        if ($entries === []) {
            return $manifest;
        }

        $merged = [...self::entries($manifest, $path, 'app.yaml'), ...$entries];

        if (count($path) === 1) {
            $manifest[$path[0]] = $merged;

            return $manifest;
        }

        $parent = is_array($manifest[$path[0]] ?? null) ? $manifest[$path[0]] : [];
        $parent[$path[1]] = $merged;
        $manifest[$path[0]] = $parent;

        return $manifest;
    }
}
