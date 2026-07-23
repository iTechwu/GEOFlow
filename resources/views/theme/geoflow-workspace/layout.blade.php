<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('site.partials.seo-head')
    <link rel="stylesheet" href="{{ asset('themes/geoflow-workspace/theme.css') }}">
</head>
<body class="geoflow-entry-body">
    <main class="geoflow-entry-shell">
        @yield('theme_content')
    </main>
</body>
</html>
