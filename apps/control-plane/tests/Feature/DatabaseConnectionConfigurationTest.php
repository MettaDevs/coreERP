<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class DatabaseConnectionConfigurationTest extends TestCase
{
    #[DataProvider('connectionModes')]
    public function test_mode_selects_a_stable_business_read_connection_without_borrowing_control_credentials(?string $mode, ?string $override, string $expected): void
    {
        foreach ([
            'COREERP_DATABASE_MODE' => $mode,
            'DB_CONNECTION' => 'control_test',
            'DB_USERNAME' => 'control_user',
            'DB_PASSWORD' => 'control_secret',
            'DB_BUSINESS_READ_CONNECTION' => $override,
            'DB_BUSINESS_READ_URL' => null,
            'DB_BUSINESS_READ_USERNAME' => null,
            'DB_BUSINESS_READ_PASSWORD' => null,
        ] as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }

        $configuration = require __DIR__.'/../../config/database.php';

        $this->assertSame($mode ?? 'combined', $configuration['mode']);
        $this->assertSame('control_test', $configuration['default']);
        $this->assertSame($expected, $configuration['business_read_connection']);
        $this->assertNull($configuration['connections']['business_read']['username']);
        $this->assertNull($configuration['connections']['business_read']['password']);
    }

    /** @return array<string, array{?string, ?string, string}> */
    public static function connectionModes(): array
    {
        return [
            'default preserves combined mode' => [null, null, 'control_test'],
            'combined preserves existing default' => ['combined', null, 'control_test'],
            'split uses separate reader' => ['split', null, 'business_read'],
            'combined explicit reader' => ['combined', 'reader_override', 'reader_override'],
            'split explicit reader' => ['split', 'reader_override', 'reader_override'],
        ];
    }
}
