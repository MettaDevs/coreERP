<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use RuntimeException;

/**
 * Isian filter yang tidak bisa dibaca {@see FieldFilterExpression}. Pesannya siap ditampilkan ke pengguna
 * apa adanya: menyebut nama kolom dan potongan isian yang salah, mis. `Filter "Nilai perolehan": "abc"
 * bukan angka.`
 */
final class InvalidFilterExpression extends RuntimeException {}
