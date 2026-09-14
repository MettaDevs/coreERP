<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use RuntimeException;

/**
 * Tanda tangan permintaan agen tidak dapat diterima.
 *
 * Pesannya untuk log, tidak pernah untuk jawaban HTTP. Jawaban yang menyebut bagian mana yang salah
 * — digest, jam, atau kuncinya — mengajari penyerang bagian mana yang sudah benar.
 */
final class SignatureInvalid extends RuntimeException {}
