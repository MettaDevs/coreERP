<?php

namespace App\Http\Requests\Provider;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AppCatalogRequest extends FormRequest
{
    /**
     * Jenis entry point yang dilindungi, mengikuti Dynamics 365: yang dipanggil
     * pengguna (form/menu) dan yang dipanggil program (service operation/report).
     */
    public const ENTRY_POINT_TYPES = ['form', 'menu_item', 'api', 'report', 'action'];

    /**
     * Access level Dynamics 365. `delete` dipakai untuk aksi lifecycle penghapusan
     * CoreERP (archive/void/retire); `invoke` untuk service operation tanpa CRUD.
     */
    public const ACCESS_LEVELS = ['read', 'update', 'create', 'correct', 'delete', 'invoke'];

    public function authorize(): bool
    {
        return $this->user()?->can('manage-app-catalog') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'string', 'max:40', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/'],
            // Hanya app yang berjalan sebagai container sendiri yang punya database
            // sendiri untuk disebutkan. Module berjalan di dalam runtime Core dan memakai
            // database Core, jadi menuntutnya menyebutkan nama database berarti menuntut
            // sebuah karangan. Lihat `berjalanSebagaiContainer()` untuk cara membedakannya.
            'database_name' => [
                Rule::requiredIf(fn (): bool => $this->berjalanSebagaiContainer()),
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z][a-z0-9_]*$/',
            ],
            // Jalur halaman UI ditentukan platform dari id module dan id entri menu, bukan
            // didaftarkan app. Aturannya tetap ada meski kolomnya sudah tidak dipakai: sebuah
            // manifest lama yang masih menyebutkannya harus ditolak dengan sebabnya, bukan
            // diterima lalu diabaikan diam-diam.
            'ui_entry' => ['prohibited'],
            'has_ui' => ['nullable', 'boolean'],
            'navigation' => ['nullable', 'array'],
            'navigation.rail' => ['required_with:navigation', 'array', 'min:1'],
            'navigation.rail.*.id' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'navigation.rail.*.label' => ['required', 'string', 'max:80'],
            'navigation.sidebar' => ['required_with:navigation', 'array'],
            'navigation.sidebar.*' => ['required', 'array', 'min:1'],
            'navigation.sidebar.*.*.id' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'navigation.sidebar.*.*.label' => ['required', 'string', 'max:100'],
            'navigation.sidebar.*.*.permission' => ['required', 'string', 'max:160'],
            'repository_url' => ['nullable', 'url', 'max:2048', 'starts_with:https://'],
            'contract_url' => ['nullable', 'url', 'max:2048', 'starts_with:https://'],

            // Key memakai ID app dan nilainya rentang versi. `[]` diterima untuk
            // manifest placeholder lama, tetapi daftar ID tanpa rentang ditolak
            // pada validasi lanjutan di bawah.
            'dependsOn' => ['nullable', 'array'],
            'dependsOn.*' => ['required', 'string', 'max:40', 'regex:/^(?:\^)?[0-9]+\.[0-9]+(?:\.[0-9]+)?$/'],

            'security' => ['required', 'array'],

            'security.entry_points' => ['required', 'array', 'min:1'],
            'security.entry_points.*.code' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'security.entry_points.*.name' => ['required', 'string', 'max:150'],
            'security.entry_points.*.type' => ['required', Rule::in(self::ENTRY_POINT_TYPES)],

            'security.permissions' => ['required', 'array', 'min:1'],
            'security.permissions.*.code' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'security.permissions.*.name' => ['required', 'string', 'max:150'],
            'security.permissions.*.entry_point' => ['required', 'string', 'max:160'],
            'security.permissions.*.access' => ['required', Rule::in(self::ACCESS_LEVELS)],

            'security.privileges' => ['required', 'array', 'min:1'],
            'security.privileges.*.code' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'security.privileges.*.name' => ['required', 'string', 'max:150'],
            'security.privileges.*.permissions' => ['required', 'array', 'min:1'],
            'security.privileges.*.permissions.*' => ['required', 'string', 'max:160'],

            'security.duties' => ['required', 'array', 'min:1'],
            'security.duties.*.code' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'security.duties.*.name' => ['required', 'string', 'max:150'],
            'security.duties.*.privileges' => ['required', 'array', 'min:1'],
            'security.duties.*.privileges.*' => ['required', 'string', 'max:160'],

            'security.data_policies' => ['nullable', 'array'],
            'security.data_policies.*.code' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'security.data_policies.*.name' => ['required', 'string', 'max:150'],
            'security.data_policies.*.protected_permissions' => ['required', 'array', 'min:1'],
            'security.data_policies.*.protected_permissions.*' => ['required', 'string', 'max:160'],
            'security.data_policies.*.requires_legal_entity' => ['required', 'boolean'],
            'security.data_policies.*.requires_operating_unit' => ['required', 'boolean'],
            'security.data_policies.*.allows_descendants' => ['required', 'boolean'],

            'number_sequences' => ['nullable', 'array'],
            'number_sequences.references' => ['nullable', 'array'],
            'number_sequences.references.*.code' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'number_sequences.references.*.name' => ['required', 'string', 'max:150'],
            'number_sequences.references.*.default_prefix' => ['required', 'string', 'size:4', 'regex:/^[A-Z]{4}$/'],
            'number_sequences.references.*.allowed_scopes' => ['required', 'array', 'min:1'],
            'number_sequences.references.*.allowed_scopes.*' => ['required', Rule::in(['tenant', 'legal_entity', 'operating_unit'])],

            'workflow_types' => ['nullable', 'array'],
            'workflow_types.*.code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'workflow_types.*.name' => ['required', 'string', 'max:160'],
            'workflow_types.*.scope' => ['nullable', Rule::in(['tenant', 'legal_entity'])],
            'workflow_types.*.decision_context_schema' => ['required', 'array'],

            // Laporan cetak/ekspor. Dataset tetap milik app; Core hanya mengenal
            // katalognya. Lihat docs/dev/23-document-rendering.md.
            'reports' => ['nullable', 'array'],
            'reports.*.code' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9.-]*$/'],
            'reports.*.name' => ['required', 'string', 'max:160'],
            'reports.*.description' => ['nullable', 'string', 'max:500'],
            'reports.*.permission' => ['required', 'string', 'max:160'],
            'reports.*.parameters' => ['nullable', 'array'],
            'reports.*.parameters.*' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'reports.*.builtin_layouts' => ['required', 'array', 'min:1'],
            'reports.*.builtin_layouts.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'reports.*.builtin_layouts.*.name' => ['required', 'string', 'max:120'],
            'reports.*.builtin_layouts.*.description' => ['nullable', 'string', 'max:500'],
            'reports.*.builtin_layouts.*.format' => ['required', Rule::in(['docx', 'xlsx'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ui_entry.prohibited' => 'Path konten UI ditentukan platform dari app dan placement; app tidak lagi mendaftarkannya.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $appId = $this->string('id')->toString();

            $dependencies = $this->input('dependsOn', []);
            if (is_array($dependencies) && $dependencies !== [] && array_is_list($dependencies)) {
                $validator->errors()->add('dependsOn', 'Dependency harus ditulis sebagai pasangan ID app dan rentang versi.');
            }
            foreach (is_array($dependencies) ? array_keys($dependencies) : [] as $dependencyId) {
                if (! is_string($dependencyId) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $dependencyId)) {
                    $validator->errors()->add('dependsOn', 'ID dependency harus memakai huruf kecil, angka, atau tanda hubung.');
                    break;
                }
            }

            $entryPointCodes = $this->layerCodes($validator, 'security.entry_points', $appId, 'entry point');
            $permissionCodes = $this->layerCodes($validator, 'security.permissions', $appId, 'permission');
            $privilegeCodes = $this->layerCodes($validator, 'security.privileges', $appId, 'privilege');
            $this->layerCodes($validator, 'security.duties', $appId, 'duty');

            // Setiap lapis harus benar-benar berbeda. Tanpa guard ini, manifest bisa
            // memakai satu kode untuk permission sekaligus privilege, dan rantai
            // duty -> privilege -> permission berubah menjadi satu lapis bersalin tiga.
            $collisions = array_intersect($permissionCodes, $privilegeCodes);
            if ($collisions !== []) {
                $validator->errors()->add(
                    'security.privileges',
                    'Kode privilege tidak boleh sama dengan kode permission: '.implode(', ', array_slice($collisions, 0, 5)).'.',
                );
            }

            foreach ($this->collect('security.permissions') as $permission) {
                if (! in_array($permission['entry_point'] ?? null, $entryPointCodes, true)) {
                    $validator->errors()->add('security.permissions', 'Permission harus menunjuk entry point yang dideklarasikan app yang sama.');
                    break;
                }
            }

            foreach ($this->collect('security.privileges') as $privilege) {
                if (array_diff($privilege['permissions'] ?? [], $permissionCodes) !== []) {
                    $validator->errors()->add('security.privileges', 'Privilege hanya boleh memakai permission app yang sama.');
                    break;
                }
            }

            foreach ($this->collect('security.duties') as $duty) {
                if (array_diff($duty['privileges'] ?? [], $privilegeCodes) !== []) {
                    $validator->errors()->add('security.duties', 'Duty hanya boleh memakai privilege app yang sama.');
                    break;
                }
            }

            $dataPolicyCodes = $this->collect('security.data_policies')->pluck('code')->all();
            if (count($dataPolicyCodes) !== count(array_unique($dataPolicyCodes))) {
                $validator->errors()->add('security.data_policies', 'Kode policy data tidak boleh duplikat.');
            }
            foreach ($this->collect('security.data_policies') as $policy) {
                $code = (string) ($policy['code'] ?? '');
                if (! str_starts_with($code, $appId.'.')) {
                    $validator->errors()->add('security.data_policies', 'Kode policy data harus memakai ID app sebagai awalan.');
                    break;
                }
                if (array_diff($policy['protected_permissions'] ?? [], $permissionCodes) !== []) {
                    $validator->errors()->add('security.data_policies', 'Policy data hanya boleh memakai permission app yang dideklarasikan.');
                    break;
                }
                if (($policy['allows_descendants'] ?? false) && ! ($policy['requires_operating_unit'] ?? false)) {
                    $validator->errors()->add('security.data_policies', 'Turunan organisasi hanya dapat dipakai oleh policy yang membatasi unit operasional.');
                    break;
                }
            }

            $readPermissions = $this->collect('security.permissions')
                ->where('access', 'read')
                ->pluck('code')
                ->all();
            $railIds = $this->collect('navigation.rail')->pluck('id')->all();
            $sidebar = $this->input('navigation.sidebar', []);

            if (count($railIds) !== count(array_unique($railIds))) {
                $validator->errors()->add('navigation.rail', 'ID menu utama tidak boleh duplikat.');
            }
            if (is_array($sidebar) && (array_diff(array_keys($sidebar), $railIds) !== [] || array_diff($railIds, array_keys($sidebar)) !== [])) {
                $validator->errors()->add('navigation.sidebar', 'Setiap menu utama harus mempunyai tepat satu kelompok sidebar.');
            }
            $navigationItemIds = [];
            foreach (is_array($sidebar) ? $sidebar : [] as $items) {
                foreach (is_array($items) ? $items : [] as $item) {
                    $navigationItemIds[] = $item['id'] ?? null;
                    if (! in_array($item['permission'] ?? null, $readPermissions, true)) {
                        $validator->errors()->add('navigation.sidebar', 'Menu app hanya boleh memakai permission read yang dideklarasikan app yang sama.');
                        break 2;
                    }
                }
            }
            if (count($navigationItemIds) !== count(array_unique($navigationItemIds))) {
                $validator->errors()->add('navigation.sidebar', 'ID menu app tidak boleh duplikat.');
            }

            $referenceCodes = $this->collect('number_sequences.references')->pluck('code')->all();
            if (count($referenceCodes) !== count(array_unique($referenceCodes))) {
                $validator->errors()->add('number_sequences.references', 'Kode reference nomor tidak boleh duplikat.');
            }
            foreach ($referenceCodes as $code) {
                if (! str_starts_with((string) $code, $appId.'.')) {
                    $validator->errors()->add('number_sequences.references', 'Kode reference nomor harus memakai ID app sebagai awalan.');
                    break;
                }
            }

            $workflowCodes = $this->collect('workflow_types')->pluck('code')->all();
            if (count($workflowCodes) !== count(array_unique($workflowCodes))) {
                $validator->errors()->add('workflow_types', 'Kode jenis workflow tidak boleh duplikat.');
            }
            foreach ($workflowCodes as $code) {
                if (! str_starts_with((string) $code, $appId.'.')) {
                    $validator->errors()->add('workflow_types', 'Kode jenis workflow harus memakai ID app sebagai awalan.');
                    break;
                }
            }

            $reportCodes = $this->collect('reports')->pluck('code')->all();
            if (count($reportCodes) !== count(array_unique($reportCodes))) {
                $validator->errors()->add('reports', 'Kode laporan tidak boleh duplikat.');
            }
            $permissionCodes = array_flip($this->collect('security.permissions')->pluck('code')->all());
            foreach ($this->collect('reports') as $index => $report) {
                if (! str_starts_with((string) ($report['code'] ?? ''), $appId.'.')) {
                    $validator->errors()->add("reports.$index.code", 'Kode laporan harus memakai ID app sebagai awalan.');
                }
                // Permission laporan harus permission yang dideklarasikan app ini; laporan
                // yang menunjuk hak app lain tidak dapat ditegakkan siapa pun.
                if (! isset($permissionCodes[$report['permission'] ?? ''])) {
                    $validator->errors()->add("reports.$index.permission", 'Permission laporan harus salah satu permission app ini.');
                }
                $keys = array_column($report['builtin_layouts'] ?? [], 'key');
                if (count($keys) !== count(array_unique($keys))) {
                    $validator->errors()->add("reports.$index.builtin_layouts", 'Kunci layout bawaan tidak boleh duplikat.');
                }
            }
        }];
    }

    /**
     * Kode satu lapis security wajib unik dan memakai ID app sebagai awalan.
     *
     * @return list<string>
     */
    private function layerCodes(Validator $validator, string $key, string $appId, string $label): array
    {
        $codes = array_map(fn (mixed $code): string => (string) $code, $this->collect($key)->pluck('code')->all());

        if (count($codes) !== count(array_unique($codes))) {
            $validator->errors()->add($key, 'Kode '.$label.' tidak boleh duplikat.');
        }

        foreach ($codes as $code) {
            if (! str_starts_with($code, $appId.'.')) {
                $validator->errors()->add($key, 'Kode '.$label.' harus memakai ID app sebagai awalan.');
                break;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Apakah app ini berjalan sebagai container sendiri, bukan sebagai module di dalam
     * runtime Core.
     *
     * Pembedanya sengaja bukan bendera baru di manifest: app yang ada sebagai folder di
     * `modules/` adalah module, sisanya container. Bendera manifest akan menjadi klaim yang
     * dapat berbohong — sebuah module bisa mengaku container demi lolos pemeriksaan lain —
     * sedangkan keberadaan folder adalah kenyataan yang sama dengan yang dipakai runtime
     * untuk memuat module. Satu sumber kebenaran, bukan dua yang bisa berselisih.
     *
     * Id kosong dihitung sebagai container supaya manifest tanpa id tidak diam-diam
     * membebaskan diri dari kewajiban ini; aturan `id` sendiri yang akan melaporkannya.
     */
    private function berjalanSebagaiContainer(): bool
    {
        $id = $this->string('id')->toString();

        return $id === '' || app(ModuleRegistry::class)->cari($id) === null;
    }

    /** @return array{id:string,name:string,description:?string,version:string,database_name:?string,has_ui:bool,navigation:?array<string,mixed>,repository_url:?string,contract_url:?string,status:string} */
    public function appPayload(): array
    {
        return [
            'id' => $this->string('id')->toString(),
            'name' => $this->string('name')->trim()->toString(),
            'description' => $this->string('description')->trim()->toString() ?: null,
            'version' => $this->string('version')->toString(),
            // Module menyimpan null, bukan string kosong. Kolom yang kosong tetapi tidak
            // null masih terbaca sebagai "punya database, namanya belum diisi".
            'database_name' => $this->string('database_name')->toString() ?: null,
            'has_ui' => $this->boolean('has_ui'),
            'navigation' => $this->navigationPayload(),
            'repository_url' => $this->string('repository_url')->toString() ?: null,
            'contract_url' => $this->string('contract_url')->toString() ?: null,
            'status' => 'available',
        ];
    }

    /** @return array<string, string> */
    public function dependenciesPayload(): array
    {
        $dependencies = $this->input('dependsOn', []);
        if (! is_array($dependencies) || array_is_list($dependencies)) {
            return [];
        }

        return collect($dependencies)
            ->mapWithKeys(fn (mixed $range, mixed $appId): array => [(string) $appId => (string) $range])
            ->all();
    }

    /** @return array{rail:list<array{id:string,label:string}>,sidebar:array<string,list<array{id:string,label:string,permission:string}>>}|null */
    private function navigationPayload(): ?array
    {
        if (! $this->has('navigation')) {
            return null;
        }

        $sidebar = [];
        foreach ((array) $this->input('navigation.sidebar', []) as $railId => $items) {
            $sidebar[(string) $railId] = array_values(array_map(fn (array $item): array => [
                'id' => (string) $item['id'],
                'label' => (string) $item['label'],
                'permission' => (string) $item['permission'],
            ], $items));
        }

        return [
            'rail' => array_values($this->collect('navigation.rail')->map(fn (array $item): array => [
                'id' => (string) $item['id'],
                'label' => (string) $item['label'],
            ])->all()),
            'sidebar' => $sidebar,
        ];
    }

    /**
     * @return array{
     *     entry_points:list<array{code:string,name:string,type:string}>,
     *     permissions:list<array{code:string,name:string,entry_point:string,access:string}>,
     *     privileges:list<array{code:string,name:string,permissions:list<string>}>,
     *     duties:list<array{code:string,name:string,privileges:list<string>}>
     * }
     */
    public function securityPayload(): array
    {
        return [
            'entry_points' => array_values($this->collect('security.entry_points')->map(fn (array $entryPoint): array => [
                'code' => (string) $entryPoint['code'],
                'name' => (string) $entryPoint['name'],
                'type' => (string) $entryPoint['type'],
            ])->all()),
            'permissions' => array_values($this->collect('security.permissions')->map(fn (array $permission): array => [
                'code' => (string) $permission['code'],
                'name' => (string) $permission['name'],
                'entry_point' => (string) $permission['entry_point'],
                'access' => (string) $permission['access'],
            ])->all()),
            'privileges' => array_values($this->collect('security.privileges')->map(fn (array $privilege): array => [
                'code' => (string) $privilege['code'],
                'name' => (string) $privilege['name'],
                'permissions' => array_values(array_map(fn (mixed $code): string => (string) $code, $privilege['permissions'])),
            ])->all()),
            'duties' => array_values($this->collect('security.duties')->map(fn (array $duty): array => [
                'code' => (string) $duty['code'],
                'name' => (string) $duty['name'],
                'privileges' => array_values(array_map(fn (mixed $code): string => (string) $code, $duty['privileges'])),
            ])->all()),
        ];
    }

    /** @return list<array{code:string,name:string,protected_permissions:list<string>,requires_legal_entity:bool,requires_operating_unit:bool,allows_descendants:bool}> */
    public function dataPoliciesPayload(): array
    {
        return array_values($this->collect('security.data_policies')->map(fn (array $policy): array => [
            'code' => (string) $policy['code'],
            'name' => (string) $policy['name'],
            'protected_permissions' => array_values(array_map(fn (mixed $code): string => (string) $code, $policy['protected_permissions'])),
            'requires_legal_entity' => (bool) $policy['requires_legal_entity'],
            'requires_operating_unit' => (bool) $policy['requires_operating_unit'],
            'allows_descendants' => (bool) $policy['allows_descendants'],
        ])->all());
    }

    /** @return list<array{code:string,name:string,default_prefix:?string,allowed_scopes:list<string>}> */
    public function numberSequenceReferencesPayload(): array
    {
        return array_values($this->collect('number_sequences.references')->map(fn (array $reference): array => [
            'code' => (string) $reference['code'],
            'name' => (string) $reference['name'],
            'default_prefix' => isset($reference['default_prefix']) ? (string) $reference['default_prefix'] : null,
            'allowed_scopes' => array_values(array_map(fn (mixed $scope): string => (string) $scope, $reference['allowed_scopes'])),
        ])->all());
    }

    /** @return list<array{code:string,name:string,scope:string,decision_context_schema:array<string,mixed>}> */
    /** @return list<array{code:string,name:string,description:?string,permission:string,parameters:list<string>,builtin_layouts:list<array{key:string,name:string,description:?string,format:string}>}> */
    public function reportsPayload(): array
    {
        return array_values($this->collect('reports')->map(fn (array $report): array => [
            'code' => (string) $report['code'],
            'name' => (string) $report['name'],
            'description' => isset($report['description']) ? (string) $report['description'] : null,
            'permission' => (string) $report['permission'],
            'parameters' => array_values(array_map('strval', $report['parameters'] ?? [])),
            'builtin_layouts' => array_values(array_map(fn (array $layout): array => [
                'key' => (string) $layout['key'],
                'name' => (string) $layout['name'],
                'description' => isset($layout['description']) ? (string) $layout['description'] : null,
                'format' => (string) $layout['format'],
            ], $report['builtin_layouts'] ?? [])),
        ])->all());
    }

    public function workflowTypesPayload(): array
    {
        return array_values($this->collect('workflow_types')->map(fn (array $type): array => [
            'code' => (string) $type['code'],
            'name' => (string) $type['name'],
            'scope' => (string) ($type['scope'] ?? 'legal_entity'),
            'decision_context_schema' => (array) $type['decision_context_schema'],
        ])->all());
    }
}
