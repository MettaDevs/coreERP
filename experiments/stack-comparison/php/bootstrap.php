<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

umask(0077);

$sourceRoot = rtrim(getenv('COMPARE_SOURCE_ROOT') ?: '/repo', '/');
require $sourceRoot.'/apps/core/vendor/autoload.php';
$application = require $sourceRoot.'/apps/core/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

// Handler CLI Laravel dapat selesai dengan exit 0 untuk exception di skrip bebas.
// Kegagalan alat ini wajib merah, tanpa mencetak SQL bindings, hash, atau password.
set_exception_handler(static function (Throwable $exception): never {
    $message = $exception instanceof QueryException
        ? 'SQLSTATE '.($exception->errorInfo[0] ?? $exception->getCode()).'; bindings tidak dicetak'
        : $exception->getMessage();
    fwrite(STDERR, get_class($exception).': '.$message.PHP_EOL);
    foreach ($exception->getTrace() as $frame) {
        fwrite(STDERR, ($frame['file'] ?? '?').':'.($frame['line'] ?? '?').' '.($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').PHP_EOL);
    }
    exit(1);
});

/** Membatasi semua alat fixture pada cluster eksperimen yang dimiliki pekerjaan ini. */
function comparisonGuard(): void
{
    if (getenv('COMPARE_FIXTURE_ALLOW') !== '1') {
        throw new RuntimeException('Set COMPARE_FIXTURE_ALLOW=1 untuk database eksperimen milik pekerjaan ini.');
    }

    $connection = DB::connection();
    if ($connection->getDriverName() !== 'pgsql'
        || $connection->getConfig('host') !== 'database'
        || $connection->getDatabaseName() !== 'erp_compare'
        || $connection->getConfig('username') !== 'compare_admin') {
        throw new RuntimeException('Koneksi efektif bukan database pembanding yang diizinkan.');
    }

    $actual = $connection->selectOne('select current_database() as name, current_user as owner');
    if ($actual->name !== 'erp_compare' || $actual->owner !== 'compare_admin') {
        throw new RuntimeException('Identitas database runtime tidak cocok.');
    }
}

function comparisonFixturePath(): string
{
    return getenv('COMPARE_FIXTURE_PATH') ?: '/tmp/comparison-fixture.json';
}

/** @param array<string, mixed> $fixture */
function comparisonSave(array $fixture): void
{
    $path = comparisonFixturePath();
    $temporary = $path.'.tmp';
    if (file_put_contents($temporary, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
        throw new RuntimeException('Tidak dapat menyimpan konfigurasi fixture.');
    }
    chmod($temporary, 0600);
    if (! rename($temporary, $path)) {
        throw new RuntimeException('Tidak dapat menyelesaikan penyimpanan konfigurasi fixture.');
    }
}

/** @return array<string, mixed> */
function comparisonLoad(): array
{
    $path = comparisonFixturePath();
    if (! is_file($path)) {
        throw new RuntimeException('Konfigurasi fixture belum dibuat. Jalankan prepare.php lebih dulu.');
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $fixture */
function comparisonOwnsDatabase(array $fixture): void
{
    $allowed = array_column($fixture['tenants'], 'tenant_id');
    if (DB::table('tenants')->whereNotIn('id', $allowed)->exists()) {
        throw new RuntimeException('Database berisi tenant di luar konfigurasi fixture ini. Tidak ada perubahan diizinkan.');
    }
}

/** @param array<string, mixed> $fields */
function comparisonReport(array $fields): void
{
    echo json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}
