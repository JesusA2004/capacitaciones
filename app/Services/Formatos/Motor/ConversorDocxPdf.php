<?php

namespace App\Services\Formatos\Motor;

use App\Enums\FidelidadConversion;
use App\Services\Formatos\FormatoPreviewService;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Word → PDF. Tres conversores con NIVELES de fidelidad distintos
 * (App\Enums\FidelidadConversion):
 *
 *  1. Microsoft Word por COM (resources/scripts/docx-a-pdf-word.ps1) —
 *     NATIVA. Windows con Office instalado.
 *  2. LibreOffice headless (FORMATOS_LIBREOFFICE_PATH) — ALTA, pero solo
 *     cuenta para un documento definitivo si ESA versión del master pasó el
 *     QA visual con LibreOffice (lo decide MotorDocumentalService).
 *  3. PhpWord + DomPDF (FormatoPreviewService) — APROXIMADA: reconstruye el
 *     documento y no conserva el diseño. Solo vista previa/desarrollo.
 *
 * convertirFiel() jamás cae al tercero: si no hay conversor fiel devuelve
 * null y el motor responde 422 DOCUMENT_CONVERTER_UNAVAILABLE. convertir()
 * (vistas previas y el módulo heredado de formatos) sí puede caer a la
 * aproximada y lo dice en `fidelidad`.
 *
 * Ninguno usa servicios externos ni IA: la conversión es local y
 * determinista.
 */
class ConversorDocxPdf
{
    /** @var array<string, bool> Detección de Word por proceso (no se repite en cada conversión). */
    private static array $wordInstalado = [];

    public function __construct(private readonly FormatoPreviewService $phpWord) {}

    /**
     * true si hay al menos un conversor fiel (Word o LibreOffice) disponible.
     */
    public function fiel(): bool
    {
        return $this->conversoresFieles() !== [];
    }

    /**
     * Conversores fieles disponibles en este servidor, en orden de
     * preferencia según config('formatos_oficiales.conversor').
     *
     * @return list<'word'|'libreoffice'>
     */
    public function conversoresFieles(): array
    {
        $preferido = (string) config('formatos_oficiales.conversor', 'auto');
        $word = $this->wordDisponible();
        $libreOffice = $this->libreOfficeConfigurado();

        return match ($preferido) {
            'word' => $word ? ['word'] : [],
            'libreoffice' => $libreOffice ? ['libreoffice'] : [],
            'phpword' => [],
            // auto: Word primero (nativa) si existe; si no, LibreOffice.
            default => array_values(array_filter([$word ? 'word' : null, $libreOffice ? 'libreoffice' : null])),
        };
    }

    /**
     * Conversión FIEL (documentos definitivos y QA visual). $soloCon limita
     * a un conversor concreto (p. ej. el que validó la versión del master).
     *
     * @param  list<string>|null  $soloCon
     * @return array{pdf: string, fidelidad: string, conversor: string}|null
     */
    public function convertirFiel(string $contenidoDocx, ?array $soloCon = null): ?array
    {
        foreach ($this->conversoresFieles() as $conversor) {
            if ($soloCon !== null && ! in_array($conversor, $soloCon, true)) {
                continue;
            }

            $pdf = $conversor === 'word' ? $this->conWord($contenidoDocx) : $this->conLibreOffice($contenidoDocx);

            if ($pdf !== null && $pdf !== '' && str_starts_with($pdf, '%PDF')) {
                return ['pdf' => $pdf, 'fidelidad' => FidelidadConversion::deConversor($conversor)->value, 'conversor' => $conversor];
            }
        }

        return null;
    }

    /**
     * Conversión para VISTA PREVIA: fiel si se puede; si no, aproximada
     * (marcada como tal). Nunca para un documento definitivo.
     *
     * @return array{pdf: string, fidelidad: string, conversor: string}|null
     */
    public function convertir(string $contenidoDocx): ?array
    {
        $fiel = $this->convertirFiel($contenidoDocx);

        if ($fiel !== null) {
            return $fiel;
        }

        $pdf = $this->phpWord->aPdf($contenidoDocx);

        return $pdf !== null && $pdf !== '' ? ['pdf' => $pdf, 'fidelidad' => FidelidadConversion::Aproximada->value, 'conversor' => 'phpword'] : null;
    }

    /**
     * Solo un entorno declarado sin conversor fiel (FORMATOS_CONVERSOR=phpword:
     * pruebas, desarrollo sin Office) acepta la aproximación en vistas
     * previas de documentos maestros.
     */
    public function permiteAproximada(): bool
    {
        return config('formatos_oficiales.conversor') === 'phpword';
    }

    public function wordDisponible(): bool
    {
        $script = (string) config('formatos_oficiales.word_script');

        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($script)) {
            return false;
        }

        // Permite apagar Word sin desinstalarlo (p. ej. un servidor donde
        // Office no tiene licencia para automatización).
        if (config('formatos_oficiales.word_habilitado', true) === false) {
            return false;
        }

        return self::$wordInstalado[$script] ??= $this->detectarWord();
    }

    private function detectarWord(): bool
    {
        try {
            return Process::timeout(15)->env($this->entornoWindows())->run(['reg', 'query', 'HKCR\\Word.Application\\CLSID'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Variables de entorno mínimas de Windows para los procesos hijos.
     * PHP dentro de Apache (servicio de WAMP como LocalSystem) no hereda
     * `SystemRoot` ni el resto del entorno de usuario: PowerShell no arranca
     * («No se pudo cargar Windows PowerShell administrado. Error:
     * 8009001d»), Word nunca convierte y la vista previa caía a la
     * aproximación de PhpWord (sin fondo, sangrías, tablas ni tipografía).
     * Solo se completan las que falten; las existentes no se tocan.
     *
     * @return array<string, string>
     */
    public function entornoWindows(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $raiz = (string) (getenv('SystemRoot') ?: getenv('windir') ?: 'C:\\Windows');
        $temporal = rtrim(sys_get_temp_dir(), '\\/');
        $perfil = (string) (getenv('USERPROFILE') ?: $raiz.'\\System32\\config\\systemprofile');
        $base = [
            'SystemRoot' => $raiz,
            'windir' => $raiz,
            'SystemDrive' => substr($raiz, 0, 2),
            'ComSpec' => $raiz.'\\System32\\cmd.exe',
            'PATHEXT' => '.COM;.EXE;.BAT;.CMD;.VBS;.VBE;.JS;.JSE;.WSF;.WSH;.MSC',
            'TEMP' => $temporal,
            'TMP' => $temporal,
            'USERPROFILE' => $perfil,
            'APPDATA' => $perfil.'\\AppData\\Roaming',
            'LOCALAPPDATA' => $perfil.'\\AppData\\Local',
            'ProgramData' => substr($raiz, 0, 2).'\\ProgramData',
            'ProgramFiles' => substr($raiz, 0, 2).'\\Program Files',
            'ProgramFiles(x86)' => substr($raiz, 0, 2).'\\Program Files (x86)',
            'PSModulePath' => $raiz.'\\System32\\WindowsPowerShell\\v1.0\\Modules',
        ];
        $entorno = [];

        foreach ($base as $clave => $valor) {
            $actual = getenv($clave);
            $entorno[$clave] = is_string($actual) && $actual !== '' ? $actual : $valor;
        }

        // PowerShell y reg.exe deben encontrarse aunque el PATH de Apache venga recortado.
        $ruta = (string) (getenv('PATH') ?: getenv('Path') ?: '');
        $sistema = implode(';', [$raiz.'\\System32', $raiz, $raiz.'\\System32\\Wbem', $raiz.'\\System32\\WindowsPowerShell\\v1.0']);
        $entorno['PATH'] = $ruta === '' ? $sistema : $ruta.';'.$sistema;

        return $entorno;
    }

    private function libreOfficeConfigurado(): bool
    {
        $ruta = config('formatos_oficiales.libreoffice');

        return is_string($ruta) && $ruta !== '';
    }

    private function conLibreOffice(string $contenidoDocx): ?string
    {
        if (! $this->libreOfficeConfigurado()) {
            return null;
        }

        return $this->enCarpetaTemporal($contenidoDocx, function (string $carpeta, string $origen): ?string {
            // Perfil de usuario aislado por conversión: dos conversiones
            // simultáneas no se bloquean ni heredan configuración.
            $perfil = 'file:///'.ltrim(str_replace('\\', '/', $carpeta.DIRECTORY_SEPARATOR.'perfil'), '/');
            $resultado = Process::timeout($this->timeout())->env($this->entornoWindows())->run([
                (string) config('formatos_oficiales.libreoffice'),
                '-env:UserInstallation='.$perfil,
                '--headless',
                '--norestore',
                '--convert-to',
                'pdf',
                '--outdir',
                $carpeta,
                $origen,
            ]);
            $salida = $carpeta.DIRECTORY_SEPARATOR.'documento.pdf';

            if (! $resultado->successful() || ! is_file($salida)) {
                Log::warning('ConversorDocxPdf: LibreOffice no pudo convertir el documento.', ['salida' => mb_substr($resultado->errorOutput(), 0, 500)]);

                return null;
            }

            return (string) file_get_contents($salida);
        });
    }

    private function conWord(string $contenidoDocx): ?string
    {
        if (! $this->wordDisponible()) {
            return null;
        }

        $script = (string) config('formatos_oficiales.word_script');

        return $this->enCarpetaTemporal($contenidoDocx, function (string $carpeta, string $origen) use ($script): ?string {
            $salida = $carpeta.DIRECTORY_SEPARATOR.'documento.pdf';
            $archivoPid = $carpeta.DIRECTORY_SEPARATOR.'word.pid';

            try {
                $resultado = Process::timeout($this->timeout())->env($this->entornoWindows())->run([
                    'powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script,
                    '-Origen', $origen, '-Destino', $salida, '-ArchivoPid', $archivoPid,
                ]);
            } catch (ProcessTimedOutException $e) {
                $this->terminarWordPropio($archivoPid);
                Log::warning('ConversorDocxPdf: Word excedió el tiempo de conversión; se terminó su proceso.', ['timeout' => $this->timeout()]);

                return null;
            }

            if (! $resultado->successful() || ! is_file($salida)) {
                $this->terminarWordPropio($archivoPid);
                Log::warning('ConversorDocxPdf: Word no pudo convertir el documento.', ['salida' => mb_substr($resultado->errorOutput(), 0, 500)]);

                return null;
            }

            return (string) file_get_contents($salida);
        });
    }

    /**
     * Mata SOLO el WINWORD.EXE que abrió nuestro script (su PID quedó en el
     * archivo); nunca un Word que alguien tenga abierto en el servidor.
     */
    private function terminarWordPropio(string $archivoPid): void
    {
        $pid = is_file($archivoPid) ? (int) trim((string) file_get_contents($archivoPid)) : 0;

        if ($pid > 0) {
            try {
                Process::timeout(15)->run(['taskkill', '/PID', (string) $pid, '/F']);
            } catch (Throwable) {
                // El proceso ya terminó.
            }
        }
    }

    private function timeout(): int
    {
        return max(10, (int) config('formatos_oficiales.timeout_segundos', 180));
    }

    /**
     * @param  callable(string, string): ?string  $convertir
     */
    private function enCarpetaTemporal(string $contenidoDocx, callable $convertir): ?string
    {
        $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'formato-'.Str::uuid();
        @mkdir($carpeta);
        $origen = $carpeta.DIRECTORY_SEPARATOR.'documento.docx';
        file_put_contents($origen, $contenidoDocx);

        try {
            return $convertir($carpeta, $origen);
        } catch (Throwable $e) {
            Log::warning('ConversorDocxPdf: error al convertir.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            $this->borrarCarpeta($carpeta);
        }
    }

    private function borrarCarpeta(string $carpeta): void
    {
        foreach (scandir($carpeta) ?: [] as $nombre) {
            if ($nombre === '.' || $nombre === '..') {
                continue;
            }

            $ruta = $carpeta.DIRECTORY_SEPARATOR.$nombre;
            is_dir($ruta) ? $this->borrarCarpeta($ruta) : @unlink($ruta);
        }

        @rmdir($carpeta);
    }
}
