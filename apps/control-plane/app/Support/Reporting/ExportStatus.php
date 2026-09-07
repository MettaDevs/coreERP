<?php

namespace App\Support\Reporting;

/** Kosakata status ekspor; satu-satunya tempat kata ini ditulis di sisi Core. */
final class ExportStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public static function isActive(string $status): bool
    {
        return $status === self::QUEUED || $status === self::RUNNING;
    }
}
