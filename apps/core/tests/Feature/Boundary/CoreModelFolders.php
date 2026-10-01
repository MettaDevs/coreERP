<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

/**
 * Folder model Core yang dibaca penjaga batas.
 *
 * Sejak Core dipecah per lapis (docs/todo/lapis-core), model tidak lagi berkumpul di satu
 * `app/Models`: tiap fitur punya `app/<Lapis>/<Fitur>/Models`. Penjaga yang hanya membaca
 * `app/Models` akan diam-diam kehilangan model yang sudah pindah, dan tabelnya lolos tanpa
 * diperiksa. Daftar foldernya karena itu dibangun di satu tempat ini.
 *
 * `app/Models` sudah kosong dan dihapus, tetapi tetap dibaca bila muncul lagi: `php artisan
 * make:model` tanpa namespace lengkap membuat berkas di sana, dan model itu tidak boleh lolos dari
 * penjaga hanya karena salah tempat.
 */
final class CoreModelFolders
{
    /**
     * @return list<array{0: string, 1: string}> pasangan [folder, awalan namespace]
     */
    public static function all(): array
    {
        $folders = is_dir(app_path('Models')) ? [[app_path('Models'), 'App\\Models\\']] : [];

        foreach (['Platform', 'Foundation'] as $layer) {
            foreach (glob(app_path($layer.'/*/Models'), GLOB_ONLYDIR) ?: [] as $folder) {
                $feature = basename(dirname($folder));
                $folders[] = [$folder, "App\\{$layer}\\{$feature}\\Models\\"];
            }
        }

        return $folders;
    }

    /**
     * Nama kelas dari setiap berkas PHP di semua folder model Core, tanpa memeriksa isinya.
     *
     * @return list<string>
     */
    public static function classNames(): array
    {
        $classes = [];

        foreach (self::all() as [$folder, $namespace]) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $classes[] = $namespace.str_replace(
                    [$folder.DIRECTORY_SEPARATOR, '/', '.php'],
                    ['', '\\', ''],
                    $file->getPathname(),
                );
            }
        }

        return $classes;
    }
}
