import { access, readdir } from 'node:fs/promises';

const components = (await readdir(new URL('../dist/components/', import.meta.url)))
    .filter((file) => file.endsWith('.js'));

if (components.length !== 65) {
    throw new Error(`Expected 65 component exports, found ${components.length}.`);
}

await Promise.all(components.map((file) =>
    access(new URL(`../dist/components/${file.replace(/\.js$/, '.d.ts')}`, import.meta.url)),
));
console.log(`Verified ${components.length} component exports.`);
