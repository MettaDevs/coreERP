<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fixture katalog untuk test. Bentuknya sengaja memakai empat lapis
        // Dynamics 365 yang berbeda — entry point, permission, privilege, duty —
        // supaya test membuktikan rantai yang sebenarnya, bukan satu lapis
        // bersalin tiga. Katalog produksi datang dari manifest app, bukan config.
        config()->set('coreerp.app_catalog', [[
            'id' => 'management-aset',
            'name' => 'Management Aset',
            'version' => '0.1.0',
            'status' => 'available',
            'database' => 'app_erp_management_aset',
            'ui_entry' => '/apps-content/management-aset/',
            'navigation' => [
                'rail' => [
                    ['id' => 'master', 'label' => 'Master data'],
                ],
                'sidebar' => [
                    'master' => [
                        ['id' => 'entitas-aset', 'label' => 'Entitas aset', 'permission' => 'management-aset.entitas-aset.read'],
                        ['id' => 'group-aset', 'label' => 'Group aset', 'permission' => 'management-aset.group-aset.read'],
                    ],
                ],
            ],
            'contract_url' => 'https://contracts.example.test/management-aset/openapi.yaml',
            'description' => 'Test catalog app.',
            'entry_points' => [
                ['code' => 'management-aset.entitas-aset.form', 'name' => 'Layar entitas aset', 'type' => 'form'],
                ['code' => 'management-aset.entitas-aset.api', 'name' => 'API entitas aset', 'type' => 'api'],
                ['code' => 'management-aset.group-aset.form', 'name' => 'Layar group aset', 'type' => 'form'],
                ['code' => 'management-aset.group-aset.api', 'name' => 'API group aset', 'type' => 'api'],
            ],
            'permissions' => [
                ['code' => 'management-aset.entitas-aset.read', 'name' => 'Lihat entitas aset', 'entry_point' => 'management-aset.entitas-aset.form', 'access' => 'read'],
                ['code' => 'management-aset.entitas-aset.create', 'name' => 'Tambah entitas aset', 'entry_point' => 'management-aset.entitas-aset.api', 'access' => 'create'],
                ['code' => 'management-aset.entitas-aset.update', 'name' => 'Ubah entitas aset', 'entry_point' => 'management-aset.entitas-aset.api', 'access' => 'update'],
                ['code' => 'management-aset.entitas-aset.archive', 'name' => 'Arsipkan entitas aset', 'entry_point' => 'management-aset.entitas-aset.api', 'access' => 'delete'],
                ['code' => 'management-aset.group-aset.read', 'name' => 'Lihat group aset', 'entry_point' => 'management-aset.group-aset.form', 'access' => 'read'],
                ['code' => 'management-aset.group-aset.create', 'name' => 'Tambah group aset', 'entry_point' => 'management-aset.group-aset.api', 'access' => 'create'],
                ['code' => 'management-aset.group-aset.update', 'name' => 'Ubah group aset', 'entry_point' => 'management-aset.group-aset.api', 'access' => 'update'],
                ['code' => 'management-aset.group-aset.archive', 'name' => 'Arsipkan group aset', 'entry_point' => 'management-aset.group-aset.api', 'access' => 'delete'],
            ],
            'privileges' => [
                [
                    'code' => 'management-aset.entitas-aset.maintain',
                    'name' => 'Pelihara entitas aset',
                    'permissions' => [
                        'management-aset.entitas-aset.read',
                        'management-aset.entitas-aset.create',
                        'management-aset.entitas-aset.update',
                    ],
                ],
                [
                    'code' => 'management-aset.entitas-aset.retire',
                    'name' => 'Arsipkan entitas aset',
                    'permissions' => ['management-aset.entitas-aset.archive'],
                ],
                [
                    'code' => 'management-aset.group-aset.maintain',
                    'name' => 'Pelihara group aset',
                    'permissions' => [
                        'management-aset.group-aset.read',
                        'management-aset.group-aset.create',
                        'management-aset.group-aset.update',
                    ],
                ],
                [
                    'code' => 'management-aset.group-aset.retire',
                    'name' => 'Arsipkan group aset',
                    'permissions' => ['management-aset.group-aset.archive'],
                ],
            ],
            'duties' => [
                [
                    'code' => 'management-aset.entitas-aset.manage',
                    'name' => 'Kelola entitas aset',
                    'privileges' => [
                        'management-aset.entitas-aset.maintain',
                        'management-aset.entitas-aset.retire',
                    ],
                ],
                [
                    'code' => 'management-aset.group-aset.manage',
                    'name' => 'Kelola group aset',
                    'privileges' => [
                        'management-aset.group-aset.maintain',
                        'management-aset.group-aset.retire',
                    ],
                ],
            ],
        ]]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
