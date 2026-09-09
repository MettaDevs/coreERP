<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\AppReleaseRequest;
use App\Models\AppRelease;
use App\Models\CoreApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppReleaseController extends Controller
{
    public function store(AppReleaseRequest $request, CoreApp $app): JsonResponse
    {
        $payload = $request->payload();

        if ($payload['version'] !== $app->version) {
            throw ValidationException::withMessages([
                'version' => ['Versi release harus sama dengan versi katalog. Upgrade belum tersedia.'],
            ]);
        }

        if ($app->releases()->where('version', $payload['version'])->exists()) {
            throw ValidationException::withMessages([
                'version' => ['Release untuk versi ini sudah terdaftar.'],
            ]);
        }

        $release = AppRelease::query()->create([
            'id' => (string) Str::ulid(),
            'app_id' => $app->id,
            ...$payload,
            'status' => 'available',
        ]);

        return response()->json(['data' => $this->present($release)], 201);
    }

    /** @return array{id:string,app_id:string,version:string,status:string,manifest_sha256:string,edition_image:string} */
    private function present(AppRelease $release): array
    {
        return [
            'id' => $release->id,
            'app_id' => $release->app_id,
            'version' => $release->version,
            'status' => $release->status,
            'manifest_sha256' => $release->manifest_sha256,
            'edition_image' => $release->edition_image,
        ];
    }
}
