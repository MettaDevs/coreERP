<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Console;

use App\Platform\ControlPlane\Products\ProductRegistrationSigningKey;
use Illuminate\Console\Command;

final class ProductRegistrationKey extends Command
{
    protected $signature = 'product-registration:signing-key {--create : Buat sekali bila belum ada}';

    protected $description = 'Tampilkan kunci publik login produk tanpa menampilkan kunci privat';

    public function handle(ProductRegistrationSigningKey $key): int
    {
        $this->line(json_encode($key->publicData((bool) $this->option('create')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
