<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Platform\ControlPlane\Models\ProductCustomer;
use App\Platform\ControlPlane\Products\ProductRegistration;
use App\Platform\Identity\Support\Sso\SsoFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

final class ProductRegistrationController extends Controller
{
    public function start(Request $request, ProductRegistration $registration): JsonResponse
    {
        $input = $request->validate(['browser_challenge' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return $this->sso(fn () => $registration->start($input['browser_challenge']));
    }

    public function exchange(Request $request, ProductRegistration $registration): JsonResponse
    {
        $input = $request->validate([
            'state' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'code' => ['required', 'string', 'max:4096'],
            'browser_secret' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        return $this->sso(fn () => $registration->exchange($input['state'], $input['code'], $input['browser_secret']));
    }

    public function session(Request $request, ProductRegistration $registration): JsonResponse
    {
        return response()->json($registration->sessionData($registration->customer($request->header('X-Registration-Session'))));
    }

    public function emailChallenge(Request $request, ProductRegistration $registration): JsonResponse
    {
        $customer = $this->customer($request, $registration);
        $input = $request->validate(['email' => ['required', 'email', 'max:250']]);

        return response()->json($registration->sendEmailChallenge($customer, $input['email']));
    }

    public function emailVerification(Request $request, ProductRegistration $registration): JsonResponse
    {
        $customer = $this->customer($request, $registration);
        $input = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        $registration->verifyEmail($customer, $input['token']);

        return response()->json($registration->sessionData($customer->fresh()));
    }

    public function storeTenant(Request $request, ProductRegistration $registration): JsonResponse
    {
        $customer = $this->customer($request, $registration);
        $input = $request->validate([
            'product' => ['required', 'string', Rule::in(array_keys(config('managed_products.products', [])))],
            'profile' => ['required', 'string'],
            'name' => ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
            'slug' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(['www', 'admin', 'api', 'auth', 'sso', 'mail', 'demo', 'support', 'trial-demo'])],
            'idempotency_key' => ['required', 'uuid'],
            'owner_name' => ['prohibited'], 'owner_email' => ['prohibited'], 'owner_customer_id' => ['prohibited'],
            'requested_by' => ['prohibited'], 'release' => ['prohibited'], 'expires_at' => ['prohibited'],
        ]);
        $tenant = $registration->createTenant($customer, $input);

        return response()->json($tenant->only(['id', 'product', 'profile', 'name', 'slug', 'status', 'public_url', 'expires_at', 'failure_message']), 202);
    }

    public function launch(Request $request, ProductRegistration $registration): JsonResponse
    {
        $customer = $this->customer($request, $registration);
        $input = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'browser_challenge' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'state' => ['required', 'string', 'min:32', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/D'],
        ]);

        return response()->json($registration->launch($customer, $input['tenant_id'], $input['browser_challenge'], $input['state']));
    }

    public function logout(Request $request, ProductRegistration $registration): JsonResponse
    {
        $this->customer($request, $registration);
        $registration->logout((string) $request->header('X-Registration-Session'));

        return response()->json(['ok' => true]);
    }

    public function backchannelLogout(Request $request, ProductRegistration $registration): JsonResponse
    {
        $input = $request->validate(['logout_token' => ['required', 'string', 'max:16384']]);

        return $this->sso(function () use ($registration, $input): array {
            $registration->backchannelLogout($input['logout_token']);

            return ['ok' => true];
        });
    }

    /** @param callable(): array<string, mixed> $action */
    private function sso(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (SsoFailure $failure) {
            Log::notice('Login SSO produk ditolak.', ['reason' => $failure->reason]);

            return response()->json(['message' => 'Login SSO belum dapat diselesaikan. Silakan masuk kembali.'],
                $failure->reason === SsoFailure::PROVIDER_UNREACHABLE ? 503 : 401);
        }
    }

    private function customer(Request $request, ProductRegistration $registration): ProductCustomer
    {
        $customer = $registration->customer($request->header('X-Registration-Session'));
        abort_unless($customer !== null, 401, 'Silakan masuk dengan SSO.');

        return $customer;
    }
}
