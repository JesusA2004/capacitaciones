<?php

namespace App\Services\Pdf;

/**
 * Dónde Puppeteer de ESTA versión instaló su navegador. Nunca adivina el
 * nombre de la carpeta de versión (cambia con cada actualización de
 * Puppeteer): escanea la carpeta de caché real y toma la más reciente.
 *
 * Revisa, en orden: la carpeta fija del proyecto (config/pdf.php,
 * `.puppeteerrc.cjs`), `PUPPETEER_CACHE_DIR` si se definió distinto, y el
 * HOME del proceso actual (para detectar instalaciones viejas que
 * descargaron el navegador antes de fijar la carpeta del proyecto).
 */
class DetectorChromeHeadlessService
{
    /**
     * @return array{ruta: string|null, carpetas_revisadas: list<string>}
     */
    public function detectar(): array
    {
        $carpetas = $this->carpetasCandidatas();

        foreach ($carpetas as $carpeta) {
            foreach (['chrome-headless-shell', 'chrome'] as $producto) {
                $ruta = $this->buscarEjecutable($carpeta, $producto);

                if ($ruta !== null) {
                    return ['ruta' => $ruta, 'carpetas_revisadas' => $carpetas];
                }
            }
        }

        return ['ruta' => null, 'carpetas_revisadas' => $carpetas];
    }

    /**
     * @return list<string>
     */
    private function carpetasCandidatas(): array
    {
        $carpetas = [];
        $fija = config('pdf.browsershot.puppeteer_cache_dir');

        if (is_string($fija) && $fija !== '') {
            $carpetas[] = rtrim($fija, '/\\');
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            $carpetas[] = rtrim($home, '/\\').'/.cache/puppeteer';
        }

        return array_values(array_unique($carpetas));
    }

    private function buscarEjecutable(string $carpetaCache, string $producto): ?string
    {
        $base = $carpetaCache.DIRECTORY_SEPARATOR.$producto;

        if (! is_dir($base)) {
            return null;
        }

        $versiones = glob($base.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        // Carpetas tipo "linux-131.0.6778.204": orden lexicográfico alcanza
        // para traer la build más reciente primero.
        rsort($versiones);

        foreach ($versiones as $versionDir) {
            foreach (glob($versionDir.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $subDir) {
                foreach ([$producto, $producto.'.exe'] as $nombre) {
                    $candidato = $subDir.DIRECTORY_SEPARATOR.$nombre;

                    if (is_file($candidato)) {
                        return $candidato;
                    }
                }
            }
        }

        return null;
    }
}
