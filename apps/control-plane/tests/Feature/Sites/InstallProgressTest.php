<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteEnrollmentToken;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Sites\InstallProgress;
use ControlPlane\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Penurunan keadaan pemasangan, tanpa database: model dibangun di memori dan waktu dipaku.
 *
 * Satu baris per keadaan, dan beberapa baris untuk urutan penentu yang paling mudah salah — situs dicabut
 * mengalahkan pemasangan yang berjalan, token habis pada situs yang belum terdaftar, dan pembatalan yang
 * tidak boleh terbaca "Jalan".
 */
final class InstallProgressTest extends TestCase
{
    private const NOW = '2026-09-15 08:00:00';

    /**
     * @return iterable<string, array{0: array<string, mixed>|null, 1: array<string, mixed>|null, 2: array<string, mixed>|null, 3: string, 4: bool}>
     */
    public static function stages(): iterable
    {
        $enrolled = ['public_key' => 'kunci', 'enrolled_at' => self::NOW, 'last_seen_at' => self::NOW];
        $live = ['expires_at' => '2026-09-15 08:30:00', 'used_at' => null];
        $expired = ['expires_at' => '2026-09-15 07:30:00', 'used_at' => null];

        yield 'belum disiapkan' => [null, null, null, 'not_prepared', true];
        yield 'disiapkan, belum ada perintah' => [[], null, null, 'no_command', true];
        yield 'perintah dibuat, token hidup' => [[], ['status' => 'requested', 'release' => '1.0.0'], $live, 'awaiting_command', false];
        yield 'token hidup tanpa operasi (pendaftaran lama)' => [[], null, $live, 'awaiting_command', false];
        yield 'token habis sebelum dijalankan' => [[], ['status' => 'requested', 'release' => '1.0.0'], $expired, 'no_command', true];
        yield 'token sudah dipakai, situs belum tercatat terdaftar' => [[], ['status' => 'requested', 'release' => '1.0.0'], ['expires_at' => '2026-09-15 08:30:00', 'used_at' => self::NOW], 'no_command', true];
        yield 'terdaftar, rilis kosong' => [$enrolled, ['status' => 'requested', 'release' => null], null, 'awaiting_release', true];
        yield 'terdaftar, rilis string kosong' => [$enrolled, ['status' => 'requested', 'release' => ''], null, 'awaiting_release', true];
        yield 'terdaftar, menunggu agen mengambil' => [$enrolled, ['status' => 'requested', 'release' => '1.0.0'], null, 'connected', false];
        yield 'memasang' => [$enrolled, ['status' => 'running', 'release' => '1.0.0'], null, 'installing', false];
        yield 'terpasang' => [$enrolled + ['reported_release' => '1.0.0'], ['status' => 'succeeded', 'release' => '1.0.0'], null, 'ready', true];
        yield 'terpasang lalu berhenti melapor' => [['public_key' => 'kunci', 'enrolled_at' => self::NOW, 'last_seen_at' => '2026-09-15 07:00:00'], ['status' => 'succeeded', 'release' => '1.0.0'], null, 'stale', true];
        yield 'gagal' => [$enrolled, ['status' => 'failed', 'release' => '1.0.0'], null, 'failed', true];
        yield 'kedaluwarsa' => [$enrolled, ['status' => 'expired', 'release' => null], null, 'failed', true];
        yield 'dibatalkan' => [$enrolled, ['status' => 'cancelled', 'release' => '1.0.0'], null, 'no_command', true];
        yield 'dipasang dengan tangan sebelum pemasangan satu perintah' => [$enrolled, null, null, 'ready', true];
        yield 'dipasang dengan tangan, tidak melapor' => [['public_key' => 'kunci', 'enrolled_at' => self::NOW, 'last_seen_at' => null], null, null, 'stale', true];
        yield 'dicabut mengalahkan pemasangan yang berjalan' => [$enrolled + ['revoked_at' => self::NOW], ['status' => 'running', 'release' => '1.0.0'], null, 'revoked', true];
    }

    /**
     * @param  array<string, mixed>|null  $site
     * @param  array<string, mixed>|null  $install
     * @param  array<string, mixed>|null  $token
     */
    #[DataProvider('stages')]
    public function test_every_stage_derives_one_state(?array $site, ?array $install, ?array $token, string $state, bool $final): void
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));

        $progress = InstallProgress::derive(
            $site === null ? null : $this->site($site),
            $install === null ? null : $this->install($install),
            $token === null ? null : (new SiteEnrollmentToken)->forceFill($token),
        );

        $this->assertSame($state, $progress['state']);
        $this->assertSame($final, $progress['final']);
        $this->assertContains($progress['state'], InstallProgress::STATES);
    }

    public function test_a_failure_carries_its_step_and_reason_and_an_expiry_gets_a_sentence(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        $site = $this->site(['public_key' => 'kunci', 'enrolled_at' => self::NOW, 'last_seen_at' => self::NOW]);

        $failed = InstallProgress::derive($site, $this->install(['status' => 'failed', 'release' => '1.0.0', 'step' => 'update.sh', 'failure_message' => 'Disk penuh.']), null);
        $this->assertSame('update.sh', $failed['step']);
        $this->assertSame('Disk penuh.', $failed['failureMessage']);
        $this->assertSame('1.0.0', $failed['release']);

        $expired = InstallProgress::derive($site, $this->install(['status' => 'expired', 'release' => null]), null);
        $this->assertStringContainsString('tidak pernah diambil agen', (string) $expired['failureMessage']);

        $running = InstallProgress::derive($site, $this->install(['status' => 'running', 'release' => '1.0.0', 'step' => 'Menarik rilis']), null);
        $this->assertSame('Menarik rilis', $running['step']);
        $this->assertNull($running['failureMessage']);
    }

    public function test_a_live_token_names_its_expiry_and_an_expired_one_says_so(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        $site = $this->site([]);

        $live = InstallProgress::derive($site, null, (new SiteEnrollmentToken)->forceFill(['expires_at' => '2026-09-15 08:30:00', 'used_at' => null]));
        $this->assertSame('2026-09-15 08:30:00', $live['commandExpiresAt']);
        $this->assertFalse($live['commandExpired']);

        $expired = InstallProgress::derive($site, null, (new SiteEnrollmentToken)->forceFill(['expires_at' => '2026-09-15 07:59:59', 'used_at' => null]));
        $this->assertNull($expired['commandExpiresAt']);
        $this->assertTrue($expired['commandExpired']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function site(array $attributes): Site
    {
        return (new Site)->forceFill([
            'id' => '01J00000000000000000000000',
            'tenant_id' => '01J00000000000000000000001',
            'name' => 'Situs',
            'edition' => Site::SINGLE_IMAGE_EDITION,
            'timezone' => 'Asia/Jakarta',
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function install(array $attributes): SiteOperation
    {
        $release = $attributes['release'];
        unset($attributes['release']);

        return (new SiteOperation)->forceFill([
            'operation' => 'install',
            'parameters' => ['release' => $release],
            ...$attributes,
        ]);
    }
}
