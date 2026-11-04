<?php

declare(strict_types=1);

use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Support\EnvironmentConnection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

try {
    $root = dirname(__DIR__, 3);
    chdir($root);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $id = $input['id'] ?? '';
    $action = $argv[1] ?? '';
    if (preg_match('/^[a-zA-Z0-9]{40}$/D', $id) !== 1 || ! in_array($action, ['write', 'read'], true)) {
        throw new RuntimeException('Invalid fixture input.');
    }
    $original = config('database.default');
    $cache = Cache::store('redis');
    $session = app('session')->driver('redis');
    $environment = (new Environment)->forceFill(['id' => 'redis-process', 'database_name' => 'env_redis_process']);
    $passed = app(EnvironmentConnection::class)->runWithin($environment, function () use ($cache, $session, $id, $action): bool {
        $session->setId($id);
        $session->start();
        if ($action === 'write') {
            $session->put('connection_probe', 'session-value');
            $session->save();
            $cache->put('connection-probe:'.$id, 'cache-value', 60);

            return $cache->get($id) === null && config('session.connection') === 'sessions';
        }
        $ok = $session->get('connection_probe') === 'session-value'
            && $cache->get('connection-probe:'.$id) === 'cache-value'
            && $cache->get($id) === null;
        $session->getHandler()->destroy($id);
        $cache->forget('connection-probe:'.$id);

        return $ok;
    });
    echo json_encode(['passed' => $passed, 'process' => getmypid(), 'context_restored' => config('database.default') === $original], JSON_THROW_ON_ERROR).PHP_EOL;
    exit($passed ? 0 : 1);
} catch (Throwable) {
    echo json_encode(['passed' => false, 'category' => 'fixture_failed'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
