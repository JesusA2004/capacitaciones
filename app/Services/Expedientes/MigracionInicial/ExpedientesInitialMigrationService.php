<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Models\Colaborador;
use App\Models\ExpedienteHistorico;
use App\Models\MigracionExpedientes;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Migración inicial de colaboradores y expedientes históricos
 * (docs/MIGRACION_INICIAL_EXPEDIENTES.md). Punto único para la pantalla de
 * RH (/rh/expedientes → «Migración inicial») y para Artisan
 * (`expedientes:importar-inicial`): la lógica no se duplica.
 *
 *   analizar()  → lee el Excel y arma el plan. NO toca BD de colaboradores
 *                 ni el NAS (solo guarda la corrida para revisarla).
 *   decidir()   → guarda elecciones manuales (carpeta correcta, qué hacer
 *                 con expedientes que no vienen en el Excel).
 *   aplicar()   → ejecuta con lock (no dos a la vez) y manifiesto.
 */
class ExpedientesInitialMigrationService
{
    private const LOCK = 'expedientes:migracion-inicial';

    public function __construct(
        private readonly LectorExcelMigracion $lector,
        private readonly PlanificadorMigracion $planificador,
        private readonly EjecutorMigracion $ejecutor,
        private readonly InventarioNasHistorico $inventario,
        private readonly DocumentoStorageService $storage,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Dry run. El Excel se guarda en storage local (no en el NAS) para que
     * la ejecución use exactamente el mismo archivo analizado.
     */
    public function analizar(string $rutaExcel, string $nombreOriginal, ?User $actor): MigracionExpedientes
    {
        $hash = hash_file('sha256', $rutaExcel) ?: '';
        $copia = sprintf('migraciones-expedientes/%s.xlsx', $hash);

        if (! Storage::disk('local')->exists($copia)) {
            Storage::disk('local')->put($copia, (string) file_get_contents($rutaExcel));
        }

        $plan = $this->planificador->planificar($this->lector->leer($rutaExcel));

        $migracion = MigracionExpedientes::query()->create([
            'user_id' => $actor?->id,
            'archivo_nombre' => $nombreOriginal,
            'archivo_path' => $copia,
            'archivo_hash' => $hash,
            'estado' => 'analizado',
            'totales' => $plan['totales'],
            'plan' => json_encode($plan, JSON_UNESCAPED_UNICODE),
            'decisiones' => ['filas' => [], 'carpetas' => []],
        ]);

        $this->auditoria->registrar('migracion_expedientes_analizada', $migracion, $actor, ['archivo' => $nombreOriginal, 'hash' => $hash, 'totales' => $plan['totales']]);

        return $migracion;
    }

    /**
     * Decisiones manuales sobre una corrida analizada:
     *  - filas[fila] = ['ruta' => carpeta elegida | null (ninguna)]
     *  - carpetas[ruta] = ['accion' => historico|pendiente|vincular|omitir, 'colaborador_id' => ?int]
     * Solo se aceptan rutas que el propio análisis encontró.
     *
     * @param  array{filas?: array<int|string, array{ruta?: string|null}>, carpetas?: array<string, array{accion: string, colaborador_id?: int|null}>}  $decisiones
     */
    public function decidir(MigracionExpedientes $migracion, array $decisiones): MigracionExpedientes
    {
        $this->exigirAnalizada($migracion);
        $plan = $migracion->planArray();
        $rutas = array_column((array) ($plan['carpetas_sin_persona'] ?? []), 'ruta');

        foreach ((array) ($plan['filas'] ?? []) as $f) {
            $rutas = [...$rutas, ...array_column((array) ($f['nas']['candidatos'] ?? []), 'ruta')];
        }

        $rutasValidas = array_flip(array_map('strval', $rutas));
        $actual = $migracion->decisiones ?? ['filas' => [], 'carpetas' => []];

        foreach ($decisiones['filas'] ?? [] as $fila => $d) {
            $ruta = $d['ruta'] ?? null;

            if ($ruta !== null && $ruta !== '' && ! isset($rutasValidas[$ruta])) {
                throw ValidationException::withMessages(['decisiones' => 'La carpeta elegida no está entre las candidatas del análisis.']);
            }

            $actual['filas'][(string) $fila] = ['ruta' => $ruta ?: null];
        }

        foreach ($decisiones['carpetas'] ?? [] as $ruta => $d) {
            if (! in_array($d['accion'], ['historico', 'pendiente', 'vincular', 'omitir'], true)) {
                throw ValidationException::withMessages(['decisiones' => 'Acción de carpeta no válida.']);
            }

            if ($d['accion'] === 'vincular' && Colaborador::withTrashed()->whereKey((int) ($d['colaborador_id'] ?? 0))->doesntExist()) {
                throw ValidationException::withMessages(['decisiones' => 'Elige el colaborador al que se vincula el expediente.']);
            }

            $actual['carpetas'][(string) $ruta] = ['accion' => $d['accion'], 'colaborador_id' => isset($d['colaborador_id']) ? (int) $d['colaborador_id'] : null];
        }

        $migracion->update(['decisiones' => $actual]);

        return $migracion;
    }

    /**
     * Ejecución real. `mover` solo si se pide explícitamente (y solo aplica
     * al origen legacy; en sitio nunca se mueve nada).
     *
     * @return array<string, int>
     */
    public function aplicar(MigracionExpedientes $migracion, User $actor, string $modo = EjecutorMigracion::MODO_COPIAR): array
    {
        $this->exigirAnalizada($migracion);

        if (! in_array($modo, [EjecutorMigracion::MODO_COPIAR, EjecutorMigracion::MODO_MOVER], true)) {
            throw ValidationException::withMessages(['modo' => 'Modo no válido.']);
        }

        $lock = Cache::lock(self::LOCK, 7200);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['migracion' => 'Ya hay una migración ejecutándose. Espera a que termine.']);
        }

        try {
            @set_time_limit(0);
            $resultado = $this->ejecutor->ejecutar($migracion, $actor, $modo);
            $this->auditoria->registrar('migracion_expedientes_aplicada', $migracion, $actor, ['modo' => $modo, 'resultado' => $resultado]);

            return $resultado;
        } catch (\Throwable $e) {
            $migracion->update(['estado' => 'fallido', 'error' => $e->getMessage(), 'terminada_en' => now()]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function exigirAnalizada(MigracionExpedientes $migracion): void
    {
        if ($migracion->estado === 'aplicando') {
            throw ValidationException::withMessages(['migracion' => 'Esta migración se está ejecutando.']);
        }
    }

    /**
     * Reporte CSV del plan + resultado (para revisar fuera del sistema).
     */
    public function reporte(MigracionExpedientes $migracion): StreamedResponse
    {
        $plan = $migracion->planArray();

        return response()->streamDownload(function () use ($plan, $migracion): void {
            $salida = fopen('php://output', 'w');

            if ($salida === false) {
                return;
            }

            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Fila', 'Clave', 'Nombre', 'Empresa', 'Sucursal', 'Puesto', 'CURP', 'Operación', 'Colaborador ID', 'Carpeta NAS', 'PDFs', 'Tipo de match', 'Score', 'Conflictos', 'Advertencias']);

            foreach ($plan['filas'] ?? [] as $f) {
                fputcsv($salida, [
                    $f['fila'], $f['clave'], $f['nombre_completo'], $f['empresa_nombre'], $f['sucursal_nombre'] ?? $f['sucursal_excel'], $f['puesto_nombre'] ?? $f['puesto_excel'],
                    $f['curp'], $f['operacion'], $f['colaborador_id'], $f['nas']['carpeta'] ?? '', count($f['nas']['pdfs'] ?? []), $f['nas']['tipo'], $f['nas']['score'],
                    implode(' | ', $f['motivos']), implode(' | ', $f['advertencias']),
                ]);
            }

            fputcsv($salida, []);
            fputcsv($salida, ['Expedientes en NAS que no vienen en el Excel']);
            fputcsv($salida, ['Sucursal', 'Carpeta', 'Nombre detectado', 'PDFs', 'Acción propuesta']);

            foreach ($plan['carpetas_sin_persona'] ?? [] as $c) {
                fputcsv($salida, [$c['sucursal_carpeta'], $c['carpeta'], $c['nombre_detectado'], count($c['pdfs']), $c['accion']]);
            }

            if ($migracion->resultado !== null) {
                fputcsv($salida, []);
                fputcsv($salida, ['Resultado de la ejecución']);

                foreach ($migracion->resultado as $clave => $valor) {
                    fputcsv($salida, [$clave, $valor]);
                }
            }

            fclose($salida);
        }, sprintf('migracion-expedientes-%d.csv', $migracion->id), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Vincula un expediente histórico pendiente a un colaborador (p. ej. en
     * un reingreso). En sitio solo cambia la relación; si vino del origen
     * legacy a «Pendientes de vincular», se copia (verificado) a la carpeta
     * Histórico del colaborador y solo entonces se retira la copia pendiente.
     */
    public function vincular(ExpedienteHistorico $historico, Colaborador $colaborador, User $actor): ExpedienteHistorico
    {
        if ($historico->colaborador_id !== null && $historico->colaborador_id !== $colaborador->id) {
            throw ValidationException::withMessages(['historico' => 'Este expediente histórico ya pertenece a otro colaborador.']);
        }

        $pendientes = (string) config('expedientes.migracion_inicial.carpeta_pendientes', 'Pendientes de vincular');

        if (str_contains($historico->path, '/'.$this->storage->sanitizarSegmento($pendientes).'/')) {
            $indice = ExpedienteHistorico::query()->where('colaborador_id', $colaborador->id)->count() + 1;
            $destino = $this->storage->rutaHistorico($colaborador->loadMissing('sucursalPrincipal.empresa'), $indice);
            $copia = $this->storage->copiarDesdeDisco($this->storage->disco(), $historico->path, $destino);

            if ($copia['estado'] === 'conflicto') {
                throw new RuntimeException('Ya existe otro archivo en el destino; revisa el expediente antes de vincular.');
            }

            $anterior = $historico->path;
            $historico->update(['path' => $destino, 'stored_name' => basename($destino)]);
            $this->storage->eliminar($anterior);
        }

        $historico->update(['colaborador_id' => $colaborador->id, 'estado' => ExpedienteHistorico::VINCULADO, 'vinculado_por' => $actor->id, 'vinculado_en' => now()]);
        $this->auditoria->registrar('expediente_historico_vinculado', $historico, $actor, ['colaborador_id' => $colaborador->id]);

        return $historico;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historicosDe(Colaborador $colaborador): array
    {
        return array_values($colaborador->expedientesHistoricos()->orderBy('id')->get()->map(fn (ExpedienteHistorico $h) => [
            'id' => $h->id,
            'nombre' => $h->stored_name,
            'original' => $h->original_name,
            'size' => $h->size,
            'migrado_en' => $h->migrated_at?->toIso8601String(),
            'url' => route('rh.expedientes.historico.ver', $h),
            'descargar_url' => route('rh.expedientes.historico.ver', [$h, 'descargar' => 1]),
        ])->all());
    }

    public function respuesta(ExpedienteHistorico $historico, bool $descargar): StreamedResponse
    {
        $disco = Storage::disk($historico->disk);
        abort_unless($disco->exists($historico->path), 404, 'El archivo histórico no está en el almacenamiento.');

        return $disco->response($historico->path, $historico->stored_name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s"', $descargar ? 'attachment' : 'inline', addslashes($historico->stored_name)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Lista de cuentas creadas (persona, sucursal, puesto, usuario,
     * contraseña temporal). Se guarda cifrada; solo se descifra aquí.
     *
     * @return list<array<string, mixed>>
     */
    public function credenciales(MigracionExpedientes $migracion): array
    {
        if ($migracion->credenciales_path === null || ! Storage::disk('local')->exists($migracion->credenciales_path)) {
            return [];
        }

        $datos = json_decode(Crypt::decryptString((string) Storage::disk('local')->get($migracion->credenciales_path)), true);

        return is_array($datos) ? array_values(array_filter($datos, 'is_array')) : [];
    }

    public function credencialesCsv(MigracionExpedientes $migracion): StreamedResponse
    {
        $lista = $this->credenciales($migracion);

        return response()->streamDownload(function () use ($lista): void {
            $salida = fopen('php://output', 'w');

            if ($salida === false) {
                return;
            }

            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Persona', 'Número de empleado', 'Sucursal', 'Puesto', 'Usuario', 'Contraseña temporal', 'Estado']);

            foreach ($lista as $fila) {
                fputcsv($salida, [$fila['persona'] ?? '', $fila['numero_empleado'] ?? '', $fila['sucursal'] ?? '', $fila['puesto'] ?? '', $fila['usuario'] ?? '', $fila['contrasena'] ?? '', $fila['estado'] ?? '']);
            }

            fclose($salida);
        }, sprintf('accesos-colaboradores-migracion-%d.csv', $migracion->id), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function inventario(): InventarioNasHistorico
    {
        return $this->inventario;
    }
}
