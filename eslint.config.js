// Konfigurasi ESLint untuk **seluruh repo**, dan karena itu ia hidup di akar.
//
// Sampai 10 September 2026 berkas ini ada di `apps/control-plane/`, dan akibatnya tidak terlihat
// sebagai kegagalan: ESLint 9 menetapkan base path dari letak berkas konfigurasinya, sehingga
// `eslint .` dari folder itu memeriksa **nol** berkas di bawah `modules/` — dan melaporkan sukses.
// Bukan menolak, bukan memperingatkan; hanya diam. Puluhan berkas UI module karena itu tidak pernah
// diperiksa aturan hook React maupun urutan impor sejak module pertama mendarat.
//
// Meletakkannya di akar membuat satu jalan ESLint mencakup Core, module, dan paket antarmuka
// sekaligus. Seluruh pola di bawah karena itu ditulis relatif terhadap akar repo, bukan terhadap
// folder Core.

import js from '@eslint/js';
import stylistic from '@stylistic/eslint-plugin';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    reactHooks.configs.flat['recommended-latest'],
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'],
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        plugins: {
            import: importPlugin,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './apps/control-plane/tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: [
                        'builtin',
                        'external',
                        'internal',
                        'parent',
                        'sibling',
                        'index',
                    ],
                    alphabetize: { order: 'asc', caseInsensitive: true },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        // laravel/chisel menghapus kode di antara sepasang penanda `@chisel-*` saat sebuah
        // fitur dimatikan. Penanda itu berada di tengah blok impor, dan import/order menata
        // ulang impor melewatinya: penandanya berpindah, isinya berubah, dan penghapusan
        // fitur diam-diam membuang baris yang salah. Urutan impor pada berkas ini dijaga
        // tangan sampai penandanya tidak lagi dipakai.
        files: [
            'apps/control-plane/resources/js/pages/auth/confirm-password.tsx',
            'apps/control-plane/resources/js/pages/auth/login.tsx',
            'apps/control-plane/resources/js/pages/settings/profile.tsx',
            'apps/control-plane/resources/js/pages/settings/security.tsx',
            'apps/control-plane/resources/js/pages/welcome.tsx',
            'apps/control-plane/resources/js/types/auth.ts',
        ],
        rules: {
            'import/order': 'off',
        },
    },
    {
        // Skrip pemeliharaan di `scripts/` berjalan di Node, bukan di peramban. Tanpa deklarasi
        // ini setiap `process` dan `console` dilaporkan sebagai variabel tak dikenal — dan itu
        // muncul persis ketika jangkauan ESLint diperluas ke akar repo, karena sebelumnya folder
        // ini tidak pernah diperiksa sama sekali.
        files: ['scripts/**/*.mjs', '*.mjs'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    {
        // Skenario k6 berjalan di runtime k6, bukan browser maupun Node. `__ENV` dan
        // kawan-kawannya disediakan runtime itu, jadi tanpa deklarasi ini setiap
        // pembacaan variabel lingkungan dilaporkan sebagai variabel tak dikenal.
        files: [
            'apps/control-plane/loadtest/k6/**/*.js',
            'modules/*/*/loadtest/k6/**/*.js',
        ],
        languageOptions: {
            globals: {
                __ENV: 'readonly',
                __ITER: 'readonly',
                __VU: 'readonly',
            },
        },
    },
    {
        ignores: [
            '**/vendor/**',
            '**/node_modules/**',
            'apps/control-plane/public/**',
            'apps/control-plane/bootstrap/ssr/**',
            'apps/control-plane/tailwind.config.js',
            'apps/control-plane/vite.config.ts',
            // Dibangkitkan wayfinder; menata ulangnya hanya membuat diff yang tidak dibaca siapa pun.
            'apps/control-plane/resources/js/actions/**',
            'apps/control-plane/resources/js/routes/**',
            'apps/control-plane/resources/js/wayfinder/**',
            // Keluaran build, bukan kode sumber. Tanpa baris ini dua bundel terminifikasi
            // menyumbang lebih dari sebelas ribu temuan dan menenggelamkan yang sungguhan.
            '**/dist/**',
            '**/build/**',
            'docs/.vitepress/cache/**',
            'docs/.vitepress/dist/**',
            '.claude/worktrees/**',
        ],
    },
    prettier,
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
        },
    },
];
