<?php

return [
    // Canonical official apps. Entitlements and releases reference these IDs.
    'database' => 'core_erp',
    'deployment' => [
        'profile' => env('COREERP_DEPLOYMENT_PROFILE', 'pooled'),
        'placement' => env('COREERP_DEPLOYMENT_PLACEMENT', 'pooled-primary'),
        'pull_images' => env('COREERP_DEPLOYMENT_PULL_IMAGES', true),
        'release_root' => env('COREERP_RELEASE_ROOT'),
    ],
    'provider' => [
        'email' => env('COREERP_PROVIDER_EMAIL', 'provider@coreerp.local'),
        'password' => env('COREERP_PROVIDER_PASSWORD'),
    ],
    'app_context_signing_key' => env('COREERP_APP_CONTEXT_SIGNING_KEY'),
    'event_endpoints' => json_decode((string) env('COREERP_EVENT_ENDPOINTS', '[]'), true) ?: [],

    // Requests per minute per app+tenant on the internal number sequence API. Sized for normal document traffic,
    // not for a caller trying to burn a tenant's number range.
    'internal_api_rate_limit' => env('COREERP_INTERNAL_API_RATE_LIMIT', 600),
    'registration_rate_limit' => env('COREERP_REGISTRATION_RATE_LIMIT', 5),
    'password_breach_check' => env('COREERP_PASSWORD_BREACH_CHECK', true),

    // Reserved-but-unfinished reservations allowed per continuous sequence. An app that reserves and never confirms
    // is broken; stopping it early keeps the pool usable and makes the fault obvious.
    'max_outstanding_reservations' => env('COREERP_MAX_OUTSTANDING_RESERVATIONS', 500),

    // Retention for rows that only exist to prove a number was handed out. Confirmed pool rows are safe to drop once
    // the issue record exists; audit events are kept far longer because they are the compliance trail.
    'confirmed_pool_retention_days' => env('COREERP_CONFIRMED_POOL_RETENTION_DAYS', 30),
    'audit_retention_days' => env('COREERP_AUDIT_RETENTION_DAYS', 400),

    'operating_unit_types' => [
        'business_unit' => 'Business unit',
        'department' => 'Department',
        'cost_center' => 'Cost center',
        'value_stream' => 'Value stream',
        'retail_channel' => 'Retail channel',
    ],
];
