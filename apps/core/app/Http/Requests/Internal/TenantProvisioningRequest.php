<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Models\Environment;
use App\Support\ControlPlane\TemporaryPassword;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Isi perintah "lahirkan tenant" yang datang dari pusat admin.
 *
 * Nama fieldnya bahasa Indonesia karena pemanggilnya konsol operator, bukan app module: yang
 * membaca dan menulis bentuk ini orang yang sama yang membaca layarnya.
 *
 * Tidak ada field kata sandi, dan ketiadaannya disengaja. Kata sandi sementara dibuat Core sendiri
 * — lihat {@see TemporaryPassword}. Menerimanya dari pemanggil berarti menerima kata sandi yang sudah
 * diketahui pemanggil sebelum pemiliknya pernah melihatnya.
 */
final class TenantProvisioningRequest extends FormRequest
{
    /** Yang menentukan boleh-tidaknya adalah token pusat admin di middleware, bukan sesi. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Jenis lingkungan pertama, dipersempit ke tiga nilai yang memang mungkin.
     *
     * Aturan validasi di atas sudah menjaminnya, tetapi jaminan itu tidak terbaca oleh analisis
     * statis — dan yang menerima nilainya, `RegisterBusiness`, memang menuntut salah satu dari
     * ketiganya. Dipersempit di sini, di tempat yang mengetahui aturannya, alih-alih dipaksa
     * dengan cast di pemanggil.
     *
     * @return 'production'|'demo'|'none'
     */
    public function firstEnvironment(): string
    {
        $nilai = $this->string('first_environment', 'production')->toString();

        return match ($nilai) {
            'demo' => 'demo',
            'none' => 'none',
            default => 'production',
        };
    }

    public function firstEnvironmentExpiresAt(): ?string
    {
        $nilai = $this->input('first_environment_expires_at');

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }

    /**
     * Tempat lingkungan pertama berjalan, dipersempit dengan alasan yang sama seperti jenisnya.
     *
     * @return 'provider'|'client_server'
     */
    public function firstEnvironmentHosting(): string
    {
        return $this->string('first_environment_hosting', Environment::HOSTING_PROVIDER)->toString() === Environment::HOSTING_CLIENT_SERVER
            ? Environment::HOSTING_CLIENT_SERVER
            : Environment::HOSTING_PROVIDER;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            /*
             * Jenis lingkungan pertama, dan ia OPSIONAL dengan bawaan `production`.
             *
             * Opsional supaya pemanggil lama — dan setiap test yang sudah ada — tidak berubah
             * perilakunya. Yang berubah hanya bahwa operator sekarang DAPAT memilih: calon
             * pelanggan yang belum tentu jadi membeli tidak perlu diberi produksi kosong yang
             * tidak pernah dipakai siapa pun sekaligus mengunci alamatnya.
             */
            'first_environment' => ['sometimes', 'in:production,demo,none'],
            /*
             * Wajib bila demo, dilarang bila bukan.
             *
             * Aturan yang sama sudah berdiri di layar pembuatan lingkungan, dan alasannya sama:
             * demo tanpa tanggal berakhir tinggal selamanya, dan tidak ada yang menyadarinya
             * sampai disknya penuh.
             */
            'first_environment_expires_at' => [
                'exclude_unless:first_environment,demo',
                'required',
                'date',
                'after:today',
            ],
            /*
             * Tempat lingkungan pertama berjalan, OPSIONAL dengan bawaan `provider`.
             *
             * `client_server` berarti produksi tenant ini dipasang di server milik klien lewat satu
             * perintah pasang. Hanya produksi yang boleh: demo dan sandbox tetap di server kami, dan
             * tenant tanpa lingkungan tidak punya apa pun untuk dipasang di mana pun. Ditolak di sini
             * dengan 422 yang menyebut field-nya; constraint registry menolaknya sekali lagi, tetapi
             * dari dalam transaksi dan sebagai 500.
             */
            'first_environment_hosting' => [
                'sometimes',
                'string',
                'in:'.Environment::HOSTING_PROVIDER.','.Environment::HOSTING_CLIENT_SERVER,
                // Masukan mentahnya yang dibaca, bukan `firstEnvironment()`: yang itu memetakan nilai
                // tak dikenal ke `production`, dan penjaga ini tidak boleh lolos karena pemetaan itu.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === Environment::HOSTING_CLIENT_SERVER && $this->input('first_environment', 'production') !== 'production') {
                        $fail('Server klien hanya menjalankan lingkungan produksi. Demo dan sandbox tetap berjalan di server kami.');
                    }
                },
            ],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'app_ids' => ['required', 'array', 'min:1'],
            'app_ids.*' => [
                'required',
                'string',
                'distinct',
                // App yang belum tersedia tidak boleh dijual. Ditolak di sini, bukan di dalam
                // aksi, supaya operator mendapat 422 yang menyebut field-nya alih-alih 500 dari
                // graph dependency yang sudah terlanjur berada di dalam transaksi.
                Rule::exists('apps', 'id')->where('status', 'available'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('admin_email');

        // Dirapikan sebelum diperiksa, bukan sesudah. `unique:users,email` membandingkan apa
        // adanya, sehingga "Owner@Contoh.test" lolos pemeriksaan lalu disimpan sebagai
        // "owner@contoh.test" oleh aksi pendaftaran — dua akun, satu email.
        if (is_string($email)) {
            $this->merge(['admin_email' => Str::lower(trim($email))]);
        }
    }

    /** @return list<string> */
    public function appIds(): array
    {
        return array_values($this->collect('app_ids')->map(fn (mixed $id): string => (string) $id)->all());
    }
}
