<?php

return [
    // Canonical official modules. Entitlements and releases reference these IDs.
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
    'module_catalog' => [
        [
            'id' => 'procurement',
            'name' => 'Procurement',
            'version' => '0.1.0',
            'status' => 'available',
            'database' => 'core_module_procurement',
            'ui_entry' => env('COREERP_PROCUREMENT_UI_ENTRY', '/modules/procurement/'),
            'description' => 'Official independent Procurement module.',
            'duties' => [
                'procurement.requisition.maintain' => [
                    'name' => 'Kelola permintaan pengadaan',
                    'permissions' => [
                        'procurement.requisition.read',
                        'procurement.requisition.create',
                        'procurement.requisition.update',
                    ],
                ],
                'procurement.requisition.approve' => [
                    'name' => 'Setujui permintaan pengadaan',
                    'permissions' => [
                        'procurement.requisition.read',
                        'procurement.requisition.approve',
                    ],
                ],
                'procurement.settings.manage' => [
                    'name' => 'Kelola pengaturan pengadaan',
                    'permissions' => ['procurement.settings.manage'],
                ],
            ],
            'permissions' => [
                'procurement.requisition.read' => 'Read procurement requisitions',
                'procurement.requisition.create' => 'Create procurement requisitions',
                'procurement.requisition.update' => 'Update procurement requisitions',
                'procurement.requisition.approve' => 'Approve procurement requisitions',
                'procurement.settings.manage' => 'Manage procurement settings',
            ],
        ],
        [
            'id' => 'management-asset',
            'name' => 'Management Asset',
            'version' => '0.1.0',
            'status' => 'available',
            'database' => 'core_module_management_asset',
            'ui_entry' => env('COREERP_MANAGEMENT_ASSET_UI_ENTRY', '/modules/management-asset/'),
            'description' => 'Official independent Management Asset module.',
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
