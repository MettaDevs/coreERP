<?php

namespace Tests\Unit;

use App\Support\AppContentPath;
use RuntimeException;
use Tests\TestCase;

class AppContentPathTest extends TestCase
{
    public function test_two_placements_of_one_app_never_share_a_path(): void
    {
        // Inti dari perubahan ini: placement adalah unit silo/pool, jadi shard
        // pooled kedua dan silo milik satu tenant harus dapat path berbeda
        // walaupun app-nya sama release-nya sama.
        $pooled = AppContentPath::for('management-aset', 'pooled-primary');
        $isolated = AppContentPath::for('management-aset', 'isolated-01jq8w2m4k');

        $this->assertSame('/apps-content/pooled-primary/management-aset/', $pooled);
        $this->assertNotSame($pooled, $isolated);
    }

    public function test_it_cannot_collide_with_the_host_route(): void
    {
        // Nilai lama pernah berupa '/apps/<id>/', yang membuat iframe memuat
        // ulang halaman host-nya sendiri. Prefix ini beda segmen dari 'apps'.
        $path = AppContentPath::for('sample-app', 'pooled-primary');

        $this->assertStringStartsWith('/apps-content/', $path);
        $this->assertStringStartsNotWith('/apps/', $path);
    }

    public function test_it_rejects_identifiers_that_would_escape_the_path(): void
    {
        $this->expectException(RuntimeException::class);

        AppContentPath::for('sample-app', '../etc');
    }
}
