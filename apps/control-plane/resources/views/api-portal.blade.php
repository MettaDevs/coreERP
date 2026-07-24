<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} API reference</title>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
</head>
<body>
    <label for="api-specification">API reference</label>
    <select id="api-specification" onchange="window.location.search = 'spec=' + this.value">
        @foreach ($specifications as $specification)
            <option value="{{ $specification['id'] }}" @selected($specification['id'] === $selected['id'])>{{ $specification['name'] }}</option>
        @endforeach
    </select>
    <div id="api-reference"></div>
    <script>
        Scalar.createApiReference('#api-reference', {
            spec: { url: @json($selected['url']) },
            theme: 'purple',
        });
    </script>
</body>
</html>
