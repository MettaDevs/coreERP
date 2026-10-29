import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import picomatch from 'picomatch';

const config = readFileSync(
    new URL('../vite.config.ts', import.meta.url),
    'utf8',
);
const ignoredSection = config.match(/ignored:\s*\[([\s\S]*?)\]/)?.[1];
assert.ok(ignoredSection, 'Vite declares explicit ignored paths');
const patterns = [...ignoredSection.matchAll(/'([^']+)'/g)].map(
    (match) => match[1],
);
const ignored = picomatch(patterns);

for (const root of ['D:/Kerja/CoreERP', '/repo']) {
    for (const file of [
        'vendors.tsx',
        'vendor-fact-box.tsx',
        'supplier-import.tsx',
    ]) {
        const source = `${root}/apps/core/resources/js/pages/foundation/vendor/${file}`;
        assert.equal(
            ignored(source),
            false,
            `UI source must remain watched: ${source}`,
        );
    }

    assert.equal(
        ignored(`${root}/apps/core/vendor/composer/autoload_real.php`),
        true,
    );
    assert.equal(
        ignored(`${root}/apps/core/storage/framework/views/compiled.php`),
        true,
    );
    assert.equal(ignored(`${root}/apps/core/public/build/assets/app.js`), true);
}

console.log(
    'Vite watch policy: Vendor UI watched; PHP dependencies and generated files ignored.',
);
