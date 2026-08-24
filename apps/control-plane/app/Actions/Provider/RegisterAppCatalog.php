<?php

namespace App\Actions\Provider;

use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Models\AppDataPolicy;
use App\Models\CoreApp;
use App\Models\NumberSequenceReference;
use App\Models\Permission;
use App\Models\SecurityDuty;
use App\Models\SecurityPrivilege;
use App\Support\AppDependencyGraph;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mendaftarkan katalog app beserta metadata keamanannya.
 *
 * Empat lapis Dynamics 365 disimpan sebagai empat baris yang berbeda:
 * entry point (yang dilindungi) -> permission (entry point + access level)
 * -> privilege (kumpulan permission untuk satu tugas) -> duty (bagian proses
 * bisnis). Security role dan assignment adalah konfigurasi tenant, bukan
 * bagian manifest app.
 */
class RegisterAppCatalog
{
    public function __construct(private AppDependencyGraph $dependencyGraph) {}

    /**
     * @param  array{id:string,name:string,description:?string,version:string,database_name:string,has_ui:bool,navigation:?array<string,mixed>,repository_url:?string,contract_url:?string,status:string}  $appData
     * @param  array{
     *     entry_points:list<array{code:string,name:string,type:string}>,
     *     permissions:list<array{code:string,name:string,entry_point:string,access:string}>,
     *     privileges:list<array{code:string,name:string,permissions:list<string>}>,
     *     duties:list<array{code:string,name:string,privileges:list<string>}>
     * }  $security
     * @param  list<array{code:string,name:string,default_prefix:?string,allowed_scopes:list<string>}>  $numberSequenceReferences
     * @param  list<array{code:string,name:string,scope:string,decision_context_schema:array<string,mixed>}>  $workflowTypes
     * @param  list<array{code:string,name:string,protected_permissions:list<string>,requires_legal_entity:bool,requires_operating_unit:bool,allows_descendants:bool}>  $dataPolicies
     * @param  array<string, string>  $dependencies
     */
    public function handle(array $appData, array $security, array $numberSequenceReferences = [], array $workflowTypes = [], array $dataPolicies = [], array $dependencies = []): CoreApp
    {
        $app = DB::transaction(function () use ($appData, $security, $numberSequenceReferences, $workflowTypes, $dataPolicies, $dependencies): CoreApp {
            $this->dependencyGraph->assertRegistrable($appData['id'], $appData['version'], $dependencies);
            $app = CoreApp::query()->updateOrCreate(['id' => $appData['id']], $appData);
            $app->dependencies()->sync(
                collect($dependencies)
                    ->map(fn (string $versionRange): array => ['version_range' => $versionRange])
                    ->all(),
            );

            $this->guardRemovedDuties($app->id, array_column($security['duties'], 'code'));

            foreach ($security['entry_points'] as $entryPoint) {
                DB::table('app_entry_points')->updateOrInsert(['code' => $entryPoint['code']], [
                    'app_id' => $app->id,
                    'name' => $entryPoint['name'],
                    'type' => $entryPoint['type'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }

            foreach ($security['permissions'] as $permission) {
                Permission::query()->updateOrCreate(['code' => $permission['code']], [
                    'app_id' => $app->id,
                    'entry_point_code' => $permission['entry_point'],
                    'access_level' => $permission['access'],
                    'name' => $permission['name'],
                ]);
            }

            foreach ($security['privileges'] as $privilegeData) {
                $privilege = SecurityPrivilege::query()->updateOrCreate(['code' => $privilegeData['code']], [
                    'app_id' => $app->id,
                    'name' => $privilegeData['name'],
                ]);
                $privilege->permissions()->sync($privilegeData['permissions']);
            }

            foreach ($security['duties'] as $dutyData) {
                $duty = SecurityDuty::query()->updateOrCreate(['code' => $dutyData['code']], [
                    'app_id' => $app->id,
                    'name' => $dutyData['name'],
                ]);
                $duty->privileges()->sync($dutyData['privileges']);
            }

            // Manifest adalah sumber kebenaran. Metadata lama yang tidak lagi
            // dideklarasikan dihapus, dari lapis terdalam agar foreign key aman.
            $this->pruneLayer('security_duties', $app->id, array_column($security['duties'], 'code'));
            $this->pruneLayer('security_privileges', $app->id, array_column($security['privileges'], 'code'));
            $this->pruneLayer('permissions', $app->id, array_column($security['permissions'], 'code'));
            $this->pruneLayer('app_entry_points', $app->id, array_column($security['entry_points'], 'code'));

            foreach ($numberSequenceReferences as $referenceData) {
                NumberSequenceReference::query()->updateOrCreate(['code' => $referenceData['code']], [
                    'app_id' => $app->id,
                    'name' => $referenceData['name'],
                    'default_prefix' => $referenceData['default_prefix'] ?? null,
                    'allowed_scopes' => $referenceData['allowed_scopes'],
                ]);
            }

            foreach ($workflowTypes as $type) {
                $existing = DB::table('workflow_types')->where('code', $type['code'])->first(['id']);
                DB::table('workflow_types')->updateOrInsert(['code' => $type['code']], [
                    ...($existing ? [] : ['id' => (string) Str::ulid()]),
                    'app_id' => $app->id,
                    'name' => $type['name'],
                    'scope' => $type['scope'],
                    'decision_context_schema' => json_encode($type['decision_context_schema'], JSON_THROW_ON_ERROR),
                    'updated_at' => now(), 'created_at' => now(),
                ]);
            }
            DB::table('workflow_types')->where('app_id', $app->id)->whereNotIn('code', array_column($workflowTypes, 'code'))->delete();

            $this->guardRemovedDataPolicies($app->id, array_column($dataPolicies, 'code'));
            foreach ($dataPolicies as $policy) {
                AppDataPolicy::query()->updateOrCreate(['code' => $policy['code']], [
                    'app_id' => $app->id,
                    'name' => $policy['name'],
                    'protected_permissions' => $policy['protected_permissions'],
                    'requires_legal_entity' => $policy['requires_legal_entity'],
                    'requires_operating_unit' => $policy['requires_operating_unit'],
                    'allows_descendants' => $policy['allows_descendants'],
                ]);
            }
            DB::table('app_data_policies')->where('app_id', $app->id)->whereNotIn('code', array_column($dataPolicies, 'code'))->delete();

            return $app;
        });

        app(EnsureNumberSequenceDrafts::class)->forReadyApp($app->id);

        return $app;
    }

    /**
     * Duty yang masih dipakai security role tenant tidak boleh hilang diam-diam
     * karena manifest baru berhenti mendeklarasikannya.
     *
     * @param  list<string>  $declaredCodes
     */
    private function guardRemovedDuties(string $appId, array $declaredCodes): void
    {
        $inUse = DB::table('security_duties')
            ->join('security_role_duties', 'security_role_duties.duty_code', '=', 'security_duties.code')
            ->where('security_duties.app_id', $appId)
            ->whereNotIn('security_duties.code', $declaredCodes)
            ->distinct()
            ->pluck('security_duties.code')
            ->all();

        if ($inUse !== []) {
            throw ValidationException::withMessages([
                'security.duties' => 'Duty berikut masih dipakai role tenant sehingga tidak boleh dihapus dari manifest: '.implode(', ', $inUse).'.',
            ]);
        }
    }

    /** @param  list<string>  $declaredCodes */
    private function guardRemovedDataPolicies(string $appId, array $declaredCodes): void
    {
        $inUse = DB::table('app_data_policies')
            ->join('role_assignment_data_policy_scopes', 'role_assignment_data_policy_scopes.policy_code', '=', 'app_data_policies.code')
            ->where('app_data_policies.app_id', $appId)
            ->whereNotIn('app_data_policies.code', $declaredCodes)
            ->distinct()
            ->pluck('app_data_policies.code')
            ->all();

        if ($inUse !== []) {
            throw ValidationException::withMessages([
                'security.data_policies' => 'Policy data berikut masih dipakai assignment akses sehingga tidak boleh dihapus dari manifest: '.implode(', ', $inUse).'.',
            ]);
        }
    }

    /** @param  list<string>  $declaredCodes */
    private function pruneLayer(string $table, string $appId, array $declaredCodes): void
    {
        DB::table($table)
            ->where('app_id', $appId)
            ->whereNotIn('code', $declaredCodes)
            ->delete();
    }
}
