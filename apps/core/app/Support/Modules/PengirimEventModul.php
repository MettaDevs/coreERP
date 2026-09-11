<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * Memancarkan event Core ke listener module dengan tenant aktif terikat.
 *
 * Ada satu lubang yang tidak terlihat sampai module punya model sungguhan, dan ini
 * penutupnya. Tenant aktif diikat `ResolveModuleContext`, yaitu middleware **rute module**.
 * Event Core tidak melewati rute module: keputusan workflow diambil di controller Core,
 * tenant baru dibuat pada pendaftaran usaha, dan sebagian dipancarkan dari perintah artisan
 * yang tidak punya permintaan sama sekali. Di ketiga tempat itu tenant aktif tidak terikat.
 *
 * Sebelumnya listener module tidak terganggu karena ia memakai query mentah, yang memang
 * tidak melihat `TenantScope`. Begitu query itu berpindah ke model — dan itu memang tujuan
 * pemindahan ini — setiap listener akan melempar "Query module dijalankan tanpa tenant
 * aktif". Kegagalannya berisik, jadi ia tidak akan diam-diam membocorkan data; tetapi ia
 * membatalkan transaksi yang memancarkan eventnya, sehingga persetujuan yang sah gagal
 * karena module tidak tahu tenantnya.
 *
 * Tenant diambil dari **event**, bukan dari keadaan proses. Itu bedanya dengan mengandalkan
 * ikatan yang kebetulan sudah ada: sebuah perintah yang memproses dua tenant berturut-turut
 * akan memakai ikatan pertama untuk keduanya, dan tidak ada yang gagal karenanya.
 */
final class PengirimEventModul
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly PelaksanaTenant $pelaksana,
    ) {}

    public function kirim(object $event, string $tenantId): void
    {
        $this->pelaksana->jalankanUntuk($tenantId, function () use ($event): void {
            $this->events->dispatch($event);
        });
    }
}
