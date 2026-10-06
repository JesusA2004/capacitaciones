<?php

namespace App\Console\Commands;

use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\Pdf\DetectorChromeHeadlessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Revisa si este servidor puede imprimir los documentos administrativos:
 * node/npm/Chrome (config/pdf.php o PATH), el navegador que Puppeteer
 * descargó para esta versión, permisos de ejecución, y una impresión real
 * de prueba con cada motor. Solo lee; no guarda ni descarga nada.
 *
 *   php artisan people:diagnostico-pdf
 *   sudo -u www-data php artisan people:diagnostico-pdf   (como corre PHP-FPM)
 */
class DiagnosticoPdfCommand extends Command
{
    protected $signature = 'people:diagnostico-pdf';

    protected $description = 'Verifica node, npm, Chrome/Chromium y puppeteer, e imprime un PDF de prueba con cada motor (no guarda nada)';

    public function handle(DocumentoAdministrativoService $documentos, DisenoAdministrativoService $disenos, DatosDocumentoAdministrativo $datos, DetectorChromeHeadlessService $detectorChrome): int
    {
        $buscador = new ExecutableFinder;
        $ruta = fn (string $config, array $nombres): ?string => (is_string(config($config)) && config($config) !== '')
            ? (string) config($config)
            : collect($nombres)->map(fn (string $n) => $buscador->find($n))->filter()->first();

        $node = $ruta('pdf.browsershot.node_binary', ['node', 'nodejs']);
        $npm = $ruta('pdf.browsershot.npm_binary', ['npm']);
        $modulos = (string) config('pdf.browsershot.node_modules_path');
        $puppeteer = is_dir(rtrim($modulos, '/\\').DIRECTORY_SEPARATOR.'puppeteer');
        $usuarioActual = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user();

        $chromeSistema = collect(['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'])->map(fn (string $n) => $buscador->find($n))->filter()->first();
        $chromeConfigurado = config('pdf.browsershot.chrome_path');
        $deteccion = $detectorChrome->detectar();
        $chromeResuelto = is_string($chromeConfigurado) && $chromeConfigurado !== '' ? $chromeConfigurado : ($chromeSistema ?? $deteccion['ruta']);

        $this->table(['Componente', 'Ruta / estado'], [
            ['Motor por defecto (PDF_RENDERER)', MotorPdf::porDefecto()->etiqueta()],
            ['Respaldo a DomPDF (PDF_FALLBACK_DOMPDF)', config('pdf.fallback_dompdf') ? 'Permitido' : 'No (el error se reporta)'],
            ['Usuario del proceso', $usuarioActual],
            ['node (BROWSERSHOT_NODE_BINARY)', $node ?? 'NO ENCONTRADO'],
            ['Versión de node', $node !== null ? $this->version($node) : '—'],
            ['npm (BROWSERSHOT_NPM_BINARY)', $npm ?? 'NO ENCONTRADO'],
            ['Versión de npm', $npm !== null ? $this->version($npm) : '—'],
            ['node_modules/puppeteer', $puppeteer ? 'Instalado' : 'FALTA (npm ci)'],
            ['Chrome/Chromium del sistema (PATH)', $chromeSistema ?? 'No hay'],
            ['Caché de Puppeteer revisada', implode(' · ', $deteccion['carpetas_revisadas']) ?: '(ninguna carpeta configurada)'],
            ['chrome-headless-shell de Puppeteer', $deteccion['ruta'] ?? 'NO ENCONTRADO en la caché de Puppeteer'],
            ['Chrome que usará Browsershot (resuelto)', $chromeResuelto ?? 'NINGUNO: Puppeteer intentará el suyo y puede fallar'],
            ['Permiso de ejecución sobre ese Chrome', $chromeResuelto !== null ? (is_executable($chromeResuelto) ? "Sí (como {$usuarioActual})" : "NO (como {$usuarioActual}: revisa permisos/propietario)") : '—'],
        ]);

        if ($deteccion['ruta'] === null && $chromeSistema === null) {
            $cacheDir = (string) config('pdf.browsershot.puppeteer_cache_dir');
            $this->newLine();
            $this->error('No se encontró ningún Chrome/Chromium ni chrome-headless-shell de Puppeteer.');
            $this->line('Opción A (recomendada, docs/DOCUMENTOS_ADMINISTRATIVOS_PDF.md): Chrome del sistema vía apt y BROWSERSHOT_CHROME_PATH en .env:');
            $this->line('');
            $this->line('  sudo apt-get install -y chromium-browser');
            $this->line('  # Agrega en .env: BROWSERSHOT_CHROME_PATH=/usr/bin/chromium-browser');
            $this->line('  # (si "chromium-browser" es un snap, instala Google Chrome .deb en su lugar)');
            $this->line('');
            $this->line('Opción B: dejar que Puppeteer descargue el suyo, con la MISMA ruta de caché que usa esta app (evita que PHP-FPM/www-data busque en un HOME distinto al del deploy):');
            $this->line('');
            $this->line(sprintf('  PUPPETEER_CACHE_DIR=%s npx puppeteer browsers install chrome-headless-shell', $cacheDir));
            $this->line('');
            $this->line('Después vuelve a correr este diagnóstico (ideal: como www-data, igual que PHP-FPM):');
            $this->line('');
            $this->line('  sudo -u www-data php artisan people:diagnostico-pdf');
            $this->newLine();
        }

        $familia = FamiliaAdministrativa::ReciboNomina;
        $ok = true;

        foreach (MotorPdf::cases() as $motor) {
            try {
                $pdf = $documentos->renderizar($familia, $datos->ejemplo($familia), $disenos->porDefecto($familia), $motor)['pdf'];
                $this->info(sprintf('%s: OK, PDF mínimo generado (%s KB)', $motor->etiqueta(), number_format(strlen($pdf) / 1024, 1)));
            } catch (Throwable $e) {
                $ok = $ok && $motor !== MotorPdf::porDefecto();
                $this->error(sprintf('%s: FALLA — %s', $motor->etiqueta(), mb_substr($e->getMessage(), 0, 400)));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function version(string $binario): string
    {
        try {
            $resultado = Process::timeout(10)->run([$binario, '--version']);
        } catch (Throwable) {
            return 'no se pudo consultar';
        }

        return $resultado->successful() ? trim($resultado->output()) : 'no se pudo consultar';
    }
}
