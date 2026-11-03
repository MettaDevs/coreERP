"""Cocokkan source PHP runtime dengan archive Git yang dipakai sebagai baseline."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import tarfile

LAB = Path(__file__).resolve().parents[1]
PRIVATE = LAB / "private"


def run() -> None:
    hashes = {}
    with tarfile.open(PRIVATE / "source.tar") as archive:
        for member in archive:
            if member.isfile() and member.name.endswith((".php", ".yaml", ".json")) and member.name.startswith(("apps/core/app/", "apps/core/routes/", "apps/core/config/", "apps/core/bootstrap/", "modules/")):
                hashes[member.name] = hashlib.sha256(archive.extractfile(member).read()).hexdigest()
        lock = json.load(archive.extractfile("apps/core/composer.lock"))
    expected = {
        "hashes": hashes,
        "packages": {package["name"]: package["version"] for package in lock["packages"] + lock["packages-dev"]},
    }
    (PRIVATE / "source-hashes.json").write_text(json.dumps(expected), encoding="utf-8")
    script = r'''
    $expected = json_decode(file_get_contents('/comparison/private/source-hashes.json'), true, flags: JSON_THROW_ON_ERROR);
    $mismatches = [];
    foreach ($expected['hashes'] as $path => $hash) {
        if (!is_file('/repo/'.$path) || hash_file('sha256', '/repo/'.$path) !== $hash) {
            $mismatches[] = $path;
        }
    }
    $extra = [];
    foreach (['apps/core/app', 'apps/core/routes', 'apps/core/config', 'modules'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/repo/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen('/repo/'));
            if ($file->isFile() && preg_match('/\.(php|yaml|json)$/', $path) && !isset($expected['hashes'][$path])) {
                $extra[] = $path;
            }
        }
    }
    $installed = json_decode(file_get_contents('/repo/apps/core/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    $dependencies = [];
    foreach ($installed['packages'] ?? $installed as $package) {
        $version = $expected['packages'][$package['name']] ?? null;
        if ($version === null || $version !== $package['version']) {
            $dependencies[] = ['name' => $package['name'], 'expected' => $version, 'actual' => $package['version']];
        }
    }
    echo json_encode(['files' => count($expected['hashes']), 'mismatches' => $mismatches, 'extra_files' => $extra, 'dependency_mismatches' => $dependencies], JSON_THROW_ON_ERROR);
    '''
    result = subprocess.run(["docker", "exec", "coreerp-stack-comparison-tools-1", "php", "-r", script], check=True, capture_output=True, text=True)
    evidence = json.loads(result.stdout)
    (PRIVATE / "source-attestation.json").write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(evidence))
    if evidence["mismatches"] or evidence["extra_files"] or evidence["dependency_mismatches"]:
        raise SystemExit(1)


if __name__ == "__main__":
    run()
