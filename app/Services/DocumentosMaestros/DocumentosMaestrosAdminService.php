<?php

namespace App\Services\DocumentosMaestros;

use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use Illuminate\Validation\ValidationException;

/**
 * Administración de documentos maestros (solo RH administrador): cargar o
 * reemplazar el original, versionar, activar/desactivar y PROBAR con un
 * colaborador (QA). No es donde se generan documentos de personas: eso
 * ocurre en cada proceso (ficha, cierre, solicitud, préstamo…).
 */
class DocumentosMaestrosAdminService
{
    public function __construct(
        private readonly CatalogoMaestrosService $catalogo,
        private readonly MotorDocumentalService $motor,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Una fila por familia: documento, proceso, a quién aplica, versión
     * activa, estado y última prueba.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $masters = DocumentTemplate::query()->with('creadoPor')->whereNotNull('estado_master')->orderBy('familia')->orderByDesc('version')->get()->groupBy('familia');
        $filas = [];

        foreach ($this->catalogo->definiciones() as $familia => $definicion) {
            $versiones = $masters->get($familia, collect());
            $activa = $versiones->firstWhere('activo', true);
            $actual = $activa ?? $versiones->first();
            $grupos = (array) ($definicion['grupos'] ?? []);

            $filas[] = [
                'familia' => $familia,
                'clave' => $definicion['clave'] ?? null,
                'nombre' => $definicion['nombre'] ?? $familia,
                'proceso' => $definicion['proceso'] ?? null,
                'proceso_etiqueta' => $this->catalogo->etiquetaProceso($definicion['proceso'] ?? null),
                'aplica_a' => $grupos === [] ? 'General' : implode(', ', array_map(fn (string $g): string => (string) $this->catalogo->etiquetaGrupo($g), $grupos)),
                'empresa' => $actual->empresa->nombre ?? 'Todas',
                'motor' => $definicion['motor'] ?? 'docx',
                'operativo' => (bool) ($definicion['operativo'] ?? true),
                'version_activa' => $activa?->version,
                'estado' => $actual->estado_master ?? 'sin_original',
                'activo' => $activa !== null,
                'master_id' => $actual?->id,
                'detectados' => (int) ($actual?->analisis['detectados'] ?? 0),
                'mapeados' => (int) ($actual?->analisis['mapeados'] ?? 0),
                'pendientes' => count((array) ($actual?->analisis['pendientes'] ?? [])) + count((array) ($actual?->analisis['reglas_pendientes'] ?? [])),
                'ultima_prueba_en' => $actual?->ultima_prueba_en?->toIso8601String(),
                'versiones' => $versiones->map(fn (DocumentTemplate $m): array => [
                    'id' => $m->id,
                    'version' => $m->version,
                    'activo' => $m->activo,
                    'estado' => $m->estado_master,
                    'original' => $m->original_nombre,
                    'cargado_en' => $m->created_at?->toIso8601String(),
                    'cargado_por' => $m->creadoPor?->name,
                ])->values()->all(),
            ];
        }

        return $filas;
    }

    /**
     * @return array<string, mixed>
     */
    public function detalle(DocumentTemplate $master): array
    {
        $definicion = $this->catalogo->definicion((string) $master->familia) ?? [];
        $analisis = (array) ($master->analisis ?? []);

        return [
            'id' => $master->id,
            'familia' => $master->familia,
            'clave' => $master->clave,
            'nombre' => $master->nombre,
            'version' => $master->version,
            'activo' => $master->activo,
            'estado' => $master->estado_master,
            'motor' => $master->motor->value,
            'proceso' => $this->catalogo->etiquetaProceso($master->proceso),
            'grupos' => array_map(fn (string $g): string => (string) $this->catalogo->etiquetaGrupo($g), (array) ($master->grupos_puesto ?? [])),
            'original' => ['nombre' => $master->original_nombre, 'sha256' => $master->original_hash],
            'master_hash' => $master->master_hash,
            'fuentes' => (array) ($master->fuentes ?? []),
            'banderas' => [
                'impresion' => $master->requiere_impresion,
                'firma_fisica' => $master->requiere_firma_fisica,
                'huella' => $master->requiere_huella,
                'testigos' => $master->requiere_testigos,
                'cantidad_testigos' => $master->cantidad_testigos,
                'envio_corporativo' => $master->requiere_envio_corporativo,
            ],
            'representante' => $definicion['representante'] ?? null,
            'reporte' => [
                'detectados' => (int) ($analisis['detectados'] ?? 0),
                'mapeados' => (int) ($analisis['mapeados'] ?? 0),
                'firmas' => (int) ($analisis['firmas'] ?? 0),
                'pendientes' => array_values((array) ($analisis['pendientes'] ?? [])),
                'reglas_pendientes' => array_values((array) ($analisis['reglas_pendientes'] ?? [])),
                'campos' => array_values((array) ($analisis['campos'] ?? [])),
                'paginas' => array_values((array) ($analisis['paginas'] ?? [])),
                'imagenes_conservadas' => count((array) ($analisis['medios'] ?? [])),
            ],
            'observaciones' => array_values(array_filter(explode("\n", (string) $master->observaciones))),
            // Quién cargó, activó, desactivó y probó esta versión.
            'historial' => $this->auditoria->historial($master),
            'cargada_por' => $master->creadoPor?->name,
            'ultima_prueba' => $master->ultima_prueba_en !== null ? [
                'en' => $master->ultima_prueba_en->toIso8601String(),
                'resultado' => $master->ultima_prueba_resultado,
            ] : null,
        ];
    }

    /**
     * "Probar con colaborador": PDF de vista previa (no se guarda en el
     * expediente) y reporte de faltantes. Registra la última prueba.
     *
     * @return array{pdf: string, faltantes: list<array<string, mixed>>, fidelidad: string}
     */
    public function probar(DocumentTemplate $master, Colaborador $colaborador, User $actor): array
    {
        if ($master->estado_master === 'referencia') {
            throw ValidationException::withMessages(['master' => 'Este documento es de referencia: no se genera para colaboradores.']);
        }

        $contrato = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->orderByDesc('fecha_inicio')->first();
        $contexto = $this->motor->contextoDesde($colaborador, $contrato, $actor);
        $resultado = $this->motor->previsualizarMaestro($master, $contexto);

        $master->update([
            'ultima_prueba_en' => now(),
            'ultima_prueba_por' => $actor->id,
            'ultima_prueba_resultado' => [
                'colaborador_id' => $colaborador->id,
                'colaborador' => $colaborador->nombreCompleto(),
                'faltantes' => array_map(fn (array $f): string => (string) $f['etiqueta'], $resultado['faltantes']),
                'fidelidad' => $resultado['fidelidad'],
                'conversor' => $resultado['conversor'],
            ],
        ]);

        $this->auditoria->registrar('documento_maestro_probado', $master, $actor, ['familia' => $master->familia, 'version' => $master->version, 'colaborador_id' => $colaborador->id]);

        return ['pdf' => $resultado['pdf'], 'faltantes' => $resultado['faltantes'], 'fidelidad' => $resultado['fidelidad']];
    }

    public function desactivar(DocumentTemplate $master, User $actor): DocumentTemplate
    {
        $master->update(['activo' => false]);
        $this->auditoria->registrar('documento_maestro_desactivado', $master, $actor, ['familia' => $master->familia, 'version' => $master->version]);

        return $master->refresh();
    }
}
