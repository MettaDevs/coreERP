<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Akun aplikasi klien integrasi (`users.account_type = 'application'`, dibuat Core) tidak pernah dapat
 * masuk ke konsol, bahkan bila ia keliru diberi hak operator dan kata sandinya diketahui.
 */
class ApplicationAccountLoginTest extends TestCase
{
    use CoreSchema;

    public function test_an_application_account_never_signs_in_even_with_operator_access(): void
    {
        config(['sso.password_login' => true]);
        $id = DB::table('users')->insertGetId([
            'name' => 'Old-finance',
            'email' => 'integration-client-uji@application.invalid',
            'password' => bcrypt('rahasia'),
            'account_type' => 'application',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('provider_access')->insert([
            'user_id' => $id, 'role' => 'provider_admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $masuk = ['email' => 'integration-client-uji@application.invalid', 'password' => 'rahasia'];

        $this->post('/login', $masuk)->assertSessionHasErrors('email');
        $this->assertGuest();

        // Baris yang sama sebagai akun orang dapat masuk: yang ditolak jenis akunnya, bukan kata sandinya.
        DB::table('users')->where('id', $id)->update(['account_type' => 'person']);
        $this->post('/login', $masuk)->assertRedirect('/lingkungan');
        $this->assertAuthenticated();
    }
}
