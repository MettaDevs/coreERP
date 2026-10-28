<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\ControlPlane;

use App\Platform\Identity\Models\User;
use App\Platform\Modules\Models\ProviderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class OperatorSecondFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coreerp.control_plane_token', 'test-operator-token');
        $this->secret = app(Google2FA::class)->generateSecretKey();
        $this->operator = User::factory()->create();
        $this->operator->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-once', 'recovery-other'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        ProviderAccess::create(['user_id' => $this->operator->id, 'role' => 'provider_admin']);
        RateLimiter::clear('operator-second-factor:'.$this->operator->id);
    }

    private function endpoint(): string
    {
        return '/api/internal/v1/operators/'.$this->operator->id.'/verify-second-factor';
    }

    private function verify(string $code): TestResponse
    {
        return $this->withToken('test-operator-token')->postJson($this->endpoint(), ['code' => $code]);
    }

    public function test_it_requires_the_control_plane_token(): void
    {
        $this->postJson($this->endpoint(), ['code' => 'recovery-once'])->assertUnauthorized();
    }

    public function test_it_accepts_the_current_totp_without_disclosing_secrets(): void
    {
        $this->verify(app(Google2FA::class)->getCurrentOtp($this->secret))
            ->assertOk()->assertExactJson(['verified' => true, 'user_id' => $this->operator->id]);
    }

    public function test_a_totp_already_used_by_core_cannot_be_reused(): void
    {
        $code = app(Google2FA::class)->getCurrentOtp($this->secret);
        $this->assertTrue(app(TwoFactorAuthenticationProvider::class)->verify($this->secret, $code));
        $this->verify($code)->assertUnprocessable();
    }

    public function test_it_rejects_a_wrong_code(): void
    {
        $this->verify('wrong-code')->assertUnprocessable();
    }

    public function test_recovery_codes_are_consumed_once_and_other_codes_survive(): void
    {
        $this->verify('recovery-once')->assertOk();
        $this->verify('recovery-once')->assertUnprocessable();
        $this->verify('recovery-other')->assertOk();
    }

    public function test_a_revoked_operator_cannot_verify(): void
    {
        ProviderAccess::where('user_id', $this->operator->id)->update(['role' => 'provider_reader']);
        $this->verify('recovery-once')->assertUnprocessable();
    }

    public function test_unconfirmed_mfa_cannot_verify(): void
    {
        $this->operator->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->verify('recovery-once')->assertUnprocessable();
    }

    public function test_disabled_mfa_cannot_verify(): void
    {
        $this->operator->forceFill(['two_factor_secret' => null])->save();
        $this->verify('recovery-once')->assertUnprocessable();
    }

    public function test_required_password_change_cannot_verify(): void
    {
        $this->operator->forceFill(['must_change_password' => true])->save();
        $this->verify('recovery-once')->assertUnprocessable();
    }

    public function test_application_accounts_cannot_verify(): void
    {
        $this->operator->forceFill(['account_type' => User::APPLICATION])->save();
        $this->verify('recovery-once')->assertUnprocessable();
    }

    public function test_rate_limit_is_shared_for_every_attempt_at_the_same_user(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->verify('wrong-code')->assertUnprocessable();
        }
        $this->verify('recovery-once')->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_code_must_be_a_bounded_string(): void
    {
        $this->withToken('test-operator-token')->postJson($this->endpoint(), ['code' => ['invalid']])->assertUnprocessable();
        $this->verify(str_repeat('a', 256))->assertUnprocessable();
    }
}
