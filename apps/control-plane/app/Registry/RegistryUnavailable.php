<?php

declare(strict_types=1);

namespace ControlPlane\Registry;

use RuntimeException;

/**
 * Registry belum disetel, tidak terjangkau, atau menjawab di luar dugaan.
 *
 * Pesannya boleh sampai ke log dan ke halaman Pengaturan, jadi tidak pernah memuat rahasia robot.
 */
final class RegistryUnavailable extends RuntimeException {}
