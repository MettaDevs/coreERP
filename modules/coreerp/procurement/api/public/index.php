<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$responses = [
    '/health' => ['data' => ['status' => 'ok', 'module' => 'procurement']],
    '/api/v1/hello' => ['data' => [
        'message' => 'Hello from Procurement.',
        'module' => 'procurement',
        'version' => '0.1.0',
    ]],
];

header('Content-Type: application/json');

if (! is_string($path) || ! array_key_exists($path, $responses)) {
    http_response_code(404);
    echo json_encode(['error' => ['code' => 'not_found', 'message' => 'Resource not found.']]);

    return;
}

echo json_encode($responses[$path]);
