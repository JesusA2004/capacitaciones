<?php

namespace App\Console\Commands;

use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Revisa si este servidor puede imprimir los documentos administrativos:
 * rutas de node/npm/Chrome (config/pdf.php o PATH), paquete puppeteer, y
 * una impresión real de prueba con cada motor. Solo lee; no guarda nada.
 *
 *   php artisan people:diagnostico-pdf
 */
class DiagnosticoPdfCommand extends Command
{
    protected $signature = 'people:diagnostico-pdf';

    protected $description = 'Verifica node, npm, Chrome/Chromium y puppeteer, e imprime un PDF de prueba con cada motor (no guarda nada)';

    public function handle(DocumentoAdministrativoService $documentos, DisenoAdministrativoService $disenos, DatosDocumentoAdministrativo $datos): int
    {
        $buscador = new ExecutableFinder;
        $ruta = fn (string $config, array $nombres): ?string => (is_string(config($config)) && config($config) !== '')
            ? (string) config($config)
            : collect($nombres)->map(fn (string $n) => $buscador->find($n))->filter()->first();

        $node = $ruta('pdf.browsershot.node_binary', ['node', 'nodejs']);
        $npm = $ruta('pdf.browsershot.npm_binary', ['npm']);
        $chrome = $ruta('pdf.browsershot.chrome_path', ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser']);
        $modulos = (string) config('pdf.browsershot.node_modules_path');
        $puppeteer = is_dir(rtrim($modulos, '/\\').DIRECTORY_SEPARATOR.'puppeteer');

        $this->table(['Componente', 'Ruta / estado'], [
            ['Motor por defecto (PDF_RENDERER)', MotorPdf::porDefecto()->etiqueta()],
            ['Respaldo a DomPDF (PDF_FALLBACK_DOMPDF)', config('pdf.fallback_dompdf') ? 'Permitido' : 'No (el error se reporta)'],
            ['node (BROWSERSHOT_NODE_BINARY)', $node ?? 'NO ENCONTRADO'],
            ['npm (BROWSERSHOT_NPM_BINARY)', $npm ?? 'NO ENCONTRADO'],
            ['Chrome/Chromium (BROWSERSHOT_CHROME_PATH)', $chrome ?? 'NO ENCONTRADO (puppeteer usará el suyo si lo descargó)'],
            ['node_modules/puppeteer', $puppeteer ? 'Instalado' : 'FALTA (npm ci)'],
            ['Usuario del proceso', function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user()],
        ]);

        $familia = FamiliaAdministrativa::ReciboNomina;
        $ok = true;

        foreach (MotorPdf::cases() as $motor) {
            try {
                $pdf = $documentos->renderizar($familia, $datos->ejemplo($familia), $disenos->porDefecto($familia), $motor)['pdf'];
                $this->info(sprintf('%s: OK (%s KB)', $motor->etiqueta(), number_format(strlen($pdf) / 1024, 1)));
            } catch (Throwable $e) {
                $ok = $ok && $motor !== MotorPdf::porDefecto();
                $this->error(sprintf('%s: FALLA — %s', $motor->etiqueta(), mb_substr($e->getMessage(), 0, 400)));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
