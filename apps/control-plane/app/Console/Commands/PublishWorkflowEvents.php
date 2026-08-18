<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PublishWorkflowEvents extends Command
{
    /** @var list<string> */
    private const TYPES = [
        'core.workflow.decision.v2',
        'core.tenant.provisioned.v1',
    ];

    protected $signature = 'workflow-events:publish {--limit=100}';

    protected $description = 'Kirim keputusan workflow yang belum terkirim ke aplikasi penerima.';

    public function handle(): int
    {
        $key = (string) config('coreerp.app_context_signing_key');
        $endpoints = collect(config('coreerp.event_endpoints', []))
            ->filter(fn (mixed $endpoint): bool => is_array($endpoint) && in_array($endpoint['type'] ?? null, self::TYPES, true) && isset($endpoint['url']))
            ->values();
        if ($key === '' || $endpoints->isEmpty()) {
            return self::SUCCESS;
        }

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
}
