<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

final class NumberSequenceFormat
{
    public const SEGMENT_TYPES = ['constant', 'number', 'year', 'scope', 'fiscal_year', 'fiscal_period', 'context'];
}
