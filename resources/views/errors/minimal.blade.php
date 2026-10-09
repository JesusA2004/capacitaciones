{{--
    Respaldo sin JavaScript de las páginas de error (todas las vistas de
    error de Laravel extienden errors::minimal). Solo se usa si la página
    Inertia resources/js/pages/Error.vue no se puede pintar. Nunca muestra
    trazas ni datos del servidor.
--}}
@php
    $codigo = trim($__env->yieldContent('code'));
    $textos = [
        '403' => ['No tienes acceso a esta sección', 'Tu rol no tiene permiso para abrir esta pantalla.'],
        '404' => ['No encontramos esta página', 'La dirección no existe o el registro ya no está disponible.'],
        '419' => ['Tu sesión expiró', 'Por seguridad, la página caducó. Recarga e intenta de nuevo.'],
        '429' => ['Demasiados intentos', 'Espera un momento antes de volver a intentarlo.'],
        '500' => ['Algo salió mal', 'Ocurrió un error inesperado y ya quedó registrado. Intenta de nuevo en unos minutos.'],
        '503' => ['Estamos en mantenimiento', 'MR. LANA PEOPLE está temporalmente fuera de servicio. Vuelve en unos minutos.'],
    ];
    [$titulo, $descripcion] = $textos[$codigo] ?? $textos['500'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }} - MR. LANA PEOPLE</title>
    <style>
        /* Paleta interior de MR. LANA PEOPLE (solo claro, mismos tokens que resources/css/app.css). */
        :root { color-scheme: light; --fondo: #fbf8f2; --tarjeta: #fffdf9; --texto: #303a38; --tenue: #707874; --borde: #e7ded1; --marca: #315b59; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
            background: var(--fondo); color: var(--texto); font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        main { width: 100%; max-width: 28rem; background: var(--tarjeta); border: 1px solid var(--borde); border-radius: 1rem; padding: 2rem; }
        .marca { font-weight: 600; font-size: .875rem; }
        .marca span { color: var(--marca); }
        .codigo { color: var(--marca); font-weight: 600; font-size: .875rem; margin: 1.5rem 0 .25rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { color: var(--tenue); font-size: .875rem; line-height: 1.5; margin: 0; }
        a { display: inline-block; margin-top: 1.5rem; color: var(--texto); font-size: .875rem; font-weight: 500; }
    </style>
</head>
<body>
    <main>
        <div class="marca">MR. LANA <span>PEOPLE</span></div>
        <p class="codigo">Error {{ $codigo !== '' ? $codigo : '500' }}</p>
        <h1>{{ $titulo }}</h1>
        <p>{{ $descripcion }}</p>
        <a href="/">Ir al inicio</a>
    </main>
</body>
</html>
