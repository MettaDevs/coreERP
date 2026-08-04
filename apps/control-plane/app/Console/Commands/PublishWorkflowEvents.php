<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PublishWorkflowEvents extends Command
{
    protected $signature = 'workflow-events:publish {--limit=100}';

    protected $description = 'Kirim keputusan workflow yang belum terkirim ke aplikasi penerima.';

    public function handle(): int
    {
        $key = (string) config('coreerp.app_context_signing_key');
        $endpoints = collect(config('coreerp.event_endpoints', []))
            ->filter(fn (mixed $endpoint): bool => is_array($endpoint) && ($endpoint['type'] ?? null) === 'core.workflow.decision.v1' && isset($endpoint['url']))
            ->values();
        if ($key === '' || $endpoints->isEmpty()) {
            return self::SUCCESS;
        }

        $events = DB::table('outbox_events')->where('type', 'core.workflow.decision.v1')->whereNull('published_at')
            ->orderBy('occurred_at')->limit((int) $this->option('limit'))->get();
        foreach ($events as $event) {
            $body = json_encode([
                'id' => $event->id, 'type' => $event->type, 'occurred_at' => $event->occurred_at,
                'tenant_id' => $event->tenant_id, 'data' => json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR);
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $key);
            try {
                foreach ($endpoints as $endpoint) {
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
