<?php

namespace App\Http\Controllers\Provider;

use App\Actions\Provider\RegisterAppCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\AppCatalogRequest;
use App\Models\CoreApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-app-catalog'), 403);

        return response()->json([
            'data' => CoreApp::query()->orderBy('name')->get()->map($this->present(...))->values(),
        ]);
    }

    public function store(AppCatalogRequest $request, RegisterAppCatalog $registrar): JsonResponse
    {
        $app = $registrar->handle(
            $request->appPayload(),
            $request->securityPayload(),
            $request->numberSequenceReferencesPayload(),
            $request->workflowTypesPayload(),
            $request->dataPoliciesPayload(),
            $request->dependenciesPayload(),
            $request->reportsPayload(),
        );

        return response()->json(['data' => $this->present($app)], $app->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array{id:string,name:string,description:?string,version:string,status:string,database_name:string,has_ui:bool,navigation:?array<string,mixed>,repository_url:?string,contract_url:?string,dependsOn:array<string,string>} */
    private function present(CoreApp $app): array
    {
        return [
            'id' => $app->id,
            'name' => $app->name,
            'description' => $app->description,
            'version' => $app->version,
            'status' => $app->status,
            'database_name' => $app->database_name,
            'has_ui' => (bool) $app->has_ui,
            'navigation' => $app->navigation,
            'repository_url' => $app->repository_url,
            'contract_url' => $app->contract_url,
            'dependsOn' => $app->dependencies()
                ->pluck('app_dependencies.version_range', 'apps.id')
                ->map(fn (mixed $versionRange): string => (string) $versionRange)
                ->all(),
        ];
    }
}
