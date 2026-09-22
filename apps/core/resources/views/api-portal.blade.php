<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $selected['name'] }} · Dokumentasi API {{ config('app.name') }}</title>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
    <style>
        body { margin: 0; }
        .pemilih-spesifikasi {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 0.75rem;
            align-items: center;
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #e5e7eb;
            font: 14px/1.4 system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        .pemilih-spesifikasi select { font: inherit; padding: 0.25rem 0.5rem; max-width: 100%; }
    </style>
</head>
<body>
    @if (count($specifications) > 1)
        <div class="pemilih-spesifikasi">
            <label for="api-specification">Dokumentasi</label>
            <select id="api-specification" onchange="window.location.search = 'spec=' + encodeURIComponent(this.value)">
                @foreach ($specifications as $specification)
                    <option value="{{ $specification['id'] }}" @selected($specification['id'] === $selected['id'])>{{ $specification['name'] }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div id="api-reference"></div>
    <script>
        Scalar.createApiReference('#api-reference', {
            url: @json($selected['url'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES),
            theme: 'purple',
        });
    </script>
</body>
</html>
