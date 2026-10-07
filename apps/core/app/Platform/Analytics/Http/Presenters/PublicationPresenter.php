<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Presenters;

use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\External\PublicationErrors;
use App\Platform\Analytics\External\PublicationReader;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Security\PublicationAccess;
use App\Platform\Integration\Models\IntegrationClient;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Support\Collection;

/**
 * Bentuk publikasi untuk layar Publikasi data (butir 15.2), termasuk keadaannya hari ini: publikasi aktif yang
 * pemiliknya kehilangan hak tampil "tertahan", dan yang datanya tidak dapat dihitung lagi tampil "perlu
 * diperbaiki" — pemeriksaan yang sama dengan yang dijalankan saat sistem luar membacanya, tanpa menjalankan
 * query-nya.
 *
 * `saved_query.changed` menandai query tersimpan yang sudah berubah sejak dipublikasikan: perubahannya belum
 * keluar sampai pemilik publikasi menerapkannya.
 */
final class PublicationPresenter
{
    public function __construct(
        private readonly PublicationAccess $access,
        private readonly PublicationReader $reader,
        private readonly DatasetRegistry $datasets,
    ) {}

    /**
     * @param  Collection<int, Publication>  $publications
     * @return list<array<string, mixed>>
     */
    public function list(Collection $publications, TenantMembership $membership, bool $canManage): array
    {
        $clientIds = $publications->flatMap(static fn (Publication $publication): array => $publication->client_ids)->unique()->values()->all();
        $clients = IntegrationClient::query()->where('tenant_id', $membership->tenant_id)->whereIn('id', $clientIds)->get()->keyBy('id');

        return array_values($publications->map(fn (Publication $publication): array => $this->one($publication, $membership, $canManage, $clients))->all());
    }

    /**
     * @param  ?Collection<string, IntegrationClient>  $clients
     * @return array<string, mixed>
     */
    public function one(Publication $publication, TenantMembership $membership, bool $canManage, ?Collection $clients = null): array
    {
        $publication->loadMissing(['owner:id,name', 'savedQuery' => static fn ($query) => $query->withTrashed()]);
        $clients ??= IntegrationClient::query()->where('tenant_id', $publication->tenant_id)->whereIn('id', $publication->client_ids)->get()->keyBy('id');
        $saved = $publication->savedQuery;
        $dataset = $publication->dataset_code === null ? null : $this->datasets->find($publication->dataset_code);
        $isOwner = $publication->owner_user_id === (int) $membership->user_id;
        $live = $publication->status !== Publication::REVOKED;

        return [
            'id' => $publication->id,
            'version' => $publication->version,
            'code' => $publication->code,
            'name' => $publication->name,
            'description' => $publication->description,
            'status' => $publication->status,
            'owner' => ['id' => $publication->owner_user_id, 'name' => $publication->owner->name ?? null],
            'is_owner' => $isOwner,
            'saved_query' => $saved === null ? null : [
                'id' => $saved->id,
                'name' => $saved->name,
                'archived' => $saved->deleted_at !== null,
                'changed' => $this->changed($publication, $saved),
            ],
            'dataset' => $publication->dataset_code === null ? null : [
                'code' => $publication->dataset_code,
                'caption' => $dataset->caption ?? $publication->dataset_code,
            ],
            'locked_filters' => $publication->dataset_code === null ? [] : (object) $publication->lockedFiltersFor($publication->dataset_code),
            'clients' => array_map(static fn (string $id): array => [
                'id' => $id,
                'name' => $clients->get($id)->name ?? null,
                'active' => ($clients->get($id)->status ?? null) === IntegrationClient::ACTIVE,
            ], $publication->client_ids),
            'formats' => $publication->formats,
            'min_group_size' => $publication->min_group_size,
            'timezone' => $publication->timezone,
            'health' => $live ? $this->health($publication) : ['state' => 'revoked', 'message' => null],
            'last_used_at' => $publication->last_used_at?->toIso8601String(),
            'updated_at' => $publication->updated_at?->toIso8601String(),
            'can_manage' => $canManage && $live,
            'can_edit' => $canManage && $live && $isOwner,
        ];
    }

    /**
     * Keadaan publikasi bila dibaca sekarang: `ok`, `suspended` (pemiliknya tidak lagi berhak), atau `unavailable`
     * (datanya tidak dapat dihitung), dengan alasan dari pesan yang diterima sistem luar.
     *
     * @return array{state: string, message: ?string}
     */
    private function health(Publication $publication): array
    {
        try {
            $this->reader->prepare($publication, $this->access->principal($publication));
        } catch (AnalyticsQueryException $e) {
            return [
                'state' => $e->errorCode === 'analytics.publication_suspended' ? 'suspended' : 'unavailable',
                'message' => PublicationErrors::reason($e),
            ];
        }

        return ['state' => 'ok', 'message' => null];
    }

    /** Query tersimpan sudah berbeda dari salinan yang dipublikasikan (data atau isi query-nya). */
    private function changed(Publication $publication, SavedQuery $saved): bool
    {
        if ($saved->dataset_code !== $publication->dataset_code) {
            return true;
        }

        // Kolom jsonb tidak menjaga urutan kunci, jadi perbandingannya longgar atas isi, bukan atas teks.
        return StoredQuery::ordered($saved->query) != StoredQuery::ordered($publication->query ?? []);
    }
}
