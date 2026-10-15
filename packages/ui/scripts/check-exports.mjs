import { access, readdir } from 'node:fs/promises';

const components = (
    await readdir(new URL('../dist/components/', import.meta.url))
).filter((file) => file.endsWith('.js'));

const expected = (await readdir(new URL('../src/components/', import.meta.url)))
    .filter((file) => /\.(ts|tsx)$/.test(file))
    .map((file) => file.replace(/\.(ts|tsx)$/, '.js'));

if (
    components.length !== expected.length ||
    expected.some((file) => !components.includes(file))
) {
    throw new Error(
        `Component exports do not match source files: expected ${expected.length}, found ${components.length}.`,
    );
}

await Promise.all(
    components.map((file) =>
        access(
            new URL(
                `../dist/components/${file.replace(/\.js$/, '.d.ts')}`,
                import.meta.url,
            ),
        ),
    ),
);
console.log(`Verified ${components.length} component exports.`);
