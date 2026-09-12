<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Support\Pusat\SandiSementara;
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
 * — lihat {@see SandiSementara}. Menerimanya dari pemanggil berarti menerima kata sandi yang sudah
 * diketahui pemanggil sebelum pemiliknya pernah melihatnya.
 */
final class PembuatanTenantRequest extends FormRequest
{
    /** Yang menentukan boleh-tidaknya adalah token pusat admin di middleware, bukan sesi. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nama_badan_hukum' => ['required', 'string', 'max:255'],
            'nama_admin' => ['required', 'string', 'max:255'],
            'email_admin' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
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
        $email = $this->input('email_admin');

        // Dirapikan sebelum diperiksa, bukan sesudah. `unique:users,email` membandingkan apa
        // adanya, sehingga "Owner@Contoh.test" lolos pemeriksaan lalu disimpan sebagai
        // "owner@contoh.test" oleh aksi pendaftaran — dua akun, satu email.
        if (is_string($email)) {
            $this->merge(['email_admin' => Str::lower(trim($email))]);
        }
    }

    /** @return list<string> */
    public function appIds(): array
    {
        return array_values($this->collect('app_ids')->map(fn (mixed $id): string => (string) $id)->all());
    }
}
