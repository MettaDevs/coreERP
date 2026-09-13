<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penjaga kata sandi sementara, beserta batas yang paling penting darinya: ia tidak menyentuh
 * siapa pun yang tidak ditandai.
 *
 * Middleware ini masuk grup web, jadi ia berdiri di depan hampir setiap halaman di aplikasi ini.
 * Kelas kegagalan yang harus ditutup bukan "penjaganya tidak menahan" melainkan "penjaganya
 * menahan orang yang salah" — dan yang kedua tidak akan terlihat sebagai satu test merah,
 * melainkan sebagai puluhan test yang tiba-tiba mendapat 302 tanpa menyebut sebabnya.
 */
final class WajibGantiSandiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pengguna_bertanda_diarahkan_ke_layar_ganti_kata_sandi(): void
    {
        $pengguna = $this->penggunaBertanda();

        $this->actingAs($pengguna)->get(route('dashboard'))->assertRedirect(route('security.edit'));
        $this->actingAs($pengguna)->get('/')->assertRedirect(route('security.edit'));
        $this->actingAs($pengguna)->get(route('profile.edit'))->assertRedirect(route('security.edit'));
    }

    public function test_pengguna_tanpa_tanda_tidak_tersentuh_sama_sekali(): void
    {
        $pengguna = User::factory()->create();

        $this->assertFalse($pengguna->must_change_password);
        $this->actingAs($pengguna)->get(route('dashboard'))->assertOk();
        $this->actingAs($pengguna)->get('/')->assertOk();
    }

    public function test_tamu_tidak_tersentuh(): void
    {
        $this->get('/')->assertOk();
    }

    /**
     * Layar ganti kata sandi berada di balik konfirmasi kata sandi, jadi jalurnya harus terbuka
     * seutuhnya — kalau salah satunya ditahan, penjaganya menjadi kurungan tanpa pintu dan
     * peramban berputar antara dua pengalihan sampai menyerah.
     */
    public function test_jalur_menuju_layar_ganti_kata_sandi_tetap_terbuka(): void
    {
        $pengguna = $this->penggunaBertanda();

        $this->actingAs($pengguna)->get(route('password.confirm'))->assertOk();

        $this->actingAs($pengguna)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk();
    }

    public function test_logout_tetap_terbuka(): void
    {
        $this->actingAs($this->penggunaBertanda())->post(route('logout'))->assertRedirect();

        $this->assertGuest();
    }

    public function test_penandanya_padam_begitu_kata_sandinya_benar_benar_diganti(): void
    {
        $pengguna = $this->penggunaBertanda();

        $this->actingAs($pengguna)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'sandi-baru-yang-panjang',
                'password_confirmation' => 'sandi-baru-yang-panjang',
            ])
            ->assertRedirect(route('security.edit'));

        $this->assertFalse($pengguna->refresh()->must_change_password);
        $this->actingAs($pengguna)->get(route('dashboard'))->assertOk();
    }

    /** Membuka layarnya saja tidak membuktikan apa pun; yang membuktikan cuma pergantiannya. */
    public function test_penandanya_tidak_padam_hanya_karena_layarnya_dibuka(): void
    {
        $pengguna = $this->penggunaBertanda();

        $this->actingAs($pengguna)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk();

        $this->assertTrue($pengguna->refresh()->must_change_password);
    }

    private function penggunaBertanda(): User
    {
        $pengguna = User::factory()->create();
        $pengguna->forceFill(['must_change_password' => true])->save();

        return $pengguna;
    }
}
