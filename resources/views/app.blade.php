<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
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
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
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
        <meta name="theme-color" content="#274754">
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
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

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
