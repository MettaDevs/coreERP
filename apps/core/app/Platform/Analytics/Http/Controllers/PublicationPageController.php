<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CorePermissions;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\External\PublicationReader;
use App\Platform\Analytics\Http\Presenters\PublicationPresenter;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Analytics\Security\PublicationPrincipal;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Integration\Models\IntegrationClient;
use App\Platform\Modules\Contracts\FieldType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Publikasi data (`/analytics/publications`, butir 15.2), dijaga `core.analytics.publication.read` di gate
 * rute. Setiap pemegang hak itu melihat **semua** publikasi tenant: layar ini menjawab "data apa yang sedang
 * dibuka ke sistem lain, dan oleh siapa".
 *
 * Prop untuk membuat publikasi — query tersimpan yang dapat dibuka yang meminta, kolom saringan terkunci per data,
 * dan klien integrasi yang memegang scope `analytics.read` — hanya dikirim kepada pemegang hak mengubah. Kolom
 * dihitung tanpa hak data pribadi, seperti publikasi itu sendiri, dan hanya untuk data yang boleh dibaca yang
 * meminta.
 */
final class PublicationPageController extends Controller
{
    public function __construct(
        private readonly PublicationPresenter $presenter,
        private readonly DashboardAccess $dashboards,
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly PersonalDataGate $personal,
        private readonly UserClock $clock,
    ) {}

    public function index(Request $request): Response
    {
        $membership = $this->currentMembership($request);
        $canManage = app(CorePermissions::class)->allows($membership, CoreSecurityCatalog::ANALYTICS_PUBLICATION_UPDATE);

        $publications = Publication::query()
            ->where('tenant_id', $membership->tenant_id)
            ->orderByRaw("case status when 'active' then 0 when 'paused' then 1 else 2 end")
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get();

        $savedQueries = [];
        $datasets = [];
        $clients = [];
        if ($canManage) {
            $principal = PublicationPrincipal::make(new Publication([
                'tenant_id' => $membership->tenant_id,
                'timezone' => $this->clock->timezone($request),
            ]), $membership);

            $codes = $publications->pluck('dataset_code')->filter()->all();
            foreach ($this->dashboards->visible(SavedQuery::query(), $membership)->orderByRaw('lower(name)')->get() as $saved) {
                $savedQueries[] = ['id' => $saved->id, 'name' => $saved->name, 'code' => $saved->code, 'dataset_code' => $saved->dataset_code];
                $codes[] = $saved->dataset_code;
            }

            foreach (array_unique($codes) as $code) {
                $dataset = $this->datasets->find($code);
                if ($dataset === null || ! $this->access->allows($principal, $dataset)) {
                    continue;
                }
                $fields = [];
                foreach ($this->personal->visibleFields($dataset, $principal) as $field) {
                    $entry = ['key' => $field->key, 'caption' => $field->caption, 'type' => $field->type->value];
                    if ($field->type === FieldType::Option) {
                        $entry['options'] = array_map(
                            static fn (int|string $value, string $label): array => ['value' => (string) $value, 'label' => $label],
                            array_keys($field->options),
                            array_values($field->options),
                        );
                    }
                    $fields[] = $entry;
                }
                $datasets[$code] = [
                    'code' => $dataset->code,
                    'caption' => $dataset->caption,
                    'fields' => $fields,
                    'can_hide_small_groups' => PublicationReader::countMeasure($dataset) !== null,
                ];
            }
            // Saved query yang datanya tidak dapat dibaca yang meminta tidak ditawarkan.
            $savedQueries = array_values(array_filter($savedQueries, static fn (array $saved): bool => isset($datasets[$saved['dataset_code']])));

            $clients = IntegrationClient::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('status', IntegrationClient::ACTIVE)
                ->orderBy('name')
                ->get()
                ->map(static fn (IntegrationClient $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'can_read' => $client->hasScope('analytics.read'),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('platform/analytics/publications', [
            'publications' => $this->presenter->list($publications, $membership, $canManage),
            'savedQueries' => $savedQueries,
            'datasets' => (object) $datasets,
            'clients' => $clients,
            'canManage' => $canManage,
            'endpoint' => url('/api/internal/v1/analytics/publications'),
            'guideUrl' => route('docs.portal', ['spec' => 'integrasi-analitik']),
        ]);
    }
}
