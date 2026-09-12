<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">

        {{--
            Tema dibaca dan diterapkan di sini, sebelum badan halaman tergambar.

            Sumbernya `localStorage`, bukan cookie seperti di Core. Core membacanya di PHP karena ia
            merender di sisi server; konsol ini tidak — `inertia({ ssr: false })` — jadi cookie
            hanya akan menambah satu hal yang harus dikecualikan dari enkripsi cookie Laravel
            sebelum PHP dapat membacanya sama sekali.

            Skripnya wajib berdiri di `<head>` dan wajib sinkron. Menaruhnya di berkas masuk berarti
            halaman tergambar terang lebih dulu lalu menggelap sesudah bundel tiba, dan kedipan
            putih itulah yang membuat sakelar tema terasa rusak.
        --}}
        <script>
            (function () {
                try {
                    var choice = localStorage.getItem('theme');
                    var dark = choice === 'dark'
                        || (choice !== 'light'
                            && window.matchMedia('(prefers-color-scheme: dark)').matches);

                    document.documentElement.classList.toggle('dark', dark);
                    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
                } catch (e) {
                    // Peramban yang memblokir penyimpanan situs tetap mendapat tema terang.
                }
            })();
        </script>
        <style>
            html { background-color: hsl(210 30% 96%); }
            html.dark { background-color: oklch(0.145 0 0); }
        </style>

        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>{{ config('app.name', 'Pusat Admin') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
