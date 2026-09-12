<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">

        {{--
            Tidak ada pemilih tema gelap/terang di sini, tidak seperti Core.

            Konsol ini dipakai segelintir operator, dan setiap sakelar tampilan menuntut
            penyimpanan preferensi, satu skrip sebelum render supaya layarnya tidak berkedip,
            dan satu hal lagi yang bisa berbeda antara dua aplikasi. Tema terang saja dulu;
            menambahkannya kelak jauh lebih murah daripada mencabutnya.
        --}}
        <style>html { background-color: hsl(210 30% 96%); }</style>

        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>{{ config('app.name', 'Pusat Admin') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
