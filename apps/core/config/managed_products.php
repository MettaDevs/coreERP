<?php

return [
    'registration' => [
        'name' => 'TrialFaskes',
        'gateway_token' => env('MANAGED_REGISTRATION_TOKEN'),
        'public_origin' => env('MANAGED_REGISTRATION_ORIGIN', 'https://landing.mettadev.my.id'),
        'signing_key_id' => env('MANAGED_REGISTRATION_KEY_ID', 'trialfaskes-v1'),
        'sso' => [
            'issuer' => env('MANAGED_REGISTRATION_SSO_ISSUER', 'https://sso.mettamedic.id'),
            'client_id' => env('MANAGED_REGISTRATION_SSO_CLIENT_ID'),
            'client_secret' => env('MANAGED_REGISTRATION_SSO_CLIENT_SECRET'),
        ],
    ],
    'products' => [
        'faskes' => [
            'name' => 'TrialFaskes',
            'trial_days' => (int) env('MANAGED_FASKES_TRIAL_DAYS', 14),
            'public_trial_limit' => (int) env('MANAGED_FASKES_PUBLIC_TRIAL_LIMIT', 20),
            'owner_username' => 'owner',
            'base_domain' => env('MANAGED_FASKES_BASE_DOMAIN', 'demo.faskes.mettamedic.id'),
            'release' => [
                'version' => env('MANAGED_FASKES_RELEASE', '20261024.2'),
                'seed_sha256' => env('MANAGED_FASKES_SEED_SHA256', '6e41e2f24317cdfbaa3a992f7f3a8b6632f6b21161bde5d3a72156931f22bc3d'),
                'image_digest' => env('MANAGED_FASKES_IMAGE_DIGEST', 'sha256:ae2b6595fd2821a0c48b8da9ee1e2a5ec64a547df4cf737e6e8b5a14b7f9e54e'),
                'profile_sha256' => env('MANAGED_FASKES_PROFILE_SHA256', '030af6c0b253d06817101fb4ef1ff5f251a1229a8aa9d6b6cda28c414b732777'),
            ],
            'worker_url' => env('MANAGED_FASKES_WORKER_URL'),
            'worker_token' => env('MANAGED_FASKES_WORKER_TOKEN'),
            'profiles' => [
                'apotek' => ['name' => 'Apotek', 'description' => 'Penjualan obat bebas, pembayaran dan persediaan obat.'],
                'praktik-dokter' => ['name' => 'Praktik Dokter', 'description' => 'Pasien, pendaftaran, pemeriksaan dan resep.'],
                'klinik' => ['name' => 'Klinik', 'description' => 'Seluruh layanan dan akses klinik.'],
            ],
        ],
    ],
];
