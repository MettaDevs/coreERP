<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tiga pilihan pemasangan yang dulu ditambahkan tangan ke perintah pasang.
 *
 * Perintah yang dikeluarkan konsol selalu berbentuk `--token <token>` saja, sementara server klien yang
 * sudah punya reverse proxy, atau yang port 8000-nya terpakai, menuntut tanda tambahan. Operator lalu
 * menyuntingnya di chat sebelum menempel — dan perintah yang disunting tangan adalah perintah yang salah
 * ketik, di mesin tempat kesalahannya baru terlihat setelah pemasangan gagal separuh jalan.
 *
 * Kelas ini hanya menyusun teks perintahnya. Tidak ada yang tersimpan: tandanya dibaca `pasang.sh` di
 * server klien dan ditulis ke `.env` di sana, dan konsol tidak pernah perlu tahu lagi sesudahnya.
 */
final class InstallOptions
{
    private function __construct(
        public readonly bool $lockLicense,
        public readonly bool $externalProxy,
        public readonly ?int $appPort,
    ) {}

    public static function none(): self
    {
        return new self(false, false, null);
    }

    /**
     * @throws ValidationException Port yang bukan port.
     */
    public static function fromRequest(Request $request): self
    {
        $port = $request->input('app_port');
        $port = is_string($port) ? trim($port) : $port;

        if ($port === '' || $port === null) {
            $port = null;
        } else {
            // `filter_var` dan bukan `is_numeric`: "8000abc", "8e3", dan " 8000 " semuanya lolos dari
            // pemeriksaan yang lebih longgar, lalu menjadi argumen yang ditolak pasang.sh di server klien —
            // tempat kesalahannya jauh lebih mahal untuk ditemukan.
            $angka = filter_var($port, FILTER_VALIDATE_INT);

            if ($angka === false || $angka < 1 || $angka > 65535) {
                throw ValidationException::withMessages([
                    'app_port' => 'Port aplikasi harus angka 1 sampai 65535, atau dikosongkan untuk memakai bawaan 8000.',
                ]);
            }

            $port = $angka;
        }

        return new self(
            $request->boolean('kunci_lisensi'),
            $request->boolean('proxy_luar'),
            $port,
        );
    }

    /**
     * Tanda yang ditambahkan sesudah `--token <token>`, atau kosong.
     *
     * Urutannya tetap supaya dua perintah dengan pilihan sama terbaca sama, dan port disebut terakhir
     * karena ia satu-satunya yang membawa nilai.
     */
    public function flags(): string
    {
        $tanda = [];

        if ($this->lockLicense) {
            $tanda[] = '--kunci-lisensi';
        }

        if ($this->externalProxy) {
            $tanda[] = '--proxy-luar';
        }

        if ($this->appPort !== null) {
            $tanda[] = '--app-port '.$this->appPort;
        }

        return $tanda === [] ? '' : ' '.implode(' ', $tanda);
    }

    /**
     * Untuk jejak audit: apa yang dipilih operator, tanpa menyentuh token maupun kata sandi.
     *
     * @return array{lock_license: bool, external_proxy: bool, app_port: ?int}
     */
    public function forAudit(): array
    {
        return [
            'lock_license' => $this->lockLicense,
            'external_proxy' => $this->externalProxy,
            'app_port' => $this->appPort,
        ];
    }
}
