<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\User;
use ControlPlane\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

final class ProductTenantsTest extends TestCase
{
    private const BASE = 'http://core.test/api/internal/v1/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['core.base_url' => 'http://core.test', 'core.token' => 'test-token']);
        Http::preventStrayRequests();
    }

    public function test_product_screen_requires_an_operator(): void
    {
        $this->get('/produk/tenant')->assertRedirect('/login');
        $this->actingAs($this->user(false))->get('/produk/tenant')->assertNotFound();
        $this->post('/produk/tenant', $this->input())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unavailable_catalog_leaves_a_visible_error_and_no_creation_options(): void
    {
        Http::fake([self::BASE.'managed-products' => Http::failedConnection()]);
        $this->actingAs($this->user())->get('/produk/tenant')->assertOk()
            ->assertInertia(fn ($page) => $page->component('products/index')->where('products', [])->where('catalogError', 'Pilihan produk belum dapat dimuat. Periksa koneksi Core lalu muat ulang halaman.'));
    }

    public function test_credential_details_are_not_cacheable_and_encrypt_browser_history(): void
    {
        $id = 'ee4ba844-daca-4d1f-85df-5975a23be2f3';
        Http::fake([
            self::BASE.'managed-products' => Http::response(['products' => []]),
            self::BASE.'product-tenants/'.$id => Http::response(['id' => $id, 'status' => 'ready']),
        ]);
        $this->actingAs($this->user())->get('/produk/tenant/'.$id)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('page', fn (array $page): bool => $page['encryptHistory'] === true);
    }

    public function test_create_passes_verified_operator_and_keeps_erp_flow_separate(): void
    {
        $id = 'ee4ba844-daca-4d1f-85df-5975a23be2f3';
        Http::fake([self::BASE.'product-tenants' => Http::response(['id' => $id, 'status' => 'queued'], 202)]);
        $this->actingAs($this->user())->post('/produk/tenant', [...$this->input(), 'requested_by' => 999])
            ->assertRedirect('/produk/tenant/'.$id);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'product-tenants'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['requested_by'] === 17
            && $request['profile'] === 'apotek');
        Http::assertSentCount(1);
    }

    public function test_core_validation_preserves_field_errors(): void
    {
        Http::fake([self::BASE.'product-tenants' => Http::response(['errors' => ['slug' => ['Alamat sudah digunakan.']]], 422)]);
        $this->actingAs($this->user())->from('/produk/tenant')->post('/produk/tenant', $this->input())
            ->assertRedirect('/produk/tenant')->assertSessionHasErrors(['slug' => 'Alamat sudah digunakan.']);
    }

    public function test_timeout_explains_uncertain_creation_and_keeps_the_same_request_key(): void
    {
        Http::fake([self::BASE.'product-tenants' => Http::failedConnection()]);
        $this->actingAs($this->user())->from('/produk/tenant')->post('/produk/tenant', $this->input())
            ->assertRedirect('/produk/tenant')->assertSessionHasErrors('core');
        Http::assertSent(fn (Request $request): bool => $request['idempotency_key'] === $this->input()['idempotency_key']);
    }

    public function test_success_without_id_is_not_treated_as_a_created_tenant(): void
    {
        Http::fake([self::BASE.'product-tenants' => Http::response(['status' => 'queued'], 202)]);
        $this->actingAs($this->user())->from('/produk/tenant')->post('/produk/tenant', $this->input())
            ->assertRedirect('/produk/tenant')->assertSessionHasErrors('core');
    }

    public function test_list_forwards_cursor_and_search_with_a_bounded_batch(): void
    {
        Http::fake([self::BASE.'product-tenants*' => Http::response(['data' => [], 'next_cursor' => null])]);
        $this->actingAs($this->user())->getJson('/produk/tenant?search=sehat&cursor=next')
            ->assertOk()->assertExactJson(['data' => [], 'next_cursor' => null]);
        Http::assertSent(fn (Request $request): bool => $request['search'] === 'sehat' && $request['cursor'] === 'next' && $request['limit'] === 50);
    }

    public function test_retry_only_requests_existing_tenant_operation(): void
    {
        $id = 'ee4ba844-daca-4d1f-85df-5975a23be2f3';
        Http::fake([self::BASE.'product-tenants/'.$id.'/retry' => Http::response(['id' => $id, 'status' => 'queued'], 202)]);
        $this->actingAs($this->user())->post('/produk/tenant/'.$id.'/ulang')
            ->assertRedirect('/produk/tenant/'.$id);
        Http::assertSent(fn (Request $request): bool => $request['requested_by'] === 17);
    }

    private function user(bool $operator = true): User
    {
        /** @var User&MockInterface $user */
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 17, 'name' => 'Operator', 'email' => 'operator@example.test']);
        $user->shouldReceive('operator')->andReturn($operator);

        return $user;
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return [
            'product' => 'faskes', 'profile' => 'apotek', 'name' => 'Apotek Sehat', 'slug' => 'apotek-sehat',
            'owner_name' => 'Admin Apotek', 'owner_email' => 'admin@example.test',
            'idempotency_key' => '18bbdba2-3187-4c3e-8305-6d7315e4ef54',
        ];
    }
}
