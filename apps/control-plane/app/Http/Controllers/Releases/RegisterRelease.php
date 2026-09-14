<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Releases;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Sites\ReleaseRegistry;
use ControlPlane\Sites\SiteRejected;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Pendaftaran berkas rilis oleh alur rilis. Kontraknya `POST /api/releases/v1`.
 *
 * Tokennya dibandingkan dengan `hash_equals`, dan token yang tidak disetel berarti pintu ini tertutup.
 * Token yang benar pun belum cukup: berkasnya tetap harus bertanda tangan kunci rilis, dan itu yang
 * sebenarnya menjaga — token hanya membatasi siapa yang boleh mencoba.
 */
final class RegisterRelease extends Controller
{
    private const FIELDS = ['manifest', 'compose', 'update_script', 'checksums', 'signature'];

    public function __invoke(Request $request, ReleaseRegistry $registry): JsonResponse
    {
        $configured = config('sites.release_token');
        $presented = (string) $request->bearerToken();

        if (! is_string($configured) || $configured === '' || $presented === '' || ! hash_equals($configured, $presented)) {
            return response()->json(['error' => 'release_token_invalid'], 401);
        }

        $files = [];

        foreach (self::FIELDS as $field) {
            $upload = $request->file($field);

            if (! $upload instanceof UploadedFile || ! $upload->isValid() || $upload->getSize() > 1024 * 1024) {
                return response()->json(['error' => 'invalid_request', 'message' => sprintf('Berkas %s tidak ada atau terlalu besar.', $field)], 422);
            }

            $files[$field] = (string) file_get_contents($upload->getRealPath());
        }

        try {
            $result = $registry->register($files);
        } catch (SiteRejected $e) {
            Log::warning('Pendaftaran rilis ditolak.', ['sebab' => $e->reason, 'pesan' => $e->getMessage()]);

            return response()->json(
                ['error' => $e->reason, 'message' => $e->getMessage()],
                $e->reason === 'release_conflict' ? 409 : 422,
            );
        }

        $release = $result['release'];

        Log::info('Rilis situs terdaftar.', ['edisi' => $release->edition, 'rilis' => $release->release, 'baru' => $result['created']]);

        return response()->json(
            ['edition' => $release->edition, 'release' => $release->release],
            $result['created'] ? 201 : 200,
        );
    }
}
