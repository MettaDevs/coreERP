<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * Isi `app.yaml` sebuah module, sebatas yang dibutuhkan runtime untuk memuatnya.
 *
 * Ini bukan salinan lengkap manifest. Entry point, permission, privilege, duty, referensi
 * nomor, dan tipe workflow tetap dibaca aksi pendaftaran katalog yang sudah ada; yang di
 * sini hanya yang diperlukan untuk menemukan, menamai, dan memuat module-nya.
 */
final readonly class ModuleManifest
{
    public function __construct(
        public string $id,
        public string $nama,
        public string $versi,
        public string $penerbit,
        public string $jenis,
        public string $awalanTabel,
        public string $folder,
        /** @var list<string> id module lain yang wajib terpasang lebih dulu */
        public array $dependency = [],
    ) {}

    /**
     * Module contoh yang hidup di repo sebagai bahan uji penjaga batas.
     *
     * Ia ikut terpasang di lingkungan pengembangan dan **tidak boleh** ikut ke edisi
     * pelanggan mana pun. Sebuah menu bernama "Contoh A" di layar pelanggan adalah
     * kegagalan yang tidak boleh mungkin terjadi.
     */
    public function bahanUjiInternal(): bool
    {
        return $this->jenis === 'internal-fixture';
    }

    public function penyediaLayanan(): string
    {
        return sprintf(
            'Modules\\%s\\%s\\ModuleServiceProvider',
            $this->studly($this->penerbit),
            $this->studly(basename($this->folder)),
        );
    }

    public function folderMigrasi(): string
    {
        return $this->folder.'/database/migrations';
    }

    private function studly(string $nama): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $nama)));
    }
}
