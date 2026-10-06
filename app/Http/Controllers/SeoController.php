<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * SEO del sitio público (people.mr-lana.com): robots.txt y sitemap.xml con
 * la URL real (APP_URL). Solo se indexan las páginas públicas — inicio de
 * sesión y descarga de la app —; todo lo que requiere sesión queda fuera
 * (además, esas páginas mandan `noindex`, ver resources/views/app.blade.php).
 */
class SeoController extends Controller
{
    /** Páginas públicas indexables: ruta => [prioridad, frecuencia]. */
    private const PUBLICAS = [
        'login' => ['1.0', 'monthly'],
        'app.index' => ['0.8', 'weekly'],
    ];

    public function robots(): Response
    {
        $lineas = [
            'User-agent: *',
            'Allow: /$',
            'Allow: /login',
            'Allow: /app',
            'Disallow: /rh/',
            'Disallow: /administracion/',
            'Disallow: /api/',
            'Disallow: /dashboard',
            'Disallow: /incorporacion/',
            'Disallow: /alta/',
            'Disallow: /constancias/',
            '',
            'Sitemap: '.url('sitemap.xml'),
        ];

        return response(implode("\n", $lineas)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function sitemap(): Response
    {
        $urls = '';

        foreach (self::PUBLICAS as $ruta => [$prioridad, $frecuencia]) {
            $urls .= sprintf(
                "  <url>\n    <loc>%s</loc>\n    <changefreq>%s</changefreq>\n    <priority>%s</priority>\n  </url>\n",
                e(route($ruta)),
                $frecuencia,
                $prioridad,
            );
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n{$urls}</urlset>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }
}
