<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="color-scheme: light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Solo tema claro (sin modo oscuro): fondo crema desde el primer
             pintado para que nunca haya un destello negro al cargar. --}}
        <meta name="color-scheme" content="light">
        <style>
            html {
                color-scheme: light;
                background-color: #fbf8f2;
            }
        </style>

        {{-- Colores institucionales personalizados en Administración → Configuración → Apariencia (validados como #RRGGBB en el servidor) --}}
        @php($temaInstitucional = app(\App\Services\Configuracion\ConfiguracionSistemaService::class)->cssVariables())
        @if ($temaInstitucional !== '')
            <style id="tema-institucional">{!! $temaInstitucional !!}</style>
        @endif

        {{-- SEO: solo las páginas públicas (login, descarga de la app) se indexan;
             cualquier pantalla con sesión manda noindex (SeoController, sitemap.xml). --}}
        @php($conSesion = auth()->check())
        @php($descripcionSitio = 'MR. LANA PEOPLE: portal de Recursos Humanos de Mr. Lana — expediente digital, solicitudes, vacaciones, recibos de nómina y la app móvil para colaboradores.')
        <meta name="description" content="{{ $descripcionSitio }}">
        <meta name="robots" content="{{ $conSesion ? 'noindex, nofollow' : 'index, follow' }}">
        {{-- Barra del navegador/app instalada: crema dentro (tema claro); el login conserva su tono oscuro. --}}
        <meta name="theme-color" content="{{ $conSesion ? '#fbf8f2' : '#0D3E43' }}">
        <link rel="canonical" href="{{ url()->current() }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="MR. LANA PEOPLE">
        <meta property="og:title" content="MR. LANA PEOPLE — Portal de Recursos Humanos">
        <meta property="og:description" content="{{ $descripcionSitio }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ url('/apple-touch-icon.png') }}">
        <meta property="og:locale" content="es_MX">
        <meta name="twitter:card" content="summary">
        @unless ($conSesion)
            <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'MR. LANA PEOPLE', 'alternateName' => 'Mr. Lana People', 'url' => url('/'), 'inLanguage' => 'es-MX', 'description' => $descripcionSitio, 'publisher' => ['@type' => 'Organization', 'name' => 'Mr. Lana', 'logo' => url('/apple-touch-icon.png')]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endunless

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/branding/apple-touch-icon-pwa.png">

        {{-- App instalable (PWA): Android/Chrome/Edge usan el manifiesto; iPhone/iPad,
             «Compartir › Agregar a inicio» con estas etiquetas. Ver docs/PWA.md. --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="application-name" content="PEOPLE">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="PEOPLE">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>MR. LANA PEOPLE — Portal de Recursos Humanos</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
