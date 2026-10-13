<?php

namespace App\Services\DocumentosMaestros\Calidad;

use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\Pdf\BrowsershotRenderer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * ¿Las fuentes que piden los documentos las usa DE VERDAD el Chrome que
 * imprime (Browsershot), con el mismo usuario del servidor (www-data /
 * PHP-FPM)? «fc-list dice que existe» no basta:
 *
 *  - fontconfig (`fc-match`): qué archivo elegiría el sistema para esa
 *    familia (si responde otra familia, hay sustitución);
 *  - Chrome: se abre el MISMO navegador configurado para los PDF y se mide
 *    el texto con la familia contra las genéricas (monospace/serif/
 *    sans-serif). Si el ancho no cambia, Chrome está sustituyendo.
 *    `document.fonts.check()` se reporta también, pero NO decide: devuelve
 *    true para familias que no existen.
 *
 * El resultado se muestra tal cual en Sistema → Diagnóstico y en el centro
 * de salud documental.
 *
 * @phpstan-type ResultadoFuente array{familia: string, fontconfig: string|null, archivo: string|null, chrome_disponible: bool|null, chrome_check: bool|null, sustituida: bool}
 */
class DiagnosticoFuentesChromeService
{
    private const TEXTO = 'mmmmmmmmmmlli1WQ@#ÁÉÑ';

    public function __construct(private readonly BrowsershotRenderer $browsershot) {}

    /**
     * Familias que piden los documentos diseñados por PEOPLE (Browsershot).
     *
     * @return list<string>
     */
    public function familiasDocumentos(): array
    {
        return array_keys(DisenoAdministrativoService::FUENTES);
    }

    /**
     * @param  list<string>  $familias
     * @return array{entorno: array{usuario: string|null, home: string|null, sistema: string}, chrome: array{disponible: bool, version: string|null, error: string|null}, fuentes: list<ResultadoFuente>}
     */
    public function diagnosticar(array $familias): array
    {
        $familias = array_values(array_unique(array_filter(array_map('trim', $familias))));
        $chrome = $this->medirEnChrome($familias);
        $fuentes = [];

        foreach ($familias as $familia) {
            [$coincide, $archivo] = $this->fcMatch($familia);
            $enChrome = $chrome['fuentes'][$familia] ?? null;
            $sustituidaFontconfig = $coincide !== null && mb_strtolower($coincide) !== mb_strtolower($familia);

            $fuentes[] = [
                'familia' => $familia,
                'fontconfig' => $coincide,
                'archivo' => $archivo,
                'chrome_disponible' => $enChrome['disponible'] ?? null,
                'chrome_check' => $enChrome['check'] ?? null,
                'sustituida' => ($enChrome !== null && ! $enChrome['disponible']) || ($enChrome === null && $sustituidaFontconfig),
            ];
        }

        return [
            'entorno' => $this->entorno(),
            'chrome' => ['disponible' => $chrome['disponible'], 'version' => $chrome['version'], 'error' => $chrome['error']],
            'fuentes' => $fuentes,
        ];
    }

    /**
     * @param  list<string>  $familias
     * @return array{disponible: bool, version: string|null, error: string|null, fuentes: array<string, array{disponible: bool, check: bool}>}
     */
    private function medirEnChrome(array $familias): array
    {
        $script = sprintf(<<<'JS'
            (() => {
                const familias = %s;
                const lienzo = document.createElement('canvas').getContext('2d');
                const medir = (fuente) => { lienzo.font = `72px ${fuente}`; return lienzo.measureText(%s).width; };
                const genericas = ['monospace', 'serif', 'sans-serif'];
                return JSON.stringify({
                    agente: navigator.userAgent,
                    fuentes: familias.map((f) => {
                        const q = `"${f.replace(/"/g, '')}"`;
                        return { familia: f, check: document.fonts.check(`12px ${q}`), disponible: genericas.some((g) => medir(`${q}, ${g}`) !== medir(g)) };
                    }),
                });
            })()
            JS, json_encode($familias, JSON_UNESCAPED_UNICODE), json_encode(self::TEXTO, JSON_UNESCAPED_UNICODE));

        try {
            $salida = $this->browsershot->navegador('<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>')->evaluate($script);
            $datos = json_decode($salida, true);

            if (! is_array($datos)) {
                return ['disponible' => false, 'version' => null, 'error' => 'Chrome no respondió al diagnóstico.', 'fuentes' => []];
            }

            $fuentes = [];

            foreach ((array) ($datos['fuentes'] ?? []) as $fila) {
                if (is_array($fila) && is_string($fila['familia'] ?? null)) {
                    $fuentes[$fila['familia']] = ['disponible' => (bool) ($fila['disponible'] ?? false), 'check' => (bool) ($fila['check'] ?? false)];
                }
            }

            $version = preg_match('/(?:HeadlessChrome|Chrome)\/([\d.]+)/', (string) ($datos['agente'] ?? ''), $m) === 1 ? $m[1] : null;

            return ['disponible' => true, 'version' => $version, 'error' => null, 'fuentes' => $fuentes];
        } catch (Throwable $e) {
            Log::warning('DiagnosticoFuentesChromeService: no se pudo abrir Chrome.', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return ['disponible' => false, 'version' => null, 'error' => 'No se pudo abrir el Chrome que imprime los documentos.', 'fuentes' => []];
        }
    }

    /**
     * @return array{0: string|null, 1: string|null} familia elegida y archivo
     */
    private function fcMatch(string $familia): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [null, null];
        }

        try {
            $resultado = Process::timeout(10)->run(['fc-match', '-f', '%{family}|%{file}', $familia]);

            if (! $resultado->successful()) {
                return [null, null];
            }

            [$coincide, $archivo] = array_pad(explode('|', trim($resultado->output()), 2), 2, null);
            $coincide = $coincide !== null ? trim(explode(',', $coincide)[0]) : null;

            return [$coincide !== '' ? $coincide : null, $archivo !== null && $archivo !== '' ? $archivo : null];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * @return array{usuario: string|null, home: string|null, sistema: string}
     */
    private function entorno(): array
    {
        $usuario = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
            : (get_current_user() ?: null);
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: null;

        return ['usuario' => is_string($usuario) ? $usuario : null, 'home' => is_string($home) ? $home : null, 'sistema' => sprintf('%s %s', PHP_OS_FAMILY, php_uname('r'))];
    }
}
