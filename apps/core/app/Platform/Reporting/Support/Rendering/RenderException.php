<?php

namespace App\Platform\Reporting\Support\Rendering;

use RuntimeException;
use Throwable;

/**
 * Kegagalan yang pesannya layak ditampilkan ke pengguna pada panel ekspor: layout yang
 * salah susun, engine render yang tidak dapat dihubungi, dan sejenisnya. Kegagalan lain
 * tetap dicatat di log dan ditampilkan sebagai pesan umum.
 *
 * Bawaannya **tetap**: permintaan yang sama akan gagal lagi dengan cara yang sama, jadi
 * ekspornya langsung ditandai gagal. Hanya gangguan sesaat di luar isi permintaan — layanan
 * PDF yang terlambat menjawab, menolak sambungan, atau menjawab 5xx — yang dibuat lewat
 * {@see self::transient()}, dan hanya itu yang diulang `RunReportExport`.
 */
final class RenderException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null, public readonly bool $transient = false)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function transient(string $message, ?Throwable $previous = null): self
    {
        return new self($message, $previous, transient: true);
    }
}
