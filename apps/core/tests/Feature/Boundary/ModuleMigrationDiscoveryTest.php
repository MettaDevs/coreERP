<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Platform\Modules\Support\ModulesBeingMoved;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class ModuleMigrationDiscoveryTest extends TestCase
{
    public function test_empty_scaffold_is_excluded_until_it_has_a_migration(): void
    {
        $root = sys_get_temp_dir().'/coreerp-migration-discovery-'.bin2hex(random_bytes(6));
        $folder = $root.'/test-publisher/test-module';
        $migrations = $folder.'/database/migrations';
        mkdir($migrations, 0777, true);
        file_put_contents($folder.'/app.yaml', "id: test-module\ntable_prefix: test_module_\n");
        file_put_contents($migrations.'/.gitkeep', '');

        try {
            $scanner = new PemindaiModul($root);
            $moved = ModulesBeingMoved::custom([]);
            $this->assertSame([], $scanner->modulDenganMigration($moved));

            file_put_contents($migrations.'/2026_01_01_000000_create_example.php', '<?php');

            $this->assertSame([[
                'id' => 'test-module',
                'nama' => 'test-module',
                'awalan' => 'test_module_',
                'migrations' => $migrations,
            ]], $scanner->modulDenganMigration($moved));
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
