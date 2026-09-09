<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mengirim event Core ke penerima yang berada **di luar** proses.
 *
 * Perintah ini sengaja dipertahankan setelah F3-09. App yang masih berjalan sebagai proses
 * tersendiri — human-resources dan procurement hari ini — tidak punya jalur lain untuk tahu
 * sebuah keputusan sudah diambil.
 *
 * Yang berubah: penerima yang kodenya berjalan di runtime ini **tidak lagi dikirimi HTTP**.
 * Ia sudah menerima event yang sama secara langsung pada transaksi keputusannya. Mengirimnya
 * lagi berarti satu permintaan ke alamat yang tidak lagi ada, lalu sebuah kegagalan koneksi
 * yang dicatat sebagai masalah padahal keputusannya justru sudah sampai.
 */
class PublishWorkflowEvents extends Command
{
    /** @var list<string> */
    private const TYPES = [
        'core.workflow.decision.v2',
        'core.tenant.provisioned.v1',
    ];

    protected $signature = 'workflow-events:publish {--limit=100}';

    protected $description = 'Kirim keputusan workflow yang belum terkirim ke aplikasi penerima.';

    public function handle(ModuleRegistry $registry): int
    {
        $key = (string) config('coreerp.app_context_signing_key');
        $semua = collect(config('coreerp.event_endpoints', []))
            ->filter(fn (mixed $endpoint): bool => is_array($endpoint) && in_array($endpoint['type'] ?? null, self::TYPES, true) && isset($endpoint['url']))
            ->values();
        if ($key === '' || $semua->isEmpty()) {
            return self::SUCCESS;
        }

        $dalamProses = $this->idModulDalamProses($registry);
        $endpoints = $semua
            ->reject(fn (array $endpoint): bool => isset($endpoint['module']) && in_array($endpoint['module'], $dalamProses, true))
            ->values();

        $events = DB::table('outbox_events')->whereIn('type', self::TYPES)->whereNull('published_at')
            ->orderBy('occurred_at')->limit((int) $this->option('limit'))->get();
        foreach ($events as $event) {
            // correlation_id is required by the v2 envelope. A row without one would put a
            // contract-violating payload on the wire, so it is held back and reported
            // instead of being sent as a half-formed event.
            if (($event->correlation_id ?? null) === null) {
                report(new RuntimeException("Outbox event {$event->id} has no correlation_id and was not published."));

                continue;
            }
            $eventEndpoints = $endpoints->where('type', $event->type)->values();
            if ($eventEndpoints->isEmpty()) {
                // Seluruh penerima jenis ini berada di dalam proses: eventnya sudah sampai
                // saat keputusannya diambil. Barisnya ditandai terkirim, karena baris yang
                // tidak pernah ditandai akan diambil ulang setiap kali perintah ini berjalan,
                // selamanya, dan antrean yang tidak pernah menyusut menyembunyikan baris yang
                // benar-benar gagal terkirim.
                //
                // Jenis yang tidak punya penerima sama sekali tetap dibiarkan menggantung —
                // itu konfigurasi yang belum lengkap, bukan event yang sudah sampai.
                if ($semua->where('type', $event->type)->isNotEmpty()) {
                    DB::table('outbox_events')->where('id', $event->id)->whereNull('published_at')->update(['published_at' => now(), 'updated_at' => now()]);
                }

                continue;
            }
            $body = json_encode(array_filter([
                'id' => $event->id, 'type' => $event->type, 'occurred_at' => $event->occurred_at,
                'tenant_id' => $event->tenant_id, 'correlation_id' => $event->correlation_id,
                'legal_entity_id' => $event->legal_entity_id,
                'data' => json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR),
            ], fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $key);
            try {
                foreach ($eventEndpoints as $endpoint) {
                    Http::acceptJson()->connectTimeout(2)->timeout(5)->withBody($body, 'application/json')
                        ->withHeaders(['X-CoreERP-Event-Timestamp' => $timestamp, 'X-CoreERP-Event-Signature' => $signature])
                        ->post((string) $endpoint['url'])->throw();
                }
            } catch (ConnectionException|RequestException $exception) {
                report($exception);

                continue;
            }
            DB::table('outbox_events')->where('id', $event->id)->whereNull('published_at')->update(['published_at' => now(), 'updated_at' => now()]);
        }

        return self::SUCCESS;
    }

    /**
     * Id module yang kodenya dimuat runtime ini.
     *
     * Dipakai untuk melewatkan endpoint yang penerimanya berada di dalam proses. Penandanya
     * kunci `module` pada setelan endpoint, bukan tebakan atas bentuk URL-nya: sebuah tebakan
     * akan meleset pada hari seseorang memasang module di belakang alamat yang berbeda, dan
     * melesetnya berupa event yang tidak pernah dikirim — kegagalan yang paling sulit dilihat.
     *
     * Setelan tanpa kunci `module` selalu dianggap di luar proses. Itu bawaan yang benar: app
     * lama memang tidak menyebutnya, dan menganggapnya di dalam proses berarti berhenti
     * mengirim event ke app yang masih hidup sebagai proses tersendiri.
     *
     * Daftarnya `semuaTermasukYangSedangDipindah()`, bukan `semua()`: yang menentukan di sini
     * adalah apakah **kodenya dimuat** runtime ini, bukan apakah module itu sudah boleh
     * dipasang untuk tenant.
     *
     * @return list<string>
     */
    private function idModulDalamProses(ModuleRegistry $registry): array
    {
        return array_map(
            static fn (ModuleManifest $module): string => $module->id,
            $registry->semuaTermasukYangSedangDipindah(),
        );
    }
}
