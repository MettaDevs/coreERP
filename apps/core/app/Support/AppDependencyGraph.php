<?php

namespace App\Support;

use App\Models\CoreApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * Satu-satunya tempat untuk membaca graph dependency app.
 *
 * Dependency adalah fakta katalog, bukan entitlement dan bukan status runtime.
 * Kelas ini dipakai saat katalog berubah maupun saat tenant diberi produk agar
 * kedua jalur selalu menutup prerequisite yang sama.
 */
class AppDependencyGraph
{
    /**
     * Memastikan deklarasi baru hanya menunjuk app tersedia, cocok versinya,
     * dan tidak membentuk cycle. Semua app dikunci agar dua registrasi paralel
     * tidak dapat menyisipkan cycle di antara pembacaan dan penyimpanan.
     *
     * @param  array<string, string>  $dependencies
     */
    public function assertRegistrable(string $appId, string $version, array $dependencies): void
    {
        $apps = CoreApp::query()
            ->lockForUpdate()
            ->get(['id', 'version', 'status'])
            ->keyBy('id');

        foreach ($dependencies as $dependencyId => $versionRange) {
            if ($dependencyId === $appId) {
                throw ValidationException::withMessages([
                    'dependsOn.'.$dependencyId => 'App tidak boleh bergantung pada dirinya sendiri.',
                ]);
            }

            /** @var CoreApp|null $dependency */
            $dependency = $apps->get($dependencyId);
            if (! $dependency || $dependency->status !== 'available') {
                throw ValidationException::withMessages([
                    'dependsOn.'.$dependencyId => 'App dependency harus sudah tersedia di katalog.',
                ]);
            }
            if (! $this->matchesVersionRange($dependency->version, $versionRange)) {
                throw ValidationException::withMessages([
                    'dependsOn.'.$dependencyId => "Versi {$dependency->version} tidak memenuhi rentang {$versionRange}.",
                ]);
            }
        }

        $graph = $this->dependencyMap();
        $graph[$appId] = $dependencies;

        foreach (array_keys($dependencies) as $dependencyId) {
            if ($this->reaches($graph, $dependencyId, $appId)) {
                throw ValidationException::withMessages([
                    'dependsOn.'.$dependencyId => 'Dependency ini membentuk siklus antar-app.',
                ]);
            }
        }

        // Memperbarui versi app tidak boleh diam-diam membuat manifest app lain
        // yang sudah terdaftar menjadi tidak kompatibel.
        foreach ($graph as $dependentId => $declaredDependencies) {
            $requiredRange = $declaredDependencies[$appId] ?? null;
            if ($requiredRange !== null && ! $this->matchesVersionRange($version, $requiredRange)) {
                throw ValidationException::withMessages([
                    'version' => "Versi {$version} tidak memenuhi dependency {$dependentId} ({$requiredRange}).",
                ]);
            }
        }
    }

    /**
     * Mengembangkan pilihan produk ke prerequisite transitif dalam urutan
     * pemasangan: dependency selalu datang sebelum app yang memerlukannya.
     *
     * @param  list<string>  $requestedAppIds
     * @return list<string>
     */
    public function resolveAvailable(array $requestedAppIds): array
    {
        $apps = CoreApp::query()->get(['id', 'version', 'status'])->keyBy('id');
        $graph = $this->dependencyMap();
        $resolved = [];
        $visiting = [];
        $visited = [];

        $visit = function (string $appId) use (&$visit, &$resolved, &$visiting, &$visited, $apps, $graph): void {
            if (isset($visited[$appId])) {
                return;
            }
            if (isset($visiting[$appId])) {
                throw new LogicException('Dependency app membentuk siklus.');
            }

            /** @var CoreApp|null $app */
            $app = $apps->get($appId);
            if (! $app || $app->status !== 'available') {
                throw new RuntimeException("App {$appId} tidak tersedia di katalog.");
            }

            $visiting[$appId] = true;
            foreach ($graph[$appId] ?? [] as $dependencyId => $versionRange) {
                /** @var CoreApp|null $dependency */
                $dependency = $apps->get($dependencyId);
                if (! $dependency || $dependency->status !== 'available') {
                    throw new RuntimeException("Dependency {$dependencyId} untuk {$appId} tidak tersedia di katalog.");
                }
                if (! $this->matchesVersionRange($dependency->version, $versionRange)) {
                    throw new RuntimeException("Versi dependency {$dependencyId} tidak memenuhi rentang {$versionRange}.");
                }
                $visit($dependencyId);
            }

            unset($visiting[$appId]);
            $visited[$appId] = true;
            $resolved[] = $appId;
        };

        foreach ($requestedAppIds as $appId) {
            $visit($appId);
        }

        return $resolved;
    }

    /** @return array<string, array<string, string>> */
    private function dependencyMap(): array
    {
        $graph = [];
        foreach (DB::table('app_dependencies')->get(['app_id', 'depends_on_app_id', 'version_range']) as $dependency) {
            $graph[$dependency->app_id][$dependency->depends_on_app_id] = $dependency->version_range;
        }

        return $graph;
    }

    /** @param array<string, array<string, string>> $graph */
    private function reaches(array $graph, string $fromAppId, string $targetAppId): bool
    {
        $seen = [];
        $visit = function (string $appId) use (&$visit, &$seen, $graph, $targetAppId): bool {
            if ($appId === $targetAppId) {
                return true;
            }
            if (isset($seen[$appId])) {
                return false;
            }

            $seen[$appId] = true;
            foreach (array_keys($graph[$appId] ?? []) as $dependencyId) {
                if ($visit($dependencyId)) {
                    return true;
                }
            }

            return false;
        };

        return $visit($fromAppId);
    }

    private function matchesVersionRange(string $version, string $range): bool
    {
        if (! str_starts_with($range, '^')) {
            return version_compare($version, $range, '=');
        }

        $base = substr($range, 1);
        $parts = array_map('intval', explode('.', $base));
        $minimum = implode('.', [...$parts, ...array_fill(0, 3 - count($parts), 0)]);
        [$major, $minor, $patch] = [...$parts, ...array_fill(0, 3 - count($parts), 0)];

        if (version_compare($version, $minimum, '<')) {
            return false;
        }

        $actual = array_map('intval', explode('.', explode('-', $version, 2)[0]));
        if ($major > 0) {
            return $actual[0] === $major;
        }
        if ($minor > 0) {
            return $actual[0] === 0 && $actual[1] === $minor;
        }

        return $actual[0] === 0 && $actual[1] === 0 && $actual[2] === $patch;
    }
}
