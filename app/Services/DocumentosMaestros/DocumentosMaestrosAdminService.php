<?php

namespace App\Services\DocumentosMaestros;

use App\Enums\EstadoValidacionVisual;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\Calidad\DiagnosticoFuentesService;
use App\Services\DocumentosMaestros\Calidad\ValidacionVisualMaestroService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Administración de documentos maestros (solo RH administrador): cargar o
 * reemplazar el original, versionar, validar el diseño, activar/desactivar
 * y PROBAR con un colaborador (QA). No es donde se generan documentos de
 * personas: eso ocurre en cada proceso (ficha, cierre, solicitud,
 * préstamo…).
 */
class DocumentosMaestrosAdminService
{
    public function __construct(
        private readonly CatalogoMaestrosService $catalogo,
        private readonly MotorDocumentalService $motor,
        private readonly AuditoriaService $auditoria,
        private readonly ImportadorFormatosJuridicosService $importador,
        private readonly ValidacionVisualMaestroService $validacion,
        private readonly CoberturaDocumentalService $cobertura,
        private readonly DatosDocumentoService $datos,
        private readonly DiagnosticoFuentesService $fuentes,
        private readonly AlmacenMaestrosService $almacen,
        private readonly ConversorDocxPdf $conversor,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Una fila por familia: documento, proceso, a quién aplica, versión
     * activa, estado ejecutivo (LISTO / REQUIERE REVISIÓN / BLOQUEADO /
     * SIN FORMATO / REFERENCIA), diseño validado e historial de versiones.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $masters = DocumentTemplate::query()->with(['creadoPor', 'activadoPor'])->whereNotNull('estado_master')->orderBy('familia')->orderByDesc('version')->get()->groupBy('familia');
        $filas = [];

        foreach ($this->catalogo->definiciones() as $familia => $definicion) {
            /** @var Collection<int, DocumentTemplate> $versiones */
            $versiones = $masters->get($familia, collect());
            $activa = $versiones->firstWhere('activo', true);
            $actual = $activa ?? $versiones->first();
            $grupos = (array) ($definicion['grupos'] ?? []);
            $operativo = (bool) ($definicion['operativo'] ?? true);

            $filas[] = [
                'familia' => $familia,
                'clave' => $definicion['clave'] ?? null,
                'nombre' => $definicion['nombre'] ?? $familia,
                'proceso' => $definicion['proceso'] ?? null,
                'proceso_etiqueta' => $this->catalogo->etiquetaProceso($definicion['proceso'] ?? null),
                'grupos' => array_values(array_map('strval', $grupos)),
                'aplica_a' => $grupos === [] ? 'General' : implode(', ', array_map(fn (string $g): string => (string) $this->catalogo->etiquetaGrupo($g), $grupos)),
                'empresa' => $actual->empresa->nombre ?? 'Todas',
                'motor' => $definicion['motor'] ?? 'docx',
                'operativo' => $operativo,
                'version_activa' => $activa?->version,
                'estado' => $actual->estado_master ?? 'sin_original',
                'estado_ejecutivo' => $this->estadoEjecutivo($operativo, $activa, $actual),
                'diseno' => $this->diseno($activa ?? $actual),
                'activo' => $activa !== null,
                'master_id' => $actual?->id,
                'detectados' => (int) ($actual?->analisis['detectados'] ?? 0),
                'mapeados' => (int) ($actual?->analisis['mapeados'] ?? 0),
                'pendientes' => count((array) ($actual?->analisis['pendientes'] ?? [])) + count((array) ($actual?->analisis['reglas_pendientes'] ?? [])),
                'ultima_prueba_en' => ($actual->visual_checked_at ?? $actual?->ultima_prueba_en)?->toIso8601String(),
                'versiones_sin_validar' => $versiones->filter(fn (DocumentTemplate $m): bool => $operativo && $m->estado_master === 'listo' && ! $m->disenoValidado())->count(),
                'versiones' => $versiones->map(fn (DocumentTemplate $m): array => $this->resumenVersion($m))->values()->all(),
            ];
        }

        return $filas;
    }

    /**
     * KPIs del encabezado de Documentos maestros.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{formatos_activos: int, requieren_revision: int, puestos_sin_cobertura: int, versiones_sin_validar: int}
     */
    public function kpis(array $filas): array
    {
        $operativas = array_filter($filas, fn (array $f): bool => (bool) $f['operativo']);

        return [
            'formatos_activos' => count(array_filter($operativas, fn (array $f): bool => $f['estado_ejecutivo'] === 'listo')),
            'requieren_revision' => count(array_filter($operativas, fn (array $f): bool => in_array($f['estado_ejecutivo'], ['requiere_revision', 'bloqueado'], true))),
            'puestos_sin_cobertura' => $this->cobertura->puestosConProblema(),
            'versiones_sin_validar' => array_sum(array_map(fn (array $f): int => (int) $f['versiones_sin_validar'], $operativas)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detalle(DocumentTemplate $master, ?User $viewer = null): array
    {
        $master->loadMissing(['creadoPor', 'activadoPor']);
        $definicion = $this->catalogo->definicion((string) $master->familia) ?? [];
        $analisis = (array) ($master->analisis ?? []);
        $reporte = (array) ($master->visual_report ?? []);
        $bloqueos = $this->importador->bloqueosActivacion($master);
        $versiones = DocumentTemplate::query()->with(['creadoPor', 'activadoPor'])->where('familia', $master->familia)->whereNotNull('estado_master')->orderByDesc('version')->get();

        return [
            'id' => $master->id,
            'familia' => $master->familia,
            'clave' => $master->clave,
            'nombre' => $master->nombre,
            'version' => $master->version,
            'activo' => $master->activo,
            'estado' => $master->estado_master,
            'estado_ejecutivo' => $this->estadoEjecutivo($master->operativo, $master->activo ? $master : null, $master),
            'motor' => $master->motor->value,
            'proceso' => $this->catalogo->etiquetaProceso($master->proceso),
            'grupos' => array_map(fn (string $g): string => (string) $this->catalogo->etiquetaGrupo($g), (array) ($master->grupos_puesto ?? [])),
            'diseno' => $this->diseno($master),
            'resumen' => [
                'documento' => $master->nombre,
                'version' => $master->version,
                'proceso' => $this->catalogo->etiquetaProceso($master->proceso),
                'aplica_a' => ($master->grupos_puesto ?? []) === [] ? 'General' : implode(', ', array_map(fn (string $g): string => (string) $this->catalogo->etiquetaGrupo($g), (array) $master->grupos_puesto)),
                'cargada_por' => $master->creadoPor?->name,
                'cargada_en' => $master->created_at?->toIso8601String(),
                'activada_por' => $master->activadoPor?->name,
                'activada_en' => $master->activado_en?->toIso8601String(),
                'ultima_prueba_en' => $master->visual_checked_at?->toIso8601String(),
                'paginas' => $master->page_count_original,
            ],
            'calidad' => [
                'estado' => $master->visual_validation_status->value,
                'etiqueta' => $master->visual_validation_status->etiqueta(),
                'similitud' => $master->visual_similarity,
                'paginas_original' => $master->page_count_original,
                'paginas_prueba' => $master->page_count_output,
                'motor' => $master->visual_engine,
                'fidelidad' => $reporte['fidelidad'] ?? null,
                'problemas' => array_values(array_map('strval', (array) ($reporte['problemas'] ?? []))),
                'advertencias' => array_values(array_map('strval', (array) ($reporte['advertencias'] ?? []))),
                'desbordes' => array_values((array) ($reporte['desbordes'] ?? [])),
                'por_pagina' => array_values(array_map(fn (array $p): array => ['pagina' => $p['pagina'], 'similitud' => $p['similitud']], array_filter((array) (($reporte['identidad'] ?? [])['por_pagina'] ?? []), 'is_array'))),
                'estructura' => $reporte['estructura'] ?? null,
                'revisado_en' => $master->visual_checked_at?->toIso8601String(),
                'revisado_por' => $reporte['ejecutado_por'] ?? null,
                'excepcion' => $master->activacion_excepcional_motivo,
                'impedimento' => $this->validacion->impedimento($master),
            ],
            'fuentes_documento' => $this->diagnosticoFuentes($master),
            'activacion' => [
                'puede' => $bloqueos === [],
                'bloqueos' => $bloqueos,
                'excepcion_permitida' => $bloqueos !== [] && array_filter($bloqueos, fn (array $b): bool => ! $b['excepcionable']) === [] && ($viewer?->can('documentos_maestros.activar_excepcional') ?? false),
            ],
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
                'reglas' => (int) ($analisis['reglas'] ?? 0),
                'campos' => array_values((array) ($analisis['campos'] ?? [])),
                'paginas' => array_values((array) ($analisis['paginas'] ?? [])),
                'imagenes_conservadas' => count((array) ($analisis['medios'] ?? [])),
            ],
            'observaciones' => array_values(array_filter(explode("\n", (string) $master->observaciones))),
            'versiones' => $versiones->map(fn (DocumentTemplate $m): array => $this->resumenVersion($m))->values()->all(),
            // Quién cargó, validó, activó, desactivó y probó esta versión.
            'historial' => $this->auditoria->historial($master),
            'cargada_por' => $master->creadoPor?->name,
            'ultima_prueba' => $master->ultima_prueba_en !== null ? [
                'en' => $master->ultima_prueba_en->toIso8601String(),
                'resultado' => $master->ultima_prueba_resultado,
            ] : null,
        ];
    }

    public function validarDiseno(DocumentTemplate $master, User $actor): DocumentTemplate
    {
        if ($master->estado_master === 'referencia' || ! $master->operativo) {
            throw ValidationException::withMessages(['master' => 'Este documento es de referencia: no se genera para colaboradores.']);
        }

        return $this->validacion->validar($master, $actor);
    }

    /**
     * "Revalidar pendientes": repite el QA visual de TODAS las versiones
     * operativas pendientes o fallidas con el estado ACTUAL del servidor
     * (p. ej. después de instalar una fuente). No reimporta, no crea
     * versiones nuevas y nunca activa nada; usa el mismo
     * ValidacionVisualMaestroService::validar() que "Probar diseño", así
     * que también invalida el caché de fuentes antes de cada corrida.
     *
     * @return list<array{familia: string, version: int, estado: string, motivo: string}>
     */
    public function revalidarPendientes(?User $actor = null): array
    {
        $pendientes = DocumentTemplate::query()
            ->whereNotNull('estado_master')
            ->where('operativo', true)
            ->whereIn('visual_validation_status', [EstadoValidacionVisual::Pendiente, EstadoValidacionVisual::Fallida])
            ->orderBy('familia')
            ->orderByDesc('version')
            ->get();

        $mapeado = $pendientes->map(function (DocumentTemplate $master) use ($actor): array {
            try {
                $resultado = $this->validacion->validar($master, $actor);
            } catch (Throwable $e) {
                return ['familia' => $master->familia ?? '', 'version' => $master->version, 'estado' => 'fallido', 'motivo' => mb_substr($e->getMessage(), 0, 150)];
            }

            $estado = match ($resultado->visual_validation_status) {
                EstadoValidacionVisual::Aprobada => 'validado',
                EstadoValidacionVisual::Fallida => 'fallido',
                EstadoValidacionVisual::Pendiente => 'pendiente',
            };
            $problemas = (array) ($resultado->visual_report['problemas'] ?? []);

            return ['familia' => $master->familia ?? '', 'version' => $master->version, 'estado' => $estado, 'motivo' => $estado === 'validado' ? '' : (string) ($problemas[0] ?? 'Sin detalle.')];
        })->all();

        return array_values($mapeado);
    }

    /**
     * "Probar con colaborador": vista previa (no se guarda en el expediente)
     * + resumen comparativo ORIGINAL vs GENERADO. El PDF de la prueba se
     * guarda en el disco privado con un token de un solo uso de lectura
     * (lo abre la pantalla de administración y se borra a las 24 h).
     *
     * @return array<string, mixed>
     */
    public function probar(DocumentTemplate $master, Colaborador $colaborador, User $actor): array
    {
        if ($master->estado_master === 'referencia') {
            throw ValidationException::withMessages(['master' => 'Este documento es de referencia: no se genera para colaboradores.']);
        }

        $contrato = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->orderByDesc('fecha_inicio')->first();
        $contexto = $this->motor->contextoDesde($colaborador, $contrato, $actor);
        $resultado = $this->motor->previsualizarMaestro($master, $contexto);
        $token = (string) Str::uuid();
        $this->almacen->disco()->put($this->rutaPrueba($master, $token), $resultado['pdf']);
        $this->limpiarPruebasViejas();
        $campos = $resultado['valores'];
        $llenos = count(array_filter($campos, fn (string $v): bool => trim($v) !== ''));
        $fuentes = $this->diagnosticoFuentes($master);
        $origenes = $this->datos->origenesPatron($contexto);
        // fijo_juridico: el representante está escrito en las declaraciones
        // notariales del original (no cambia); empresa: es un dato del documento.
        $origenes['representante']['modo'] = (string) ($this->catalogo->definicion((string) $master->familia)['representante'] ?? 'no_aplica');

        $resumen = [
            'colaborador' => ['id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'numero_empleado' => $colaborador->numero_empleado],
            'paginas' => ['original' => $master->page_count_original, 'generado' => $resultado['paginas']],
            'fidelidad' => $resultado['fidelidad'],
            'conversor' => $resultado['conversor'],
            'diseno' => $this->diseno($master),
            'campos' => ['llenos' => $llenos, 'total' => count($campos)],
            'faltantes' => array_map(fn (array $f): string => (string) $f['etiqueta'], $resultado['faltantes']),
            'desbordes' => $resultado['desbordes'],
            'fuentes' => ['ok' => array_filter($fuentes, fn (array $f): bool => ! $f['disponible']) === [], 'detalle' => $fuentes],
            'patron' => $origenes,
            'url_resultado' => sprintf('/rh/documentos-maestros/%d/prueba/%s', $master->id, $token),
            'url_original' => sprintf('/rh/documentos-maestros/%d/original-pdf', $master->id),
        ];

        $master->update([
            'ultima_prueba_en' => now(),
            'ultima_prueba_por' => $actor->id,
            'ultima_prueba_resultado' => [
                'colaborador_id' => $colaborador->id,
                'colaborador' => $colaborador->nombreCompleto(),
                'faltantes' => $resumen['faltantes'],
                'fidelidad' => $resultado['fidelidad'],
                'conversor' => $resultado['conversor'],
                'paginas' => $resumen['paginas'],
                'desbordes' => count($resultado['desbordes']),
            ],
        ]);

        $this->auditoria->registrar('documento_maestro_probado', $master, $actor, ['familia' => $master->familia, 'version' => $master->version, 'colaborador_id' => $colaborador->id]);

        return $resumen;
    }

    public function pdfPrueba(DocumentTemplate $master, string $token): ?string
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $token) !== 1) {
            return null;
        }

        return $this->almacen->disco()->get($this->rutaPrueba($master, $token));
    }

    /**
     * El ORIGINAL de Jurídico como PDF, para compararlo lado a lado con el
     * generado. PDF original: tal cual. Word: convertido con el conversor
     * fiel (se guarda por hash para no convertir cada vez).
     *
     * @return array{pdf: string, fidelidad: string}|null
     */
    public function originalPdf(DocumentTemplate $master): ?array
    {
        $original = $this->almacen->original($master);

        if ($master->motor->value === 'pdf_overlay') {
            return ['pdf' => $original, 'fidelidad' => 'nativa'];
        }

        $ruta = sprintf('%s/previews/original-%s.pdf', config('documentos_maestros.carpeta', 'documentos-maestros'), $master->original_hash ?? hash('sha256', $original));
        $disco = $this->almacen->disco();

        if ($disco->exists($ruta)) {
            return ['pdf' => (string) $disco->get($ruta), 'fidelidad' => 'nativa'];
        }

        $convertido = $this->conversor->convertir($original);

        if ($convertido === null) {
            return null;
        }

        // Solo se guarda en caché la conversión fiel.
        if ($convertido['fidelidad'] !== 'aproximada') {
            $disco->put($ruta, $convertido['pdf']);
        }

        return ['pdf' => $convertido['pdf'], 'fidelidad' => $convertido['fidelidad']];
    }

    /**
     * Buscador de colaboradores para "Probar con colaborador" (nombre,
     * número de empleado) dentro del alcance de quien prueba.
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null}>
     */
    public function buscarColaboradores(User $usuario, string $termino): array
    {
        // Cada palabra debe aparecer en nombre, apellidos o número de
        // empleado, sin importar acentos ni mayúsculas ("juan perez"
        // encuentra a Juan Pérez López). Se compara en PHP con texto plegado
        // a ASCII: igual en SQLite y MariaDB, sin depender del collation.
        $palabras = array_slice(preg_split('/\s+/u', Str::lower(Str::ascii(trim($termino))), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
        $candidatos = Colaborador::query()->select(['id', 'name', 'apellidos', 'numero_empleado']);
        $this->alcance->limitarColaboradoresPorAlcance($candidatos, $usuario);
        $ids = $candidatos->orderBy('name')->orderBy('apellidos')->limit(3000)->get()
            ->filter(function (Colaborador $c) use ($palabras): bool {
                $texto = Str::lower(Str::ascii(sprintf('%s %s %s', $c->name, $c->apellidos, $c->numero_empleado)));

                return collect($palabras)->every(fn (string $p): bool => str_contains($texto, $p));
            })
            ->take(12)
            ->pluck('id')
            ->all();

        $consulta = Colaborador::query()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])->whereIn('id', $ids);

        return array_values($consulta->orderBy('name')->orderBy('apellidos')->get()->map(fn (Colaborador $c): array => [
            'id' => $c->id,
            'nombre' => $c->nombreCompleto(),
            'numero_empleado' => $c->numero_empleado,
            'puesto' => $c->puesto?->nombre,
            'sucursal' => $c->sucursalPrincipal?->nombre,
        ])->all());
    }

    public function desactivar(DocumentTemplate $master, User $actor): DocumentTemplate
    {
        $master->update(['activo' => false]);
        $this->auditoria->registrar('documento_maestro_desactivado', $master, $actor, ['familia' => $master->familia, 'version' => $master->version]);

        return $master->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenVersion(DocumentTemplate $m): array
    {
        $reporte = (array) ($m->visual_report ?? []);

        return [
            'id' => $m->id,
            'version' => $m->version,
            'activo' => $m->activo,
            'estado' => $m->estado_master,
            'diseno' => $this->diseno($m),
            'original' => $m->original_nombre,
            'cargado_en' => $m->created_at?->toIso8601String(),
            'cargado_por' => $m->creadoPor?->name,
            'activado_en' => $m->activado_en?->toIso8601String(),
            'activado_por' => $m->activadoPor?->name,
            'ultima_prueba_en' => $m->visual_checked_at?->toIso8601String(),
            'fidelidad' => $reporte['fidelidad'] ?? null,
            'similitud' => $m->visual_similarity,
            'excepcional' => $m->activacion_excepcional_motivo !== null && $m->activacion_excepcional_motivo !== '',
        ];
    }

    /**
     * listo | requiere_revision | bloqueado | sin_formato | referencia
     */
    private function estadoEjecutivo(bool $operativo, ?DocumentTemplate $activa, ?DocumentTemplate $actual): string
    {
        if (! $operativo) {
            return 'referencia';
        }

        if ($actual === null) {
            return 'sin_formato';
        }

        if ($activa === null) {
            return $actual->estado_master === 'bloqueado' ? 'bloqueado' : 'requiere_revision';
        }

        return $activa->disenoValidado() && $activa->estado_master === 'listo' ? 'listo' : 'requiere_revision';
    }

    /**
     * validado | sin_validar | fallido | excepcion | no_aplica
     */
    private function diseno(?DocumentTemplate $master): string
    {
        if ($master === null || ! $master->operativo || $master->estado_master === 'referencia') {
            return 'no_aplica';
        }

        if ($master->visual_validation_status === EstadoValidacionVisual::Aprobada) {
            return 'validado';
        }

        if ($master->disenoValidado()) {
            return 'excepcion';
        }

        return $master->visual_validation_status === EstadoValidacionVisual::Fallida ? 'fallido' : 'sin_validar';
    }

    /**
     * @return list<array{fuente: string, disponible: bool, sustitucion: string|null, familia_encontrada: string|null, archivo: string|null}>
     */
    private function diagnosticoFuentes(DocumentTemplate $master): array
    {
        if ($master->motor->value !== 'docx') {
            return [];
        }

        $guardado = $master->diagnostico_fuentes;

        if (is_array($guardado) && $guardado !== []) {
            $lista = [];

            foreach ($guardado as $fuente) {
                if (is_array($fuente) && isset($fuente['fuente'])) {
                    $lista[] = [
                        'fuente' => (string) $fuente['fuente'],
                        'disponible' => (bool) ($fuente['disponible'] ?? false),
                        'sustitucion' => isset($fuente['sustitucion']) ? (string) $fuente['sustitucion'] : null,
                        'familia_encontrada' => isset($fuente['familia_encontrada']) ? (string) $fuente['familia_encontrada'] : null,
                        'archivo' => isset($fuente['archivo']) ? (string) $fuente['archivo'] : null,
                    ];
                }
            }

            return $lista;
        }

        try {
            return $this->fuentes->diagnosticar($this->almacen->original($master));
        } catch (Throwable) {
            return [];
        }
    }

    private function rutaPrueba(DocumentTemplate $master, string $token): string
    {
        return sprintf('%s/pruebas/%d-%s.pdf', config('documentos_maestros.carpeta', 'documentos-maestros'), $master->id, $token);
    }

    private function limpiarPruebasViejas(): void
    {
        $disco = $this->almacen->disco();
        $limite = now()->subDay()->getTimestamp();

        foreach ($disco->files(sprintf('%s/pruebas', config('documentos_maestros.carpeta', 'documentos-maestros'))) as $archivo) {
            try {
                if ($disco->lastModified($archivo) < $limite) {
                    $disco->delete($archivo);
                }
            } catch (Throwable) {
                // Otro proceso ya lo borró.
            }
        }
    }
}
