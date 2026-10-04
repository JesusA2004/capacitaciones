<?php

namespace App\Services\DocumentosMaestros;

use App\Enums\CategoriaDocumento;
use App\Enums\MotorPlantilla;
use App\Enums\TipoPlantillaDocumento;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Incorpora los formatos jurídicos ORIGINALES como documentos maestros:
 *
 *   archivo → SHA-256 → familia/versión (registro versionado) → original
 *   inmutable en el NAS → master técnico preparado por el sistema →
 *   DocumentTemplate (versión) con mapping, análisis y estado.
 *
 * Idempotente: la identidad es (familia, versión) y el original se
 * reconoce por su hash, así que correrlo dos veces no duplica nada; si
 * cambian las reglas del registro, el master se vuelve a preparar sobre el
 * mismo original (las instancias ya generadas no cambian: guardan su
 * snapshot y el hash del master con el que se hicieron).
 */
class ImportadorFormatosJuridicosService
{
    public function __construct(
        private readonly CatalogoMaestrosService $catalogo,
        private readonly PreparacionMaestroService $preparacion,
        private readonly AlmacenMaestrosService $almacen,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @return array{archivos: list<array<string, mixed>>, versiones: list<array<string, mixed>>, anomalias: list<string>}
     */
    public function importarCarpeta(string $carpeta, ?User $actor = null, bool $simular = false): array
    {
        if (! is_dir($carpeta)) {
            throw ValidationException::withMessages(['carpeta' => "No existe la carpeta de formatos: {$carpeta}"]);
        }

        $archivos = [];
        $porHash = [];
        $anomalias = [];

        foreach (glob(rtrim($carpeta, '/\\').DIRECTORY_SEPARATOR.'*') ?: [] as $ruta) {
            if (! is_file($ruta)) {
                continue;
            }

            $nombre = basename($ruta);
            $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
            $hash = (string) hash_file('sha256', $ruta);
            $familias = $this->catalogo->todasPorHash($hash);
            $duplicado = isset($porHash[$hash]);
            $porHash[$hash][] = $nombre;

            $archivo = [
                'archivo' => $nombre,
                'sha256' => $hash,
                'tipo' => match ($extension) {
                    'docx' => 'DOCX',
                    'pdf' => 'PDF',
                    default => strtoupper($extension),
                },
                'familias' => array_map(fn (array $f): string => sprintf('%s v%d', $f['familia'], $f['version']), $familias),
                'duplicado_de' => $duplicado ? $porHash[$hash][0] : null,
            ];

            if (! in_array($extension, ['docx', 'pdf'], true)) {
                $anomalias[] = "{$nombre}: tipo de archivo no soportado ({$extension}); solo DOCX o PDF.";
            } elseif ($familias === []) {
                $anomalias[] = "{$nombre}: fuente desconocida (SHA-256 {$hash} no está en config/documentos_maestros.php). No se asigna por parecido de nombre: agrega la familia/versión al registro.";
            }

            $archivos[] = $archivo;
        }

        $versiones = [];

        foreach ($porHash as $hash => $nombres) {
            $ruta = rtrim($carpeta, '/\\').DIRECTORY_SEPARATOR.$nombres[0];
            $contenido = (string) file_get_contents($ruta);

            foreach ($this->catalogo->todasPorHash($hash) as $fuente) {
                if ($simular) {
                    $versiones[] = ['familia' => $fuente['familia'], 'version' => $fuente['version'], 'accion' => 'simulado'];

                    continue;
                }

                try {
                    $master = $this->registrarVersion($fuente['familia'], $fuente['version'], $contenido, $nombres, $fuente, $actor);
                    $versiones[] = $this->resumen($master);

                    foreach ((array) ($master->analisis['pendientes'] ?? []) as $pendiente) {
                        $anomalias[] = sprintf('%s v%d: %s — %s', $fuente['familia'], $fuente['version'], $pendiente['tipo'] ?? 'pendiente', $pendiente['contexto'] ?? '');
                    }

                    foreach ((array) ($master->analisis['reglas_pendientes'] ?? []) as $pendiente) {
                        $anomalias[] = sprintf('%s v%d: regla #%s (%s) sin contexto %s', $fuente['familia'], $fuente['version'], $pendiente['regla'] ?? '?', $pendiente['campo'] ?? '', $pendiente['contexto'] ?? '');
                    }

                    if ($fuente['bloqueada']) {
                        $anomalias[] = sprintf('%s v%d: BLOQUEADA — %s', $fuente['familia'], $fuente['version'], $fuente['nota'] ?? '');
                    }
                } catch (Throwable $e) {
                    $anomalias[] = sprintf('%s v%d: no se pudo preparar el master (%s).', $fuente['familia'], $fuente['version'], $e->getMessage());
                }
            }
        }

        foreach ($porHash as $nombres) {
            if (count($nombres) > 1) {
                $anomalias[] = sprintf('Archivos idénticos (mismo SHA-256): %s. Se registran como fuentes duplicadas de un mismo master; no se borra ninguno.', implode(' = ', $nombres));
            }
        }

        // Familias sin ninguna versión activa tras la importación.
        if (! $simular) {
            foreach ($this->catalogo->definiciones() as $familia => $definicion) {
                if (($definicion['operativo'] ?? true) && ! DocumentTemplate::query()->where('familia', $familia)->where('activo', true)->exists()) {
                    $anomalias[] = sprintf('%s: ninguna versión activa (falta el archivo original o tiene pendientes).', $familia);
                }
            }
        }

        return ['archivos' => $archivos, 'versiones' => $versiones, 'anomalias' => array_values(array_unique($anomalias))];
    }

    /**
     * Registra (o re-prepara) una versión concreta de una familia a partir
     * del contenido del ORIGINAL.
     *
     * @param  list<string>  $nombresFuente
     * @param  array{familia: string, version: int, activa: bool, bloqueada: bool, nota: string|null}|null  $fuente
     */
    public function registrarVersion(string $familia, int $version, string $contenido, array $nombresFuente, ?array $fuente, ?User $actor): DocumentTemplate
    {
        $definicion = $this->catalogo->definicion($familia);

        if ($definicion === null) {
            throw ValidationException::withMessages(['familia' => "La familia «{$familia}» no existe en el registro de documentos maestros."]);
        }

        $motor = MotorPlantilla::from((string) ($definicion['motor'] ?? 'docx'));
        $extension = $motor === MotorPlantilla::PdfOverlay ? 'pdf' : 'docx';
        $hash = hash('sha256', $contenido);
        $preparado = $this->preparacion->preparar($contenido, $definicion, $version);
        $rutaOriginal = $this->almacen->guardarOriginal($contenido, $extension);
        $existente = DocumentTemplate::withTrashed()->where('familia', $familia)->where('version', $version)->first();
        $mismoMaster = $existente !== null && $existente->master_hash === hash('sha256', $preparado['master']) && $existente->path !== null;
        $rutaMaster = $mismoMaster ? (string) $existente->path : $this->almacen->guardarMaster($familia, $version, $preparado['master'], $preparado['extension']);
        $bloqueada = (bool) ($fuente['bloqueada'] ?? false);
        $estado = $bloqueada ? 'bloqueado' : $preparado['estado'];
        $quiereActiva = (bool) ($fuente['activa'] ?? false) && $estado === 'listo';
        $banderas = (array) ($definicion['banderas'] ?? []);
        $grupos = $definicion['grupos'] ?? null;
        $observaciones = array_values(array_filter([
            ...array_map('strval', (array) ($definicion['observaciones'] ?? [])),
            $fuente['nota'] ?? null,
        ]));

        $datos = [
            'clave' => (string) $definicion['clave'],
            'familia' => $familia,
            'nombre' => (string) $definicion['nombre'],
            'descripcion' => $observaciones[0] ?? null,
            'tipo' => $this->tipo((string) $definicion['clave']),
            'categoria' => isset($definicion['categoria']) ? CategoriaDocumento::tryFrom((string) $definicion['categoria']) : null,
            'motor' => $motor,
            'grupos_puesto' => is_array($grupos) ? array_values($grupos) : null,
            'empresa_id' => null,
            'puesto_id' => null,
            'proceso' => $definicion['proceso'] ?? null,
            'evento' => $definicion['evento'] ?? null,
            'causa' => $definicion['causa'] ?? null,
            'disk' => $this->almacen->nombreDisco(),
            'path' => $rutaMaster,
            'original_name' => $nombresFuente[0] ?? null,
            'mime' => $extension === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => strlen($preparado['master']),
            'original_disk' => $this->almacen->nombreDisco(),
            'original_path' => $rutaOriginal,
            'original_hash' => $hash,
            'original_nombre' => $nombresFuente[0] ?? null,
            'master_hash' => hash('sha256', $preparado['master']),
            'mapping' => $preparado['mapping'],
            'analisis' => $preparado['analisis'],
            'estado_master' => $estado,
            'operativo' => (bool) ($definicion['operativo'] ?? true),
            'requiere_impresion' => (bool) ($banderas['requiere_impresion'] ?? false),
            'requiere_firma_fisica' => (bool) ($banderas['requiere_firma_fisica'] ?? false),
            'requiere_huella' => (bool) ($banderas['requiere_huella'] ?? false),
            'requiere_testigos' => (bool) ($banderas['requiere_testigos'] ?? false),
            'requiere_envio_corporativo' => (bool) ($banderas['requiere_envio_corporativo'] ?? false),
            'cantidad_testigos' => (int) ($banderas['cantidad_testigos'] ?? 0),
            'requiere_firma_digital' => false,
            'prioridad_especificidad' => is_array($grupos) ? 20 : 10,
            'fuentes' => array_map(fn (string $n): array => ['nombre' => $n, 'sha256' => $hash], $nombresFuente),
            'observaciones' => $observaciones === [] ? null : implode("\n", $observaciones),
            'version' => $version,
        ];

        return DB::transaction(function () use ($existente, $datos, $quiereActiva, $familia, $actor): DocumentTemplate {
            if ($existente !== null) {
                if ($existente->trashed()) {
                    $existente->restore();
                }

                // Una versión activada a mano por RH no se desactiva en
                // re-importaciones (solo se activa la del registro si no hay otra).
                $existente->fill($datos);
                $existente->save();
                $master = $existente;
            } else {
                $master = DocumentTemplate::query()->create([...$datos, 'activo' => false, 'created_by' => $actor?->id]);
                $this->auditoria->registrar('documento_maestro_importado', $master, $actor, ['familia' => $familia, 'version' => $master->version, 'hash' => $datos['original_hash']]);
            }

            $hayActiva = DocumentTemplate::query()->where('familia', $familia)->where('activo', true)->where('id', '!=', $master->id)->exists();

            if ($quiereActiva && ! $hayActiva && ! $master->activo) {
                $this->activar($master, $actor);
            }

            if ($master->estado_master !== 'listo' && $master->activo) {
                // Nunca queda activo un master con pendientes o bloqueado.
                $master->update(['activo' => false]);
            }

            return $master->refresh();
        });
    }

    /**
     * Activa una versión y desactiva las demás de su familia (una sola
     * activa). Solo masters listos.
     */
    public function activar(DocumentTemplate $master, ?User $actor): DocumentTemplate
    {
        if ($master->estado_master !== 'listo') {
            throw ValidationException::withMessages(['master' => sprintf('«%s» v%d no puede activarse: tiene campos pendientes o está bloqueado. Revisa el reporte de campos.', $master->nombre, $master->version)]);
        }

        DB::transaction(function () use ($master): void {
            DocumentTemplate::query()->where('familia', $master->familia)->where('id', '!=', $master->id)->update(['activo' => false]);
            $master->update(['activo' => true]);
        });

        $this->auditoria->registrar('documento_maestro_activado', $master, $actor, ['familia' => $master->familia, 'version' => $master->version]);

        return $master->refresh();
    }

    /**
     * Carga de una versión NUEVA desde administración (RH sube el archivo
     * que entregó Jurídico; el sistema aplica el mismo mapeo de la familia).
     * Queda inactiva hasta que RH la pruebe y la active.
     */
    public function cargarNuevaVersion(string $familia, string $contenido, string $nombreArchivo, User $actor): DocumentTemplate
    {
        $definicion = $this->catalogo->definicion($familia);

        if ($definicion === null) {
            throw ValidationException::withMessages(['familia' => 'Selecciona un documento maestro válido.']);
        }

        $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
        $esperada = ($definicion['motor'] ?? 'docx') === 'pdf_overlay' ? 'pdf' : 'docx';

        if ($extension !== $esperada) {
            throw ValidationException::withMessages(['archivo' => sprintf('Este documento maestro se procesa como %s: sube el archivo en ese formato.', strtoupper($esperada))]);
        }

        $hash = hash('sha256', $contenido);
        $conocida = $this->catalogo->porHash($hash);

        if ($conocida !== null && $conocida['familia'] === $familia) {
            $version = $conocida['version'];
        } else {
            $igual = DocumentTemplate::query()->where('familia', $familia)->where('original_hash', $hash)->first();
            $version = $igual->version ?? ((int) DocumentTemplate::withTrashed()->where('familia', $familia)->max('version') + 1);
        }

        return $this->registrarVersion($familia, $version, $contenido, [$nombreArchivo], ['familia' => $familia, 'version' => $version, 'activa' => false, 'bloqueada' => false, 'nota' => null], $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function resumen(DocumentTemplate $master): array
    {
        $analisis = (array) ($master->analisis ?? []);

        return [
            'familia' => $master->familia,
            'version' => $master->version,
            'estado' => $master->estado_master,
            'activo' => $master->activo,
            'detectados' => (int) ($analisis['detectados'] ?? 0),
            'mapeados' => (int) ($analisis['mapeados'] ?? 0),
            'pendientes' => count((array) ($analisis['pendientes'] ?? [])) + count((array) ($analisis['reglas_pendientes'] ?? [])),
        ];
    }

    private function tipo(string $clave): TipoPlantillaDocumento
    {
        return match (true) {
            str_starts_with($clave, 'contrato_confidencialidad') => TipoPlantillaDocumento::CartaConfidencialidad,
            str_starts_with($clave, 'contrato'), str_starts_with($clave, 'prestamo') => TipoPlantillaDocumento::Contrato,
            str_contains($clave, 'permiso') => TipoPlantillaDocumento::FormatoPermiso,
            in_array($clave, ['carta_renuncia', 'aviso_terminacion', 'acta_negativa_firma', 'evaluacion_capacitacion'], true) => TipoPlantillaDocumento::FormatoBaja,
            default => TipoPlantillaDocumento::Otro,
        };
    }
}
