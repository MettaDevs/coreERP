<?php

namespace App\Http\Requests\Onboarding;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Aturan penukaran kode, dan ia bercabang menurut siapa yang mengirimnya.
 *
 * Orang yang sudah masuk hanya mengirim kodenya. Nama, email, dan kata sandi tidak diminta — dan
 * lebih penting lagi, **tidak diterima**: kalau ia tetap dikirim, ia diabaikan. Undangan tidak
 * berhak menyentuh akun yang sudah ada, dan satu-satunya cara menjamin itu adalah dengan tidak
 * pernah membaca nilainya.
 *
 * `unique:users,email` tetap berdiri untuk orang baru. Itu bukan sisa aturan lama melainkan
 * penjaga pengambilalihan akun: tanpanya, pemegang satu kode undangan dapat mengetik email orang
 * lain beserta kata sandi pilihannya sendiri.
 */
class JoinInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $code = ['required', 'string', 'max:32'];

        if ($this->user() !== null) {
            return ['code' => $code];
        }

        return [
            'code' => $code,
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            /*
             * Pesan bawaan Laravel berbunyi "The email has already been taken" — benar, dan
             * berhenti tepat sebelum bagian yang berguna. Orang yang membacanya menyimpulkan
             * undangannya tidak berlaku untuk dirinya, padahal ia hanya perlu masuk lebih dulu.
             *
             * Ditulis di sini dan bukan hanya di aksinya, karena aturan `unique` inilah yang
             * sungguhan ditemui orang: ia berjalan sebelum satu baris pun aksi itu dieksekusi.
             */
            'email.unique' => 'Email ini sudah memiliki akun. Masuk dengan akun itu lebih dulu, '
                .'lalu tukarkan kodenya dari sana — satu akun boleh berada di banyak tenant.',
        ];
    }

    /** @return array{code:string,name:string,email:string,password:string} */
    public function payload(): array
    {
        return [
            'code' => $this->string('code')->toString(),
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }
}
