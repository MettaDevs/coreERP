<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwoFactorLoginTest extends TestCase
{
    use CoreSchema;

    private int $operatorId;

    /** @var array<string, mixed>|null */
    private ?array $verificationBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['core.base_url' => 'http://core.mfa.test', 'core.token' => 'mfa-test-token', 'sso.password_login' => true]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response($this->verificationBody ?? [], $this->verificationBody === null ? 422 : 200));
    }

    private function operator(): int
    {
        $this->operatorId = DB::table('users')->insertGetId(['name' => 'Operator', 'email' => 'operator@mfa.invalid', 'password' => bcrypt('secret'), 'account_type' => 'person', 'two_factor_secret' => 'encrypted-by-core-not-console', 'two_factor_recovery_codes' => 'encrypted-by-core-not-console', 'two_factor_confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('provider_access')->insert(['user_id' => $this->operatorId, 'role' => 'provider_admin', 'created_at' => now(), 'updated_at' => now()]);

        return $this->operatorId;
    }

    private function verified(): void
    {
        $this->verificationBody = ['verified' => true, 'user_id' => $this->operatorId];
    }

    private function start(): void
    {
        $this->post('/login', ['email' => 'operator@mfa.invalid', 'password' => 'secret'])->assertRedirect('/login/verifikasi');
        $this->assertGuest();
    }

    public function test_password_alone_cannot_create_session_and_core_verification_can(): void
    {
        $this->operator();
        $this->verified();
        $this->start();
        $this->get('/lingkungan')->assertRedirect('/login');
        $this->post('/login/verifikasi', ['code' => '123456'])->assertRedirect('/lingkungan');
        $this->assertAuthenticated();
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer mfa-test-token') && $request['code'] === '123456');
    }

    public function test_wrong_code_keeps_user_unauthenticated(): void
    {
        $this->operator();
        $this->start();
        $this->post('/login/verifikasi', ['code' => 'invalid'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_expired_challenge_requires_password_again(): void
    {
        $this->operator();
        $this->start();
        $this->travel(6)->minutes();
        $this->post('/login/verifikasi', ['code' => 'recovery-once'])->assertRedirect('/login');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_recovery_code_is_forwarded_without_decrypting_core_secrets(): void
    {
        $this->operator();
        $this->verified();
        $this->start();
        $this->post('/login/verifikasi', ['code' => 'recovery-once'])->assertRedirect('/lingkungan');
        $this->assertAuthenticated();
        Http::assertSent(fn ($request) => $request['code'] === 'recovery-once');
        $this->assertSame('encrypted-by-core-not-console', DB::table('users')->where('id', $this->operatorId)->value('two_factor_recovery_codes'));
    }

    public function test_operator_revocation_during_challenge_prevents_login(): void
    {
        $id = $this->operator();
        $this->verified();
        $this->start();
        DB::table('provider_access')->where('user_id', $id)->update(['role' => 'revoked']);
        $this->post('/login/verifikasi', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_required_password_change_is_not_bypassed(): void
    {
        $id = $this->operator();
        DB::table('users')->where('id', $id)->update(['must_change_password' => true]);
        $this->post('/login', ['email' => 'operator@mfa.invalid', 'password' => 'secret'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_repeated_wrong_codes_are_limited(): void
    {
        $this->operator();
        $this->start();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login/verifikasi', ['code' => 'invalid'])->assertSessionHasErrors('code');
        }
        $this->assertGuest();
        Http::assertSentCount(5);
    }

    public function test_emergency_password_login_is_logged_after_mfa_completion(): void
    {
        $this->operator();
        $this->verified();
        config(['sso.password_login' => false, 'sso.break_glass_emails' => ['operator@mfa.invalid']]);
        $this->start();
        $auditLogger = Log::spy();
        $this->post('/login/verifikasi', ['code' => '123456'])->assertRedirect('/lingkungan');
        $auditLogger->shouldHaveReceived('warning')->with('Konsol: akun darurat masuk dengan kata sandi.', ['email' => 'operator@mfa.invalid', 'ip' => '127.0.0.1'])->once();
    }

    public function test_password_policy_is_checked_again_when_mfa_completes(): void
    {
        $this->operator();
        $this->start();
        config(['sso.password_login' => false, 'sso.break_glass_emails' => []]);
        $this->post('/login/verifikasi', ['code' => 'recovery-once'])->assertSessionHasErrors('code');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_wrong_identity_or_malformed_core_success_is_never_authorized(): void
    {
        $this->operator();
        $this->start();
        foreach ([['verified' => true, 'user_id' => $this->operatorId + 1], ['verified' => false, 'user_id' => $this->operatorId], []] as $body) {
            $this->verificationBody = $body;
            $this->post('/login/verifikasi', ['code' => '123456'])->assertSessionHasErrors('code');
            $this->assertGuest();
        }
    }
}
