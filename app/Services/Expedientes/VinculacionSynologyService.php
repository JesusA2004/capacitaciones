<?php

namespace App\Services\Expedientes;

use App\Models\Colaborador;
use App\Models\ExpedienteHistorico;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\MigracionInicial\EjecutorMigracion;
use App\Services\Expedientes\MigracionInicial\InventarioNasHistorico;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * «Ligar expediente de Synology» (docs/MIGRACION_INICIAL_EXPEDIENTES.md §5.1):
 * para el colaborador que la migración no ligó solo porque su carpeta tiene
 * el nombre mal escrito («Juan Pérez» ↔ «Juam Peres»).
 *
 *  - Lista las carpetas de expediente del NAS (InventarioNasHistorico, el
 *    mismo recorrido de SOLO LECTURA de la migración: whitelist de
 *    sucursales, sin carpetas auxiliares ni las «EMP-… - Nombre» del
 *    sistema; nunca candidatos/) que NO están ligadas a nadie.
 *  - Busca sin acentos ni mayúsculas, por palabra y con parecido fonético /
 *    Levenshtein (SimilitudNombres), y sugiere las más parecidas a la persona.
 *  - Liga con el MISMO camino que la migración
 *    (EjecutorMigracion::vincularCarpetaManual()): en sitio solo registra
 *    hash + metadata; nunca mueve, renombra ni borra nada del NAS.
 *
 * El listado del NAS (lento) se guarda en caché unos minutos; lo que decide
 * si una carpeta está libre se consulta SIEMPRE en BD.
 *
 * @phpstan-type CarpetaNas array{clave: string, ruta: string, carpeta: string, sucursal_carpeta: string, sucursal_id: int, sucursal_nombre: string, archivo_suelto: bool, tokens: list<string>, tokens_pdf: list<list<string>>, tokens_sucursal: list<string>, pdfs: list<array{ruta: string, nombre: string}>}
 */
class VinculacionSynologyService
{
    private const CACHE = 'expedientes:synology:carpetas';

    private const CACHE_SEGUNDOS = 300;

    private const LOCK = 'expedientes:synology:ligar';

    public const UMBRAL_SUGERENCIA = 0.6;

    private const MAX_SUGERENCIAS = 5;

    private const MAX_RESULTADOS = 300;

    private const MAX_ARCHIVOS_VISTA = 200;

    public function __construct(
        private readonly InventarioNasHistorico $inventario,
        private readonly EjecutorMigracion $ejecutor,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * ¿Ya tiene expediente de Synology? Sí si tiene PDF(s) históricos
     * ligados, o si su carpeta de expediente es una carpeta histórica de
     * persona del NAS (no la «EMP-… - Nombre» que crea el sistema al subir
     * documentos).
     */
    public function tieneExpedienteLigado(Colaborador $colaborador): bool
    {
        if ($colaborador->expedientesHistoricos()->exists()) {
            return true;
        }

        $ruta = trim((string) $colaborador->expediente_storage_path);

        return $ruta !== ''
            && str_starts_with($ruta, $this->inventario->raiz().'/')
            && $this->inventario->motivoNoEsPersona(basename($ruta)) === null;
    }

    /**
     * ¿Ya tiene su PROPIO expediente NUEVO en formación? Sí cuando su carpeta
     * de expediente es la que crea el sistema al subir documentos
     * («EMP-… - Nombre» / «SIN-NUMERO-… - Nombre»). Es el caso de un nuevo
     * ingreso que ya subió todo: NO le falta ligar un expediente histórico
     * viejo —ese simplemente no existe— y por eso no debe verse como una
     * deficiencia, solo como la opción (rara) de ligar un histórico si lo hay.
     */
    public function tieneExpedienteNuevo(Colaborador $colaborador): bool
    {
        $ruta = trim((string) $colaborador->expediente_storage_path);

        return $ruta !== ''
            && str_starts_with($ruta, $this->inventario->raiz().'/')
            && $this->inventario->motivoNoEsPersona(basename($ruta)) === 'sistema';
    }

    /**
     * Carpetas libres para $actor, ordenadas: sin texto, primero las
     * sugeridas para la persona (más parecidas) y luego alfabético; con
     * texto, solo las que coinciden, de la más a la menos parecida.
     *
     * @return array{disponible: bool, error: string|null, total: int, carpetas: list<array<string, mixed>>, sugerencias: list<array<string, mixed>>}
     */
    public function buscar(Colaborador $colaborador, User $actor, string $consulta = '', bool $refrescar = false): array
    {
        try {
            $carpetas = $this->disponibles($actor, $refrescar);
        } catch (RuntimeException $e) {
            return ['disponible' => false, 'error' => $e->getMessage(), 'total' => 0, 'carpetas' => [], 'sugerencias' => []];
        }

        $persona = SimilitudNombres::tokens($colaborador->nombreCompleto());
        $tokensConsulta = SimilitudNombres::tokens($consulta);
        $todas = [];
        $filas = [];

        foreach ($carpetas as $carpeta) {
            $similitud = $this->similitudConPersona($persona, $carpeta);
            $fila = $this->fila($carpeta, $similitud, $colaborador);
            $todas[] = $fila;

            if ($tokensConsulta === []) {
                $filas[] = $fila;

                continue;
            }

            $coincidencia = $this->coincidencia($tokensConsulta, $carpeta);

            if ($coincidencia !== null) {
                $filas[] = [...$fila, 'coincidencia' => $coincidencia];
            }
        }

        usort($filas, $tokensConsulta === []
            ? fn (array $a, array $b) => [$b['sugerida'], $b['similitud'], $b['misma_sucursal'], $a['sucursal'], $a['carpeta']] <=> [$a['sugerida'], $a['similitud'], $a['misma_sucursal'], $b['sucursal'], $b['carpeta']]
            : fn (array $a, array $b) => [$b['coincidencia'], $b['similitud'], $a['carpeta']] <=> [$a['coincidencia'], $a['similitud'], $b['carpeta']]);

        $sugerencias = array_values(array_filter($todas, fn (array $f) => $f['sugerida']));
        usort($sugerencias, fn (array $a, array $b) => [$b['similitud'], $b['misma_sucursal']] <=> [$a['similitud'], $a['misma_sucursal']]);

        return [
            'disponible' => true,
            'error' => null,
            'total' => count($filas),
            'carpetas' => array_slice($filas, 0, self::MAX_RESULTADOS),
            'sugerencias' => array_slice($sugerencias, 0, self::MAX_SUGERENCIAS),
        ];
    }

    /**
     * Qué hay en la carpeta antes de confirmar: archivos (nombre, tamaño) y
     * cuáles PDF se registrarán como expediente histórico.
     *
     * @return array<string, mixed>
     */
    public function vistaPrevia(Colaborador $colaborador, User $actor, string $clave): array
    {
        $carpeta = $this->carpetaDisponible($actor, $clave);
        $ruta = $carpeta['ruta'];
        $esArchivo = $carpeta['archivo_suelto'];

        try {
            $disco = $this->inventario->disco();
            $archivos = $esArchivo ? [$ruta] : $disco->files($ruta);
            $subcarpetas = $esArchivo ? [] : array_map('basename', $disco->directories($ruta));
            $registrables = array_flip($this->inventario->pdfsDe($ruta));
            sort($archivos, SORT_NATURAL | SORT_FLAG_CASE);

            $lista = array_map(fn (string $archivo) => [
                'nombre' => basename($archivo),
                'extension' => strtolower(pathinfo($archivo, PATHINFO_EXTENSION)),
                'size' => (int) $disco->size($archivo),
                'se_registra' => isset($registrables[$archivo]),
            ], array_slice($archivos, 0, self::MAX_ARCHIVOS_VISTA));
        } catch (Throwable $e) {
            Log::warning('Ligar expediente de Synology: no se pudo leer la carpeta.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['synology' => 'No se pudo leer el contenido de esa carpeta en Synology. Intenta de nuevo; si persiste, avisa a Sistemas.']);
        }

        $actual = trim((string) $colaborador->expediente_storage_path);

        return [
            'clave' => $clave,
            'carpeta' => $carpeta['carpeta'],
            'sucursal' => $carpeta['sucursal_nombre'],
            'archivo_suelto' => $esArchivo,
            'total_archivos' => count($archivos),
            'pdfs_a_registrar' => count($registrables),
            'archivos' => $lista,
            'subcarpetas' => array_values($subcarpetas),
            // Ya tiene su carpeta del sistema («EMP-… - Nombre»): no se mueve;
            // los documentos nuevos siguen yendo ahí.
            'conserva_carpeta_sistema' => $actual !== '' && ! $esArchivo,
        ];
    }

    /**
     * Liga la carpeta al colaborador. Seguro ante carreras: lock de
     * aplicación (la BD de desarrollo es MyISAM) + transacción con
     * lockForUpdate, y se vuelve a comprobar que la carpeta siga libre y
     * que la persona siga sin expediente.
     *
     * @return array{carpeta: string, pdfs_registrados: int, pdfs_duplicados: int, pdfs_conflicto: int, errores: int}
     */
    public function ligar(Colaborador $colaborador, User $actor, string $clave): array
    {
        $lock = Cache::lock(self::LOCK, 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['synology' => 'Otra persona está ligando un expediente en este momento. Intenta de nuevo en unos segundos.']);
        }

        try {
            $carpeta = $this->carpetaDisponible($actor, $clave);
            $ruta = $carpeta['ruta'];

            if (! $this->existeEnNas($ruta)) {
                Cache::forget(self::CACHE);

                throw ValidationException::withMessages(['synology' => 'Esa carpeta ya no está en Synology (¿se movió o se renombró?). Vuelve a buscarla.']);
            }

            return DB::transaction(function () use ($colaborador, $actor, $carpeta, $ruta): array {
                $persona = Colaborador::withTrashed()->where('id', $colaborador->id)->lockForUpdate()->first();

                if ($persona === null) {
                    throw ValidationException::withMessages(['synology' => 'El colaborador ya no existe.']);
                }

                if ($this->tieneExpedienteLigado($persona)) {
                    throw ValidationException::withMessages(['synology' => 'Este colaborador ya tiene un expediente de Synology ligado.']);
                }

                $pdfs = $this->inventario->pdfsDe($ruta);

                if ($this->carpetaLigada($ruta, $pdfs)) {
                    throw ValidationException::withMessages(['synology' => 'Esa carpeta acaba de ligarse a otro colaborador. Elige otra.']);
                }

                if ($pdfs === [] && (trim((string) $persona->expediente_storage_path) !== '' || $carpeta['archivo_suelto'])) {
                    throw ValidationException::withMessages(['synology' => 'Esa carpeta no tiene ningún PDF que registrar y el colaborador ya tiene su carpeta en el sistema: no hay nada que ligar.']);
                }

                $resultado = $this->ejecutor->vincularCarpetaManual($persona, $ruta, $carpeta['sucursal_carpeta'], $actor);
                $conteo = $resultado['conteo'];
                $registrados = $conteo['pdfs_registrados'] + $conteo['pdfs_copiados'] + $conteo['pdfs_duplicados'];

                if ($pdfs !== [] && $registrados === 0) {
                    throw ValidationException::withMessages(['synology' => 'No se pudo registrar ningún PDF de esa carpeta. Revisa el registro de errores o avisa a Sistemas.']);
                }

                $this->auditoria->registrar('expediente_synology_ligado_manual', $persona, $actor, [
                    'carpeta' => $carpeta['carpeta'],
                    'sucursal_carpeta' => $carpeta['sucursal_carpeta'],
                    'ruta' => $ruta,
                    'conteo' => $conteo,
                    'eventos' => $resultado['eventos'],
                ]);

                return [
                    'carpeta' => $carpeta['carpeta'],
                    'pdfs_registrados' => $conteo['pdfs_registrados'] + $conteo['pdfs_copiados'],
                    'pdfs_duplicados' => $conteo['pdfs_duplicados'],
                    'pdfs_conflicto' => $conteo['pdfs_conflicto'],
                    'errores' => $conteo['errores'],
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @return CarpetaNas
     */
    private function carpetaDisponible(User $actor, string $clave): array
    {
        try {
            $carpetas = $this->disponibles($actor, false);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['synology' => $e->getMessage()]);
        }

        foreach ($carpetas as $carpeta) {
            if ($carpeta['clave'] === $clave) {
                return $carpeta;
            }
        }

        throw ValidationException::withMessages(['synology' => 'Esa carpeta ya no está disponible: puede que otra persona la acabe de ligar. Vuelve a buscar.']);
    }

    /**
     * Carpetas del NAS que nadie tiene ligadas, dentro de las sucursales que
     * $actor puede ver. La parte «ligada o no» siempre sale de BD.
     *
     * @return list<CarpetaNas>
     *
     * @throws RuntimeException si Synology no responde
     */
    private function disponibles(User $actor, bool $refrescar): array
    {
        $ligadas = [];

        foreach (Colaborador::withTrashed()->whereNotNull('expediente_storage_path')->pluck('expediente_storage_path') as $ruta) {
            $ligadas[(string) $ruta] = true;
        }

        foreach (ExpedienteHistorico::query()->whereNotNull('colaborador_id')->get(['path', 'source_path']) as $historico) {
            $ligadas[$historico->path] = true;
            $ligadas[$historico->source_path] = true;
        }

        $sucursales = $this->alcance->tieneAlcanceGlobal($actor)
            ? null
            : array_flip($this->alcance->sucursalesVisiblesIds($actor)->all());

        return array_values(array_filter($this->carpetasNas($refrescar), function (array $carpeta) use ($ligadas, $sucursales): bool {
            if ($sucursales !== null && ! isset($sucursales[$carpeta['sucursal_id']])) {
                return false;
            }

            if (isset($ligadas[$carpeta['ruta']])) {
                return false;
            }

            foreach ($carpeta['pdfs'] as $pdf) {
                if (isset($ligadas[$pdf['ruta']])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Inventario del NAS (caché corta). Una falla NUNCA se guarda en caché.
     *
     * @return list<CarpetaNas>
     *
     * @throws RuntimeException
     */
    private function carpetasNas(bool $refrescar): array
    {
        if ($refrescar) {
            Cache::forget(self::CACHE);
        }

        // remember() no guarda nada si leerNas() lanza: la falla no se cachea.
        return Cache::remember(self::CACHE, self::CACHE_SEGUNDOS, fn (): array => $this->leerNas());
    }

    /**
     * @return list<CarpetaNas>
     *
     * @throws RuntimeException
     */
    private function leerNas(): array
    {
        try {
            $inventario = $this->inventario->inventario();
        } catch (Throwable $e) {
            Log::warning('Ligar expediente de Synology: no se pudo recorrer el NAS.', ['error' => $e->getMessage()]);

            throw new RuntimeException($this->mensajeNoDisponible());
        }

        if (! $inventario['origen_existe']) {
            throw new RuntimeException($this->mensajeNoDisponible());
        }

        $carpetas = [];

        foreach ($inventario['carpetas'] as $c) {
            $ruta = (string) $c['ruta'];

            // Los mini expedientes de candidatos nunca se mezclan con expedientes.
            if (str_starts_with($ruta, 'candidatos/')) {
                continue;
            }

            $pdfs = [];

            foreach (is_array($c['pdfs'] ?? null) ? $c['pdfs'] : [] as $pdf) {
                if (is_array($pdf) && is_string($pdf['ruta'] ?? null)) {
                    $pdfs[] = ['ruta' => $pdf['ruta'], 'nombre' => basename($pdf['ruta'])];
                }
            }

            $nombre = (string) $c['carpeta'];
            $sucursal = (string) $c['sucursal_nombre'];

            $carpetas[] = [
                'clave' => sha1($ruta),
                'ruta' => $ruta,
                'carpeta' => $nombre,
                'sucursal_carpeta' => (string) $c['sucursal_carpeta'],
                'sucursal_id' => (int) $c['sucursal_id'],
                'sucursal_nombre' => $sucursal,
                'archivo_suelto' => strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) === 'pdf',
                // Mismos tokens que la migración (Normalizador::tokensNombre).
                'tokens' => SimilitudNombres::tokens($nombre),
                'tokens_pdf' => array_map(fn (array $p): array => SimilitudNombres::tokens($p['nombre']), $pdfs),
                'tokens_sucursal' => SimilitudNombres::tokens($sucursal),
                'pdfs' => $pdfs,
            ];
        }

        return $carpetas;
    }

    private function mensajeNoDisponible(): string
    {
        return sprintf('No se pudo leer Synology (carpeta «%s»). Revisa que el NAS esté encendido y conectado; si persiste, avisa a Sistemas.', $this->inventario->raiz());
    }

    /**
     * @param  list<string>  $pdfs
     */
    private function carpetaLigada(string $ruta, array $pdfs): bool
    {
        if (Colaborador::withTrashed()->where('expediente_storage_path', $ruta)->lockForUpdate()->exists()) {
            return true;
        }

        $rutas = [$ruta, ...$pdfs];

        return ExpedienteHistorico::query()
            ->whereNotNull('colaborador_id')
            ->where(fn ($q) => $q->whereIn('path', $rutas)->orWhereIn('source_path', $rutas))
            ->lockForUpdate()
            ->exists();
    }

    private function existeEnNas(string $ruta): bool
    {
        try {
            $disco = $this->inventario->disco();

            return $disco->directoryExists($ruta) || $disco->fileExists($ruta);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $persona
     * @param  CarpetaNas  $carpeta
     */
    private function similitudConPersona(array $persona, array $carpeta): float
    {
        $mejor = SimilitudNombres::persona($persona, $carpeta['tokens']);

        foreach ($carpeta['tokens_pdf'] as $tokensPdf) {
            $mejor = max($mejor, SimilitudNombres::persona($persona, $tokensPdf));
        }

        return $mejor;
    }

    /**
     * Coincidencia con lo tecleado: por nombre de la carpeta, de sus PDF o
     * de la sucursal (lo que mejor coincida).
     *
     * @param  list<string>  $consulta
     * @param  CarpetaNas  $carpeta
     */
    private function coincidencia(array $consulta, array $carpeta): ?float
    {
        $mejor = null;

        foreach ([$carpeta['tokens'], ...$carpeta['tokens_pdf']] as $tokens) {
            $valor = SimilitudNombres::busqueda($consulta, [...$tokens, ...$carpeta['tokens_sucursal']]);

            if ($valor !== null && ($mejor === null || $valor > $mejor)) {
                $mejor = $valor;
            }
        }

        return $mejor;
    }

    /**
     * Lo que ve el navegador: nunca la ruta física, solo la clave opaca.
     *
     * @param  CarpetaNas  $carpeta
     * @return array{clave: string, carpeta: string, sucursal: string, archivo_suelto: bool, pdfs: int, similitud: float, sugerida: bool, misma_sucursal: bool, coincidencia: float|null}
     */
    private function fila(array $carpeta, float $similitud, Colaborador $colaborador): array
    {
        return [
            'clave' => $carpeta['clave'],
            'carpeta' => $carpeta['carpeta'],
            'sucursal' => $carpeta['sucursal_nombre'],
            'archivo_suelto' => $carpeta['archivo_suelto'],
            'pdfs' => count($carpeta['pdfs']),
            'similitud' => round($similitud, 2),
            'sugerida' => $similitud >= self::UMBRAL_SUGERENCIA,
            'misma_sucursal' => $carpeta['sucursal_id'] === $colaborador->sucursal_principal_id,
            'coincidencia' => null,
        ];
    }
}
