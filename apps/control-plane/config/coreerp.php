<?php

return [
    // Canonical official apps. Entitlements and releases reference these IDs.
    'database' => 'core_erp',
    'deployment' => [
        'profile' => env('COREERP_DEPLOYMENT_PROFILE', 'pooled'),
        'placement' => env('COREERP_DEPLOYMENT_PLACEMENT', 'pooled-primary'),
        'pull_images' => env('COREERP_DEPLOYMENT_PULL_IMAGES', true),
    ],
    'provider' => [
        'email' => env('COREERP_PROVIDER_EMAIL', 'provider@coreerp.local'),
        'password' => env('COREERP_PROVIDER_PASSWORD'),
    ],
    'operating_unit_types' => [
        'business_unit' => 'Business unit',
        'department' => 'Department',
        'cost_center' => 'Cost center',
        'value_stream' => 'Value stream',
        'retail_channel' => 'Retail channel',
    ],
    'app_catalog' => [
        [
            'id' => 'management-asset',
            'name' => 'Management Asset',
            'version' => '0.1.0',
            'status' => 'available',
            'database' => 'core_app_management_asset',
            'ui_entry' => env('COREERP_MANAGEMENT_ASSET_UI_ENTRY', '/apps/management-asset/'),
            'description' => 'Official independent Management Asset app.',
            'duties' => [
                'management-asset.asset.maintain' => [
                    'name' => 'Kelola aset',
                    'permissions' => [
                        'management-asset.asset.read',
                        'management-asset.asset.create',
                        'management-asset.asset.update',
                    ],
                ],
                'management-asset.asset.retire' => [
                    'name' => 'Hentikan penggunaan aset',
                    'permissions' => [
                        'management-asset.asset.read',
                        'management-asset.asset.retire',
                    ],
                ],
                'management-asset.settings.manage' => [
                    'name' => 'Kelola pengaturan aset',
                    'permissions' => ['management-asset.settings.manage'],
                ],
            ],
            'permissions' => [
                'management-asset.asset.read' => 'Read assets',
                'management-asset.asset.create' => 'Create assets',
                'management-asset.asset.update' => 'Update assets',
                'management-asset.asset.retire' => 'Retire assets',
                'management-asset.settings.manage' => 'Manage asset settings',
            ],
        ],
    ],
];
