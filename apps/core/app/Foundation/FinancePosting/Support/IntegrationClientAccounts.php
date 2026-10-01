<?php

namespace App\Foundation\FinancePosting\Support;

use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Akun aplikasi setiap klien integrasi, padanan User aplikasi di Business Central (kolom `User ID` pada
 * Microsoft Entra Application). Penulisan klien itu tercatat atas nama akunnya di kolom jejak dan log
 * perubahan, bukan sebagai sistem.
 *
 * Akunnya baris `users` berjenis {@see User::APPLICATION}: bernama sama dengan kliennya, beralamat di
 * domain `.invalid` yang tidak pernah menerima email, dengan kata sandi acak yang tidak diketahui siapa
 * pun, dan tidak pernah dapat masuk karena penyedia pengguna hanya membaca akun orang. Ia bukan anggota
 * tenant. Klien yang dicabut tetap menyimpan akunnya, supaya riwayat lamanya tetap bernama.
 */
final class IntegrationClientAccounts
{
    /** Membuat akun klien bila belum ada, dan menyamakan namanya dengan klien. Memulangkan id akunnya. */
    public function ensure(IntegrationClient $client): int
    {
        // Alamatnya diturunkan dari id klien, jadi akun yang tautannya terputus ditemukan lagi, bukan dibuat dua.
        $email = 'integration-client-'.Str::lower($client->id).'@application.invalid';
        $account = ($client->user_id === null ? null : User::query()->find($client->user_id))
            ?? User::query()->where('email', $email)->where('account_type', User::APPLICATION)->first();

        if ($account === null) {
            $account = new User;
            $account->forceFill([
                'name' => $client->name,
                'email' => $email,
                // Di-hash cast `hashed` model; nilainya tidak disimpan di mana pun.
                'password' => Str::random(64),
                'account_type' => User::APPLICATION,
            ])->save();
        } elseif ($account->name !== $client->name) {
            $account->forceFill(['name' => $client->name])->save();
        }

        if ($client->user_id === null || (int) $client->user_id !== (int) $account->id) {
            $client->forceFill(['user_id' => $account->id])->saveQuietly();
        }

        return (int) $account->id;
    }
}
