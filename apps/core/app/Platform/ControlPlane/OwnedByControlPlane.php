<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane;

use App\Platform\Environment\Support\EnvironmentConnection;

trait OwnedByControlPlane
{
    public function getConnectionName(): ?string
    {
        if (config('coreerp.database_mode') === 'split') {
            return app(EnvironmentConnection::class)->controlPlane();
        }
        $connection = config('coreerp.control_connection');

        if (is_string($connection) && $connection !== '') {
            return $connection;
        }

        return parent::getConnectionName();
    }
}
