<?php

declare(strict_types=1);

namespace ControlPlane\Products;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProductTenants
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $input = []): array
    {
        $endpoint = rtrim((string) config('core.base_url'), '/').'/api/internal/v1/'.$path;

        try {
            $response = Http::withToken((string) config('core.token'))->acceptJson()
                ->timeout(max(1, (int) config('core.timeout')))
                ->send($method, $endpoint, $method === 'GET' ? ['query' => $input] : ['json' => $input]);
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages(['core' => 'Core belum menjawab. Pembuatan mungkin sudah diterima; periksa daftar sebelum mencoba lagi.']);
        }

        $payload = $response->json();
        abort_if($method === 'GET' && $response->status() === 404, 404);

        if ($response->failed()) {
            $errors = is_array($payload) ? ($payload['errors'] ?? null) : null;

            if (is_array($errors)) {
                $messages = [];
                foreach ($errors as $field => $items) {
                    if (! is_string($field)) {
                        continue;
                    }
                    $text = array_values(array_filter(is_array($items) ? $items : [$items], is_string(...)));
                    if ($text !== []) {
                        $messages[$field] = $text;
                    }
                }
                if ($messages !== []) {
                    throw ValidationException::withMessages($messages);
                }
            }

            throw ValidationException::withMessages(['core' => is_array($payload) && is_string($payload['message'] ?? null)
                ? $payload['message'] : 'Permintaan belum dapat diproses. Periksa koneksi dan pengaturan Core.']);
        }

        if (! in_array($response->status(), [200, 201, 202], true) || ! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages(['core' => 'Jawaban Core belum dapat dibaca. Periksa status tenant sebelum mencoba lagi.']);
        }

        $rules = match (true) {
            $path === 'managed-products' => [
                'products' => ['present', 'array'],
                'products.*.code' => ['required', 'string'],
                'products.*.name' => ['required', 'string'],
                'products.*.profiles' => ['present', 'array'],
                'products.*.profiles.*.code' => ['required', 'string'],
                'products.*.profiles.*.name' => ['required', 'string'],
                'products.*.profiles.*.description' => ['present', 'string'],
            ],
            $method === 'GET' && $path === 'product-tenants' => ['data' => ['present', 'array'], 'next_cursor' => ['present', 'nullable', 'string']],
            default => ['id' => ['required', 'uuid'], 'status' => ['required', 'in:queued,provisioning,ready,failed']],
        };

        if (($method === 'GET' && $response->status() !== 200) || Validator::make($payload, $rules)->fails()) {
            throw ValidationException::withMessages(['core' => 'Jawaban Core belum lengkap. Periksa status tenant sebelum mencoba lagi.']);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
