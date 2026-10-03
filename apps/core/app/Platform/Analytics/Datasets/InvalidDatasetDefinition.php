<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use LogicException;

/**
 * Definisi dataset yang tidak dapat dipakai engine. Di runtime dataset itu dilewati dan dicatat
 * (`DatasetRegistry`); pesannya menyebut sebab tanpa data tenant apa pun, ditulis untuk pengembang
 * module yang mendaftarkannya.
 */
final class InvalidDatasetDefinition extends LogicException {}
