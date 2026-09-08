<?php

namespace App\Support\Reporting\Rendering;

use RuntimeException;

/**
 * Kegagalan yang pesannya layak ditampilkan ke pengguna pada panel ekspor: layout yang
 * salah susun, engine render yang tidak dapat dihubungi, dan sejenisnya. Kegagalan lain
 * tetap dicatat di log dan ditampilkan sebagai pesan umum.
 */
final class RenderException extends RuntimeException {}
