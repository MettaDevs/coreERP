// Konfigurasi ESLint untuk **seluruh repo**, dan karena itu ia hidup di akar.
//
// Sampai 10 September 2026 berkas ini ada di `apps/core/`, dan akibatnya tidak terlihat
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
                    project: './apps/core/tsconfig.json',
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
        // Sebuah `catch` tidak boleh memulangkan nilai yang juga sah.
        //
        // Aturan yang lebih tua — "setiap catch harus menangani, melempar ulang, atau mencatat" —
        // meloloskan bentuk yang paling sering menipu: `catch { console.log(...); return undefined }`.
        // Ia sudah mencatat, jadi ia lulus, dan pemanggilnya tetap menerima `undefined` yang
        // berbunyi persis sama dengan "memang tidak ada". Yang hilang bukan lognya; yang hilang
        // adalah kemampuan pemanggil membedakan gagal dari kosong.
        //
        // Log tidak menutup jarak itu. Log dibaca orang yang sudah curiga; nilai kembali dibaca
        // kode, saat itu juga, dan kode tidak pernah curiga. Jadi yang dilarang di sini adalah
        // nilainya, bukan kesunyiannya: pulangkan bentuk yang menyatakan "tidak tahu" — lempar
        // ulang, atau nilai yang berbeda dari nilai sah mana pun — lalu biarkan pemanggilnya
        // memutuskan.
        //
        // `return;` telanjang sengaja tidak ikut dilarang: pada fungsi `void` ia tidak
        // menyampaikan fakta apa pun kepada siapa pun, jadi tidak ada yang bisa disalahpahami.
        //
        // `return false` pernah ikut dilarang di sini dan dibuang lagi setelah dijalankan. Dua
        // temuannya benar-benar jujur: `copy()` yang memulangkan false berarti teksnya memang
        // tidak jadi disalin, dan pembanding URL yang memulangkan false berarti alamat yang tidak
        // dapat diurai memang bukan alamat yang sedang dibuka. Pada fungsi yang memang menjawab
        // ya/tidak, `false` adalah jawaban — bukan ketidaktahuan yang menyamar. Aturan yang merah
        // pada dua kasus benar dari dua temuan adalah aturan yang akan dimatikan orang, bersama
        // tiga larangan lain yang menumpang di dalamnya.
        //
        // Sisi PHP dijaga terpisah oleh
        // `apps/core/tests/Feature/Boundary/CatchTidakMemalsukanHasilTest.php`.
        rules: {
            'no-restricted-syntax': [
                'error',
                {
                    selector: 'CatchClause ReturnStatement > Literal[raw="null"]',
                    message:
                        '`catch` yang memulangkan null: pemanggil tidak bisa membedakannya dari "memang tidak ada". Lempar ulang, atau pulangkan bentuk yang menyatakan kegagalan.',
                },
                {
                    selector:
                        'CatchClause ReturnStatement > Identifier[name="undefined"]',
                    message:
                        '`catch` yang memulangkan undefined: pemanggil tidak bisa membedakannya dari nilai yang memang belum diisi. Lempar ulang, atau pulangkan bentuk yang menyatakan kegagalan.',
                },
                {
                    selector:
                        'CatchClause ReturnStatement > ArrayExpression[elements.length=0]',
                    message:
                        '`catch` yang memulangkan larik kosong: pemanggil membacanya sebagai "tidak ada satu pun", padahal yang benar adalah "tidak terbaca". Lempar ulang, atau pulangkan bentuk yang menyatakan kegagalan.',
                },
                {
                    selector:
                        'CatchClause ReturnStatement > ObjectExpression[properties.length=0]',
                    message:
                        '`catch` yang memulangkan objek kosong: pemanggil membacanya sebagai data yang sah dan kebetulan kosong. Lempar ulang, atau pulangkan bentuk yang menyatakan kegagalan.',
                },
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
            'apps/core/resources/js/pages/auth/confirm-password.tsx',
            'apps/core/resources/js/pages/auth/login.tsx',
            'apps/core/resources/js/pages/settings/profile.tsx',
            'apps/core/resources/js/pages/settings/security.tsx',
            'apps/core/resources/js/pages/welcome.tsx',
            'apps/core/resources/js/types/auth.ts',
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
            'apps/core/loadtest/k6/**/*.js',
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
            'apps/core/public/**',
            'apps/core/bootstrap/ssr/**',
            'apps/core/tailwind.config.js',
            'apps/core/vite.config.ts',
            // Dibangkitkan wayfinder; menata ulangnya hanya membuat diff yang tidak dibaca siapa pun.
            'apps/core/resources/js/actions/**',
            'apps/core/resources/js/routes/**',
            'apps/core/resources/js/wayfinder/**',
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
