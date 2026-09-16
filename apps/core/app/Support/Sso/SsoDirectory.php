<?php

declare(strict_types=1);

namespace App\Support\Sso;

/**
 * Mencari satu pengguna di penyedia SSO berdasarkan emailnya.
 *
 * Dipakai satu tempat saja: saat operator membuat undangan terikat. Jawabannya menentukan dua hal —
 * apakah undangan boleh dibuat, dan **subjek mana** yang diikat. Yang kedua itu yang penting:
 * sesudah baris undangan lahir, email tidak pernah lagi dipakai untuk mencocokkan siapa pun.
 *
 * ## Yang tidak dilakukan di sini
 *
 * Tidak ada penyimpanan sementara. Jawaban "ada" berumur pendek — akun dapat dinonaktifkan semenit
 * kemudian — dan menyimpannya berarti menahan penolakan yang seharusnya muncul. Tidak ada pula
 * pencarian terbalik dari subjek ke email: penyedia tidak menyediakannya, dan menebaknya dari
 * `sso_email_at_invite` akan menghidupkan lagi pencocokan lewat email yang justru dihindari.
 */
class SsoDirectory
{
    public function __construct(private readonly SsoApiClient $api) {}

    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
    }

    /**
     * Pengguna dengan email itu, atau `null` bila penyedia tidak mengenalnya.
     *
     * @throws SsoApiUnavailable Penyedia tidak menjawab, menolak kredensial, atau menjawab hal yang tidak dapat dibaca.
     * @throws \App\Support\ControlPlane\OutboundRefused Lingkungan ini memang tidak boleh menjangkau luar.
     */
    public function find(string $email): ?SsoDirectoryUser
    {
        $response = $this->api->get('/users/lookup', ['email' => $email]);

        // Satu-satunya arti 404 di endpoint ini, dan ia bukan kegagalan.
        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            // Tersisa 4xx selain 404 — pada penyedia ini praktisnya 422 dari pemeriksa emailnya
            // sendiri. Pesannya diteruskan karena ia menyebut apa yang salah pada masukan operator.
            throw new SsoApiUnavailable($this->reason($response->json('message'), $response->status()));
        }

        $user = $response->json('user');

        if ($response->json('success') !== true || ! is_array($user)) {
            throw new SsoApiUnavailable('Jawaban penyedia SSO saat mencari pengguna tidak dapat dibaca.');
        }

        $subject = $user['id'] ?? null;

        // Subjek adalah satu-satunya bagian yang menentukan; tanpanya jawaban ini tidak dapat
        // dipakai untuk apa pun, dan diam-diam menyimpan string kosong akan mengikat undangan pada
        // "tidak ada siapa-siapa".
        if (! is_string($subject) && ! is_int($subject)) {
            throw new SsoApiUnavailable('Penyedia SSO menjawab tanpa id pengguna yang dapat dipakai.');
        }

        $subject = (string) $subject;

        if (trim($subject) === '') {
            throw new SsoApiUnavailable('Penyedia SSO menjawab dengan id pengguna yang kosong.');
        }

        return new SsoDirectoryUser(
            subject: $subject,
            name: $this->text($user['name'] ?? null) ?: $this->text($user['email'] ?? null),
            email: $this->text($user['email'] ?? null) ?: $email,
            // Bentuk selain boolean dibaca sebagai nonaktif. Keliru ke arah itu hanya menolak satu
            // undangan yang dapat diulang; keliru ke arah sebaliknya mengundang akun yang mati.
            isActive: ($user['is_active'] ?? null) === true,
        );
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function reason(mixed $message, int $status): string
    {
        $message = is_string($message) ? trim($message) : '';

        return $message === ''
            ? sprintf('Penyedia SSO menolak pencarian dengan HTTP %d.', $status)
            : $message;
    }
}
