<?php

declare(strict_types=1);

namespace ControlPlane\Dns;

use RuntimeException;

/**
 * DNS belum disetel, Cloudflare tidak terjangkau, menolak, atau menjawab di luar dugaan.
 *
 * Pesannya boleh sampai ke layar operator dan ke log, jadi tidak pernah memuat token.
 */
final class DnsUnavailable extends RuntimeException {}
