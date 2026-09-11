<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConfigureLocalCoreCommand extends Command
{
    protected $signature = 'core:configure-local';

    protected $description = 'Create local-only CoreERP credentials and application key';

    public function handle(): int
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            copy(base_path('.env.example'), $envPath);
        }

        $contents = (string) file_get_contents($envPath);
        $existing = static function (string $key) use ($contents): ?string {
            preg_match('/^'.preg_quote($key, '/').'=(.+)$/m', $contents, $match);

            return isset($match[1]) && trim($match[1]) !== '' ? trim($match[1]) : null;
        };
        $dbPassword = $existing('DB_PASSWORD') ?? $this->generateSecret(32);
        $providerPassword = $existing('COREERP_PROVIDER_PASSWORD') ?? 'Cp!1'.$this->generateSecret(24);
        $appKey = $existing('APP_KEY') ?? 'base64:'.base64_encode(random_bytes(32));
        $newCredentials = $existing('DB_PASSWORD') === null || $existing('COREERP_PROVIDER_PASSWORD') === null;

        foreach ([
            'APP_KEY' => $appKey,
            'APP_URL' => 'http://localhost:8000',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'core_erp',
            'DB_USERNAME' => 'core_erp_app',
            'DB_PASSWORD' => $dbPassword,
            'COREERP_PROVIDER_EMAIL' => 'provider@coreerp.local',
            'COREERP_PROVIDER_PASSWORD' => $providerPassword,
        ] as $key => $value) {
            $line = "{$key}={$value}";
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            $contents = preg_match($pattern, $contents)
                ? (string) preg_replace($pattern, $line, $contents)
                : rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($envPath, $contents, LOCK_EX);
        if ($newCredentials) {
            $this->line("DB_ROLE_PASSWORD={$dbPassword}");
            $this->line("PROVIDER_PASSWORD={$providerPassword}");
        } else {
            $this->info('Local credentials already exist; no secrets were rotated.');
        }

        return self::SUCCESS;
    }

    /** @param int<1, max> $bytes */
    private function generateSecret(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
