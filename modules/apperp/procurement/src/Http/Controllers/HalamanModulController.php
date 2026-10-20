<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers;

use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

final class HalamanModulController
{
    /**
     * @var array<string, ?string>|null
     */
    private static ?array $menu = null;

    public function __invoke(Request $request, RequestContext $akses, string $view): Response
    {
        $menu = self::menu();

        abort_unless(array_key_exists($view, $menu), 404);

        $izin = $menu[$view];

        abort_unless($izin !== null && $akses->hasPermission($izin), 403);

        return Inertia::render('procurement::Modul', [
            'view' => $view,
            'segments' => self::ruas($request, $view),
            'permissions' => $akses->permissions(),
            'konteks' => [
                'legal_entity_id' => $request->attributes->get('coreerp.legal_entity_id'),
                'org_unit_id' => $request->attributes->get('coreerp.org_unit_id'),
                'user_id' => $akses->userId(),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function ruas(Request $request, string $view): array
    {
        $sisa = (string) $request->route('sisa', '');

        if ($sisa === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $sisa), static fn (string $ruas): bool => $ruas !== ''));
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
            foreach ($sidebar as $entri) {
                if (! is_array($entri)) {
                    continue;
                }

                foreach ($entri as $item) {
                    if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                        continue;
                    }

                    $izin = $item['permission'] ?? null;
                    $menu[$item['id']] = is_string($izin) ? $izin : null;
                }
            }
        }

        return self::$menu = $menu;
    }
}
