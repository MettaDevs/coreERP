<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: hsl(210 30% 96%);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @php
            /*
             * Berkas halaman ikut diminta di sini supaya peramban mengunduhnya bersamaan
             * dengan berkas masuk, bukan sesudahnya. Halaman module tidak ikut, dan itu
             * disengaja.
             *
             * Nama halaman module berbentuk `<id module>::<berkas>` dan tidak menyebut
             * penerbitnya, sedangkan kunci manifest Vite menyebutkan jalur lengkapnya
             * (`../../modules/<penerbit>/<modul>/ui/Pages/<berkas>.tsx`). Menebak jalur itu
             * dari nama halaman berarti menaruh susunan folder module di dalam sebuah
             * template Blade. Lebih dari itu, jalur berawalan `../..` tidak bisa dilayani
             * server pengembangan Vite tanpa awalan `/@fs/`, sehingga baris yang bekerja
             * pada `npm run build` justru gagal saat dikembangkan.
             *
             * Halaman module memang dimuat malas — itu sebabnya tuan rumahnya wajib punya
             * pembatas penangguhan — jadi ia diambil pemilih halaman sesudah berkas masuk
             * berjalan, dengan biaya satu perjalanan jaringan tambahan sekali per halaman.
             */
            $berkasHalaman = str_contains($page['component'], '::')
                ? []
                : ["resources/js/pages/{$page['component']}.tsx"];
        @endphp

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', ...$berkasHalaman])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
