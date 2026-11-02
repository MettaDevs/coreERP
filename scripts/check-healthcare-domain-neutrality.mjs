import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const defaultRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);

if (args.length !== 0 && (args.length !== 2 || args[0] !== '--root')) {
    console.error(
        'Usage: node scripts/check-healthcare-domain-neutrality.mjs [--root PATH]',
    );
    process.exit(2);
}

const root = args.length === 2 ? resolve(args[1]) : defaultRoot;
const relativeFolders = [
    'docs/todo/control-plane-database',
    'docs/todo/metta-platform-portal',
];
const sectorName =
    /\bdinkes\b|\bdinas[ _-]+kesehatan\b|\b(?:dinkes|dinas)_[a-z0-9_]+\b|\b(?:Dinkes|DinasKesehatan)(?:Id|ID|Organization|Tenant|Unit|Policy)\b/i;
const oldLabels = /DINKES-DEFAULT|\bDK-\d+/i;
const failures = [];
let checked = 0;

for (const folder of relativeFolders) {
    for (const entry of readdirSync(join(root, folder), {
        withFileTypes: true,
    })) {
        if (!entry.isFile() || !entry.name.endsWith('.md')) {
            continue;
        }

        const path = join(root, folder, entry.name);
        const label = relative(root, path).replaceAll('\\', '/');

        if (/dinkes|dinas[-_ ]?kesehatan/i.test(entry.name)) {
            failures.push(
                `${label}: use a sector-neutral document/profile name`,
            );
        }

        if (entry.name === 'DATABASE-PATTERN-EVIDENCE.md') {
            continue;
        }

        checked++;
        let example = false;
        let fence = null;

        for (const [index, line] of readFileSync(path, 'utf8')
            .split(/\r?\n/)
            .entries()) {
            const marker = line.match(/^\s*(`{3,}|~{3,})/);

            if (marker && !fence) {
                fence = marker[1][0];
            } else if (marker && marker[1][0] === fence) {
                fence = null;
            }

            if (!fence && /^## /.test(line)) {
                example = /^## Contoh\b/i.test(line);
            }

            if (oldLabels.test(line) || (!example && sectorName.test(line))) {
                failures.push(
                    `${label}:${index + 1}: use organization, facility, service_group, catalog or policy terms; keep sector-specific examples under '## Contoh'`,
                );
            }
        }
    }
}

const sidebar = 'docs/.vitepress/config.ts';

for (const [index, line] of readFileSync(join(root, sidebar), 'utf8')
    .split(/\r?\n/)
    .entries()) {
    if (
        oldLabels.test(line) ||
        (/control-plane-database|metta-platform-portal/.test(line) &&
            sectorName.test(line))
    ) {
        failures.push(
            `${sidebar}:${index + 1}: use sector-neutral healthcare navigation and links`,
        );
    }
}

if (failures.length) {
    failures.forEach((failure) => console.error(failure));
    process.exitCode = 1;
} else {
    console.log(
        `PASS: sector-neutral healthcare domain wording in ${checked} plan documents and navigation`,
    );
}
