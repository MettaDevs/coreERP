<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers;

use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

final class ModulePageController
{
    /**
     * @var array<string, ?string>|null
     */
    private static ?array $menu = null;

    public function __invoke(Request $request, RequestContext $context, string $view): Response
    {
        $menu = self::menu();

        abort_unless(array_key_exists($view, $menu), 404);

        $permission = $menu[$view];

        abort_unless($permission !== null && $context->hasPermission($permission), 403);

        return Inertia::render('procurement::ModulePage', [
            'view' => $view,
            'segments' => self::segments($request),
            'permissions' => $context->permissions(),
            'context' => [
                'legal_entity_id' => $request->attributes->get('coreerp.legal_entity_id'),
                'org_unit_id' => $request->attributes->get('coreerp.org_unit_id'),
                'user_id' => $context->userId(),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function segments(Request $request): array
    {
        $tail = (string) $request->route('tail', '');

        if ($tail === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $tail), static fn (string $segment): bool => $segment !== ''));
    }

    /**
     * @return array<string, ?string>
     */
    private static function menu(): array
    {
        if (self::$menu !== null) {
            return self::$menu;
        }

        /** @var mixed $manifest */
        $manifest = Yaml::parseFile(dirname(__DIR__, 3).'/app.yaml');
        $menu = [];

        $sidebar = is_array($manifest) ? ($manifest['ui']['navigation']['sidebar'] ?? []) : [];

        if (is_array($sidebar)) {
            foreach ($sidebar as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                foreach ($entry as $item) {
                    if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                        continue;
                    }

                    $permission = $item['permission'] ?? null;
                    $menu[$item['id']] = is_string($permission) ? $permission : null;
                }
            }
        }

        return self::$menu = $menu;
    }
}
