<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CorePermissions;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\External\PublicationEditor;
use App\Platform\Analytics\External\PublicationErrors;
use App\Platform\Analytics\External\PublicationReader;
use App\Platform\Analytics\Http\Presenters\PublicationPresenter;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\ResultColumn;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Analytics\Security\PublicationAccess;
use App\Platform\Analytics\Security\PublicationPrincipal;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * API layar Publikasi data (`api/v1/analytics/publications`, butir 15.2). Melihat dijaga
 * `core.analytics.publication.read` dan setiap perubahan `core.analytics.publication.update` di gate rute.
 *
 * Publikasi dihitung sebagai pemiliknya, jadi **hanya pemiliknya yang mengubah apa yang dibukanya** — query,
 * saringan terkunci, klien, format, dan ambang kelompok kecil — dan hanya ia yang melihat pratinjau datanya.
 * Pemegang hak publikasi lain boleh menghentikan sementara, melanjutkan, mencabut, dan mengambil alih: ambil alih
 * menjadikan dirinya pemilik, sehingga sejak saat itu jangkauannya yang dipakai. Tidak ada jalan untuk membuat
 * publikasi berjalan atas jangkauan orang lain tanpa orang itu sendiri yang menyusunnya.
 *
 * Setiap perubahan memakai versi baris (`If-Match`). Publikasi yang dicabut tidak dapat diubah atau dihidupkan
 * lagi; barisnya tetap ada sebagai riwayat, dan kodenya tetap terpakai.
 */
final class PublicationController extends Controller
{
    /** Pilihan nilai paling banyak untuk satu kolom saringan terkunci. */
    private const FIELD_VALUES_LIMIT = 500;

    /** Baris pratinjau publikasi di layar. */
    private const PREVIEW_ROWS = 50;

    public function __construct(
        private readonly PublicationEditor $editor,
        private readonly PublicationPresenter $presenter,
        private readonly PublicationAccess $access,
        private readonly PublicationReader $reader,
        private readonly UserClock $clock,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'regex:'.PublicationEditor::CODE_PATTERN],
            'description' => ['nullable', 'string', 'max:1000'],
            'saved_query_id' => ['required', 'string', 'max:26'],
            'locked_filters' => ['nullable', 'array'],
            'client_ids' => ['nullable', 'array'],
            'formats' => ['nullable', 'array'],
            'min_group_size' => ['nullable'],
        ], ['code.regex' => 'Kode hanya huruf kecil, angka, tanda hubung, dan garis bawah, diawali huruf atau angka.']);

        $publication = new Publication([
            'tenant_id' => $membership->tenant_id,
            'owner_user_id' => $membership->user_id,
            'timezone' => $this->clock->timezone($request),
        ]);
        $snapshot = $this->editor->snapshot($membership, $publication, $this->savedQuery($membership, $data['saved_query_id']));
        $principal = PublicationPrincipal::make($publication, $membership);
        $name = trim($data['name']);

        $publication->fill([
            ...$snapshot['values'],
            'name' => $name,
            'description' => $data['description'] ?? null,
            'locked_filters' => $this->editor->lockedFilters($snapshot['dataset'], $principal, $data['locked_filters'] ?? null),
            'client_ids' => $this->editor->clients($membership->tenant_id, $data['client_ids'] ?? null),
            'formats' => $this->editor->formats($data['formats'] ?? ['json']),
            'min_group_size' => $this->editor->minGroupSize($snapshot['dataset'], $snapshot['parsed'], $data['min_group_size'] ?? null),
            'code' => $this->editor->code($membership->tenant_id, $data['code'] ?? null, $name),
        ]);

        try {
            DB::transaction(fn () => $publication->save());
        } catch (UniqueConstraintViolationException) {
            throw PublicationEditor::duplicateCode();
        }

        return $this->respond($publication->refresh(), $membership, 201);
    }

    /**
     * Mengubah isi publikasi; hanya pemiliknya. `saved_query_id` mengambil salinan query tersimpan itu lagi — cara
     * menerapkan perubahan query tersimpan, atau berganti query. Bila datanya berganti, saringan terkunci wajib
     * dikirim ulang untuk data yang baru.
     */
    public function update(Request $request, Publication $publication): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->authorizeOwner($membership, $publication);
        $expected = RowVersion::expected($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'saved_query_id' => ['sometimes', 'required', 'string', 'max:26'],
            'locked_filters' => ['sometimes', 'nullable', 'array'],
            'client_ids' => ['sometimes', 'nullable', 'array'],
            'formats' => ['sometimes', 'array'],
            'min_group_size' => ['sometimes', 'nullable'],
        ]);
        $principal = PublicationPrincipal::make($publication, $membership);

        $values = [];
        if (array_key_exists('saved_query_id', $data)) {
            $snapshot = $this->editor->snapshot($membership, $publication, $this->savedQuery($membership, $data['saved_query_id']));
            if ($snapshot['dataset']->code !== $publication->dataset_code && ! array_key_exists('locked_filters', $data) && $publication->locked_filters !== []) {
                throw ValidationException::withMessages(['locked_filters' => ['Analisis ini memakai data lain. Atur ulang saringan terkunci untuk data yang baru.']]);
            }
            ['dataset' => $dataset, 'parsed' => $parsed] = $snapshot;
            $values = $snapshot['values'];
        } else {
            ['dataset' => $dataset, 'parsed' => $parsed] = $this->editor->current($publication);
        }

        if (array_key_exists('name', $data)) {
            $values['name'] = trim($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $values['description'] = $data['description'];
        }
        if (array_key_exists('locked_filters', $data)) {
            $values['locked_filters'] = $this->editor->lockedFilters($dataset, $principal, $data['locked_filters']);
        }
        if (array_key_exists('client_ids', $data)) {
            $values['client_ids'] = $this->editor->clients($membership->tenant_id, $data['client_ids']);
        }
        if (array_key_exists('formats', $data)) {
            $values['formats'] = $this->editor->formats($data['formats']);
        }
        if (array_key_exists('min_group_size', $data)) {
            $values['min_group_size'] = $this->editor->minGroupSize($dataset, $parsed, $data['min_group_size']);
        } elseif (isset($values['query']) && $publication->min_group_size !== null) {
            // Query baru harus tetap dapat menghitung jumlah baris untuk ambang yang sudah ada.
            $values['min_group_size'] = $this->editor->minGroupSize($dataset, $parsed, $publication->min_group_size);
        }

        $this->save($publication, $expected, $values);

        return $this->respond($publication->refresh(), $membership);
    }

    public function pause(Request $request, Publication $publication): JsonResponse
    {
        return $this->transition($request, $publication, Publication::ACTIVE, Publication::PAUSED, 'Hanya publikasi yang aktif yang dapat dihentikan sementara.');
    }

    public function resume(Request $request, Publication $publication): JsonResponse
    {
        return $this->transition($request, $publication, Publication::PAUSED, Publication::ACTIVE, 'Hanya publikasi yang dihentikan sementara yang dapat dilanjutkan.');
    }

    /** Mencabut berlaku pada permintaan berikutnya, dan tidak dapat dibatalkan. */
    public function revoke(Request $request, Publication $publication): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->authorizeLive($publication);
        $this->save($publication, RowVersion::expected($request), ['status' => Publication::REVOKED]);

        return $this->respond($publication->refresh(), $membership);
    }

    /**
     * Menjadikan yang meminta pemilik publikasi, misalnya karena pemilik lamanya keluar atau kehilangan hak. Ia harus
     * dapat membaca data publikasi itu sendiri; sejak saat itu publikasi dihitung dengan jangkauannya.
     */
    public function takeOver(Request $request, Publication $publication): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->authorizeLive($publication);
        $expected = RowVersion::expected($request);

        $candidate = (clone $publication)->forceFill(['owner_user_id' => (int) $membership->user_id]);
        try {
            $this->reader->prepare($candidate, PublicationPrincipal::make($candidate, $membership));
        } catch (AnalyticsQueryException $e) {
            throw ValidationException::withMessages(['owner' => [$e->errorCode === 'analytics.publication_suspended'
                ? 'Anda belum boleh membaca data publikasi ini, jadi belum dapat mengambil alihnya.'
                : PublicationErrors::reason($e)]]);
        }

        $this->save($publication, $expected, ['owner_user_id' => (int) $membership->user_id]);

        return $this->respond($publication->refresh(), $membership);
    }

    /**
     * Contoh baris yang akan dibaca sistem luar, dihitung persis seperti permintaan mereka — sebagai pemilik,
     * dengan saringan terkunci dan kelompok kecil disembunyikan. Hanya untuk pemiliknya: pratinjau oleh orang lain
     * berarti melihat data dengan jangkauan pemilik.
     */
    public function preview(Request $request, Publication $publication): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($publication->owner_user_id === (int) $membership->user_id, 403, 'Hanya pemilik publikasi yang dapat melihat contoh datanya.');

        try {
            $page = $this->reader->rows($publication, $this->access->principal($publication), [], null, self::PREVIEW_ROWS, QueryLog::SOURCE_EXPLORE);
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'columns' => array_map(static fn (ResultColumn $column): array => $column->toArray(), $page['columns']),
            'rows' => $page['rows'],
            'meta' => $page['meta'],
        ]);
    }

    /**
     * Pilihan nilai untuk satu kolom saringan terkunci: pilihan kolom pilihan dan ya/tidak dari definisinya, nilai
     * kolom rujukan beserta namanya dari data yang boleh dibaca yang meminta — tanpa hak data pribadi, seperti
     * publikasi.
     */
    public function fieldValues(Request $request, DatasetRegistry $datasets, PersonalDataGate $personal, QueryParser $parser, QueryNormalizer $normalizer, RunQuery $run): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $data = $request->validate([
            'dataset' => ['required', 'string', 'max:160'],
            'field' => ['required', 'string', 'max:64'],
        ]);

        $dataset = $datasets->find($data['dataset']);
        if ($dataset === null) {
            return AnalyticsQueryException::datasetUnknown()->toResponse();
        }
        $principal = PublicationPrincipal::make(new Publication([
            'tenant_id' => $membership->tenant_id,
            'timezone' => $this->clock->timezone($request),
        ]), $membership);
        $field = $personal->visibleFields($dataset, $principal)[$data['field']] ?? null;
        if ($field === null) {
            throw ValidationException::withMessages(['field' => ['Kolom ini tidak dapat dipakai sebagai saringan terkunci.']]);
        }

        if ($field->type === FieldType::Boolean) {
            return response()->json(['data' => [['value' => '1', 'label' => 'Ya'], ['value' => '0', 'label' => 'Tidak']], 'truncated' => false]);
        }
        if ($field->type === FieldType::Option) {
            $options = [];
            foreach ($field->options as $value => $label) {
                $options[] = ['value' => (string) $value, 'label' => $label];
            }

            return response()->json(['data' => $options, 'truncated' => false]);
        }
        if ($field->type !== FieldType::Reference) {
            throw ValidationException::withMessages(['field' => ['Kolom ini diisi dengan ekspresi saringan, bukan dipilih dari daftar.']]);
        }

        $measure = PublicationReader::countMeasure($dataset) ?? array_key_first($dataset->measures());
        try {
            $result = $run->handle($principal, $normalizer->normalize($parser->parse([
                'dataset' => $dataset->code,
                'dimensions' => [$field->key],
                'measures' => [$measure],
                'limit' => self::FIELD_VALUES_LIMIT,
            ])), source: QueryLog::SOURCE_EXPLORE);
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        $values = [];
        foreach ($result->rows as $row) {
            $value = $row[$field->key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $label = $row[$field->key.'__label'] ?? null;
            $values[] = ['value' => (string) $value, 'label' => $label === null ? (string) $value : (string) $label];
        }
        usort($values, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return response()->json(['data' => $values, 'truncated' => $result->meta['truncated']]);
    }

    private function transition(Request $request, Publication $publication, string $from, string $to, string $refusal): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->authorizeLive($publication);
        if ($publication->status !== $from) {
            throw ValidationException::withMessages(['status' => [$refusal]]);
        }
        $this->save($publication, RowVersion::expected($request), ['status' => $to]);

        return $this->respond($publication->refresh(), $membership);
    }

    /** @param array<string, mixed> $values */
    private function save(Publication $publication, int $expected, array $values): void
    {
        DB::transaction(function () use ($publication, $expected, $values): void {
            RowVersion::claim($publication, $expected);
            if ($values !== []) {
                $publication->forceFill($values)->save();
            }
        });
    }

    private function authorizeLive(Publication $publication): void
    {
        if ($publication->status === Publication::REVOKED) {
            throw ValidationException::withMessages(['status' => ['Publikasi ini sudah dicabut. Buat publikasi baru bila masih dibutuhkan.']]);
        }
    }

    private function authorizeOwner(TenantMembership $membership, Publication $publication): void
    {
        $this->authorizeLive($publication);
        abort_unless($publication->owner_user_id === (int) $membership->user_id, 403, 'Hanya pemilik publikasi yang dapat mengubah isinya. Ambil alih lebih dulu bila pemiliknya berhalangan.');
    }

    private function savedQuery(TenantMembership $membership, string $id): ?SavedQuery
    {
        return SavedQuery::query()->where('tenant_id', $membership->tenant_id)->whereKey($id)->first();
    }

    private function respond(Publication $publication, TenantMembership $membership, int $status = 200): JsonResponse
    {
        $canManage = app(CorePermissions::class)->allows($membership, CoreSecurityCatalog::ANALYTICS_PUBLICATION_UPDATE);

        return response()->json(['data' => $this->presenter->one($publication, $membership, $canManage)], $status, ['ETag' => RowVersion::etag($publication->version)]);
    }
}
