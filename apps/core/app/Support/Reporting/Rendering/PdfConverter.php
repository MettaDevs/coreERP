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
 *
 * ## Kenapa di sini TIDAK ada penjagaan sambungan keluar, dan jangan ditambahkan
 *
 * Ketika sebuah produksi disalin menjadi sandbox, salinannya dilucuti sambungan keluarnya:
 * penerbit event berhenti mengirim, laporan ke Discord ditekan, dan sisanya ditolak jaring
 * global. Titik ini sengaja berada di luar daftar itu, dan `JaringSambunganKeluar` malah
 * mengecualikan alamatnya dengan sengaja.
 *
 * Alasannya ada pada kalimat pertama docblock ini. Yang dilucuti dari sebuah salinan adalah
 * kemampuannya menghubungi **pihak yang sebenarnya** — pelanggan yang menerima email, sistem
 * yang menerima webhook, penyedia pembayaran. Gotenberg bukan salah satunya: ia berada di
 * dalam deployment yang sama, tidak tahu apa-apa tentang tenant, dan tidak menyimpan sebaris
 * pun dari dokumen yang lewat. Memblokirnya tidak melindungi seorang pelanggan pun.
 *
 * Yang dirusaknya justru nyata: pencetakan mati di **setiap** sandbox dan setiap demo — dan
 * mencetak adalah hal pertama yang orang coba ketika ia diperlihatkan produk ini. Fitur yang
 * hanya bisa dicoba di produksi persis kebalikan dari alasan sandbox dibangun.
 *
 * Jadi kalau suatu saat seseorang membaca daftar pelucutan lalu merasa titik ini "terlewat":
 * ia tidak terlewat. Kalimat ini yang menahannya, dan ada test yang membuktikan perender
 * tetap berhasil di lingkungan yang sambungan keluarnya sudah dimatikan.
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
