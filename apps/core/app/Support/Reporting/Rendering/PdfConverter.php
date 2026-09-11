<?php

namespace App\Support\Reporting\Rendering;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mengubah dokumen Office menjadi PDF lewat service render milik Core (Gotenberg).
 *
 * Service itu stateless: menerima satu berkas, mengembalikan PDF, tidak menyimpan apa pun
 * dan tidak mengenal tenant. Karena itu ia boleh dipakai bersama oleh semua app dan
 * semua tenant pada satu deployment, dan pada on-prem cukup satu container tambahan.
 */
final class PdfConverter
{
    public function convert(RenderedFile $source): RenderedFile
    {
        $url = rtrim((string) config('reporting.renderer_url'), '/');
        if ($url === '') {
            throw new RenderException('Layanan PDF belum dikonfigurasi pada deployment ini. Pilih format Word atau Excel, atau hubungi administrator.');
        }

        try {
            $response = Http::timeout((int) config('reporting.renderer_timeout'))
                ->attach('files', file_get_contents($source->localPath), 'dokumen.'.$source->format)
                ->post($url.'/forms/libreoffice/convert');
        } catch (ConnectionException $exception) {
            throw new RenderException('Layanan PDF tidak dapat dihubungi. Coba lagi beberapa saat, atau pilih format Word atau Excel.', previous: $exception);
        }
        if (! $response->successful()) {
            throw new RenderException('Layanan PDF menolak dokumen ('.$response->status().'). Periksa layout, lalu coba lagi.');
        }

        $path = tempnam(sys_get_temp_dir(), 'laporan-');
        if ($path === false) {
            throw new RenderException('Direktori sementara tidak dapat ditulis.');
        }
        @unlink($path);
        $path .= '.pdf';
        file_put_contents($path, $response->body());

        return new RenderedFile($path, 'pdf');
    }
}
