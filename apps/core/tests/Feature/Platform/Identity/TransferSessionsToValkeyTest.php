<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferSessionsToValkeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_live_sessions_are_transferred_and_database_copies_are_retired(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 120]);
        $target = new Repository(new ArrayStore);
        Cache::shouldReceive('store')->with('sessions')->once()->andReturn($target);
        $live = Str::random(40);
        $expired = Str::random(40);
        $payload = serialize(['_token' => 'csrf-fixture', 'membership_id' => 'trusted-fixture']);
        foreach ([$live => time() - 60, $expired => time() - 7300] as $id => $activity) {
            DB::table('sessions')->insert(['id' => $id, 'payload' => base64_encode($payload), 'last_activity' => $activity]);
        }

        $this->artisan('core:sessions:transfer-to-valkey')->assertSuccessful();

        $this->assertSame($payload, Crypt::decrypt($target->get($live)));
        $store = new EncryptedStore('test', new CacheBasedSessionHandler($target, 120), app('encrypter'), $live);
        $store->start();
        $this->assertSame('csrf-fixture', $store->get('_token'));
        $this->assertSame('trusted-fixture', $store->get('membership_id'));
        $this->assertNull($target->get($expired));
        $this->assertSame(0, DB::table('sessions')->where('id', $live)->value('last_activity'));
        $this->assertGreaterThan(0, DB::table('sessions')->where('id', $expired)->value('last_activity'));
    }

    public function test_the_transfer_cannot_overwrite_a_store_already_serving_sessions(): void
    {
        config(['session.driver' => 'redis']);
        $this->artisan('core:sessions:transfer-to-valkey')->assertFailed();
    }
}
