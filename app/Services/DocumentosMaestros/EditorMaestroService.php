<?php

namespace App\Services\DocumentosMaestros;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoCampoDocumento;
use App\Enums\EstadoValidacionVisual;
use App\Enums\MotorPlantilla;
use App\Enums\TransformacionTexto;
use App\Enums\ZonaCampoDocumento;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateField;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosMaestros\Calidad\ValidacionVisualMaestroService;
use App\Services\DocumentosMaestros\Calidad\ValoresPruebaDocumento;
use App\Services\DocumentosMaestros\Deteccion\CandidatosDocxService;
use App\Services\DocumentosMaestros\Deteccion\CandidatosPdfService;
use App\Services\DocumentosMaestros\Deteccion\TipoArchivoDocumento;
use App\Services\DocumentosMaestros\Docx\PreparadorMasterDocx;
use App\Services\DocumentosMaestros\Pdf\RenderizadorOverlayMaestro;
use App\Services\DocumentosMaestros\Variables\CatalogoVariablesDocumento;
use App\Services\DocumentosMaestros\Variables\ExpresionesDocumentoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Editor visual NO-CODE de documentos maestros: todo lo que RH/Jurídico
 * hace sin editar PHP, config ni rutas.
 *
 *   subir archivo → detectar tipo (DOCX / PDF digital / PDF escaneado)
 *   → detectar espacios (CandidatosDocx/PdfService, OCR local solo si es
 *   escaneado) → RH confirma qué dato va en cada espacio → se compila el
 *   mapa (DefinicionMaestroService) y se prepara el master → datos largos
 *   (QA de estrés) → probar con un colaborador → activar.
 *
 * Una versión ya activa o que ya generó documentos NO se edita: su mapa
 * pertenece a lo ya emitido. Para cambiarla se crea una versión nueva
 * (mismo original, mapa copiado) que no se activa sola.
 */
class EditorMaestroService
{
    private const MAX_ESPACIOS = 200;

    public function __construct(
        private readonly ImportadorFormatosJuridicosService $importador,
        private readonly CatalogoMaestrosService $catalogo,
        private readonly CatalogoVariablesDocumento $variables,
        private readonly ExpresionesDocumentoService $expresiones,
        private readonly AlmacenMaestrosService $almacen,
        private readonly PreparadorMasterDocx $preparador,
        private readonly CandidatosDocxService $candidatosDocx,
        private readonly CandidatosPdfService $candidatosPdf,
        private readonly TipoArchivoDocumento $tipoArchivo,
        private readonly ValidacionVisualMaestroService $validacion,
        private readonly ValoresPruebaDocumento $valoresPrueba,
        private readonly RenderizadorOverlayMaestro $overlay,
        private readonly AuditoriaService $auditoria,
    ) {}

    // ───────────────────────── Crear ─────────────────────────

    /**
     * «Nuevo formato»: familia nueva creada desde la UI.
     *
     * @param  array{nombre: string, clave?: string|null, grupo?: string|null, proceso: string, requiere_impresion?: bool, requiere_firma_fisica?: bool, requiere_huella?: bool}  $datos
     */
    public function crearFormato(array $datos, UploadedFile $archivo, User $actor): DocumentTemplate
    {
        $contenido = (string) $archivo->get();
        $tipo = $this->tipoArchivo->detectar($contenido);

        if ($tipo === null) {
            throw ValidationException::withMessages(['archivo' => 'Sube el documento original en Word (.docx) o PDF.']);
        }

        $nombre = trim($datos['nombre']);
        $clave = trim((string) ($datos['clave'] ?? '')) !== '' ? (string) $datos['clave'] : Str::slug($nombre, '_');
        $clave = substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower($clave)), 0, 60);

        if ($clave === '') {
            throw ValidationException::withMessages(['nombre' => 'El nombre debe tener letras o números.']);
        }

        $grupo = trim((string) ($datos['grupo'] ?? '')) ?: null;
        $familia = $this->familiaLibre(sprintf('%s.%s', $clave, $grupo ?? 'general'));
        $motor = $tipo === 'docx' ? MotorPlantilla::Docx : MotorPlantilla::PdfOverlay;

        $base = [
            'clave' => $clave,
            'nombre' => $nombre,
            'proceso' => $datos['proceso'],
            'evento' => null,
            'grupos' => $grupo !== null ? [$grupo] : null,
            'motor' => $motor->value,
            'categoria' => $this->categoria($datos['proceso'])->value,
            'operativo' => true,
            'banderas' => [
                'requiere_impresion' => (bool) ($datos['requiere_impresion'] ?? true),
                'requiere_firma_fisica' => (bool) ($datos['requiere_firma_fisica'] ?? true),
                'requiere_huella' => (bool) ($datos['requiere_huella'] ?? false),
            ],
            'representante' => 'empresa',
            'reglas' => [],
            'campos' => [],
            'requeridos' => [],
        ];

        $nombreArchivo = $archivo->getClientOriginalName();
        $master = $this->importador->registrarVersion($familia, 1, $contenido, [$nombreArchivo], ['familia' => $familia, 'version' => 1, 'activa' => false, 'bloqueada' => false, 'nota' => null], $actor, null, $base, false);
        $master->update(['configuracion' => [...(array) ($master->configuracion ?? []), 'tipo_archivo' => $tipo, 'representante' => 'empresa']]);
        $this->auditoria->registrar('documento_maestro_creado', $master, $actor, ['familia' => $familia, 'tipo_archivo' => $tipo]);

        $this->detectarEspacios($master, $actor);

        return $master->refresh();
    }

    /**
     * Detecta los espacios que aún no tiene el mapa y los agrega (amarillo
     * o rojo: nada queda confirmado sin RH). Devuelve cuántos agregó.
     */
    public function detectarEspacios(DocumentTemplate $master, User $actor): int
    {
        $this->exigirEditable($master);
        $original = $this->almacen->original($master);
        $existentes = $master->campos()->get();
        $siguiente = $this->siguienteNumero($existentes);
        $nuevos = [];

        if ($master->motor === MotorPlantilla::Docx) {
            $reglas = $this->reglasVista($existentes);
            $fechasFijas = array_values(array_map('strval', (array) (($master->configuracion ?? [])['fechas_fijas'] ?? [])));

            foreach ($this->candidatosDocx->detectar($original, $reglas, $fechasFijas) as $candidato) {
                $nuevos[] = [
                    'field_key' => sprintf('c%d', $siguiente++),
                    'zona' => $candidato['zona'],
                    'estado' => $this->estadoInicial($candidato['zona'], $candidato['sugerencias'], $candidato['selector']),
                    'label' => $candidato['zona'] !== ZonaCampoDocumento::Dato ? $candidato['zona']->etiqueta() : null,
                    'type' => $candidato['type'],
                    'selector' => $candidato['selector'],
                    'placeholder_original' => $candidato['original'],
                    'detection_method' => $candidato['detection_method'],
                    'detection_confidence' => $candidato['detection_confidence'],
                    'metadata' => ['contexto' => $candidato['contexto'], 'sugerencias' => $candidato['sugerencias'], 'ubicacion' => $candidato['ubicacion']],
                ];
            }
        } else {
            $tipo = (string) (($master->configuracion ?? [])['tipo_archivo'] ?? 'pdf_texto');
            $deteccion = $this->candidatosPdf->detectar($original, $tipo === 'pdf_escaneado' ? 'pdf_escaneado' : 'pdf_texto');

            foreach ($deteccion['candidatos'] as $candidato) {
                if ($this->ocupado($candidato, $existentes)) {
                    continue;
                }

                $nuevos[] = [
                    'field_key' => sprintf('c%d', $siguiente++),
                    'zona' => $candidato['zona'],
                    'estado' => $this->estadoInicial($candidato['zona'], $candidato['sugerencias'], ['caja' => true]),
                    'label' => $candidato['zona'] !== ZonaCampoDocumento::Dato ? $candidato['zona']->etiqueta() : null,
                    'type' => $candidato['type'],
                    'page' => $candidato['page'],
                    'x' => $candidato['x'],
                    'y' => $candidato['y'],
                    'width' => $candidato['width'],
                    'height' => $candidato['height'],
                    'font_size' => 9.0,
                    'detection_method' => $candidato['detection_method'],
                    'detection_confidence' => $candidato['detection_confidence'],
                    'metadata' => ['contexto' => $candidato['contexto'], 'sugerencias' => $candidato['sugerencias']],
                ];
            }

            if ($deteccion['mensaje'] !== null) {
                $master->update(['configuracion' => [...(array) ($master->configuracion ?? []), 'aviso_deteccion' => $deteccion['mensaje']]]);
            }
        }

        $orden = (int) $existentes->max('sort_order');

        DB::transaction(function () use ($master, $nuevos, $actor, &$orden): void {
            foreach (array_slice($nuevos, 0, self::MAX_ESPACIOS) as $fila) {
                // La sugerencia más confiable se pre-asigna (AMARILLO): RH la
                // confirma o cambia; nunca queda verde sin su clic.
                $sugerida = $fila['metadata']['sugerencias'][0] ?? null;

                $master->campos()->create([
                    ...$fila,
                    'source_key' => $fila['zona'] === ZonaCampoDocumento::Dato ? ($sugerida['clave'] ?? null) : null,
                    'label' => $fila['label'] ?? ($sugerida['etiqueta'] ?? null),
                    'required' => true,
                    'sort_order' => ++$orden,
                    'created_by' => $actor->id,
                ]);
            }
        });

        if ($nuevos !== []) {
            $this->recompilar($master, $actor);
        }

        return count($nuevos);
    }

    /**
     * Copia de una versión (mismo original, mismo mapa) para poder editarla
     * cuando la vigente ya no se puede tocar. Queda inactiva.
     */
    public function nuevaVersionDesde(DocumentTemplate $master, User $actor): DocumentTemplate
    {
        $version = (int) DocumentTemplate::withTrashed()->where('familia', $master->familia)->max('version') + 1;
        $nueva = $this->importador->registrarVersion(
            (string) $master->familia,
            $version,
            $this->almacen->original($master),
            [(string) ($master->original_nombre ?? $master->original_name ?? 'original')],
            ['familia' => (string) $master->familia, 'version' => $version, 'activa' => false, 'bloqueada' => false, 'nota' => null],
            $actor,
            $master,
            null,
            false,
        );
        $this->auditoria->registrar('documento_maestro_version_copiada', $nueva, $actor, ['familia' => $master->familia, 'desde' => $master->version, 'version' => $version]);

        return $nueva;
    }

    // ───────────────────────── Editar ─────────────────────────

    /**
     * @param  list<array<string, mixed>>  $campos
     */
    public function guardarCampos(DocumentTemplate $master, array $campos, User $actor): DocumentTemplate
    {
        $this->exigirEditable($master);
        $esPdf = $master->motor === MotorPlantilla::PdfOverlay;
        $existentes = $master->campos()->get()->keyBy('field_key');
        $errores = [];
        $filas = [];

        if (count($campos) > self::MAX_ESPACIOS) {
            throw ValidationException::withMessages(['campos' => sprintf('Un documento admite hasta %d espacios.', self::MAX_ESPACIOS)]);
        }

        foreach ($campos as $i => $campo) {
            $n = $i + 1;
            $fieldKey = (string) ($campo['field_key'] ?? '');
            $existente = $existentes->get($fieldKey);

            // DOCX: los espacios salen del documento (su ancla no la manda el
            // navegador); solo se cambia QUÉ dato llevan.
            if (! $esPdf && $existente === null) {
                $errores[] = "Espacio {$n}: no existe en este documento.";

                continue;
            }

            if (preg_match('/^[a-z0-9_]{1,40}$/', $fieldKey) !== 1) {
                $errores[] = "Espacio {$n}: identificador no válido.";

                continue;
            }

            $zona = ZonaCampoDocumento::tryFrom((string) ($campo['zona'] ?? 'dato'));
            $estado = EstadoCampoDocumento::tryFrom((string) ($campo['estado'] ?? 'sugerido'));
            $transformacion = isset($campo['transform']) ? TransformacionTexto::tryFrom((string) $campo['transform']) : null;
            $sourceKey = isset($campo['source_key']) && $campo['source_key'] !== '' ? (string) $campo['source_key'] : null;
            $expresion = $campo['expression'] ?? null;

            if ($zona === null || $estado === null || (isset($campo['transform']) && $transformacion === null)) {
                $errores[] = "Espacio {$n}: valores no válidos.";

                continue;
            }

            if ($sourceKey !== null && ! $this->variables->existe($sourceKey)) {
                $errores[] = sprintf('Espacio %d: el dato «%s» no existe.', $n, $sourceKey);
            }

            if ($expresion !== null) {
                foreach ($this->expresiones->validar($expresion, fn (string $c): bool => $this->variables->existe($c)) as $error) {
                    $errores[] = "Espacio {$n}: {$error}";
                }
            }

            if ($zona === ZonaCampoDocumento::Dato && $estado === EstadoCampoDocumento::Confirmado && $sourceKey === null && $expresion === null) {
                $estado = EstadoCampoDocumento::SinAsignar;
            }

            $fila = [
                'field_key' => $fieldKey,
                'zona' => $zona,
                'estado' => $estado,
                'source_key' => $zona === ZonaCampoDocumento::Dato ? $sourceKey : null,
                'expression' => $zona === ZonaCampoDocumento::Dato && is_array($expresion) ? $expresion : null,
                'transform' => $zona === ZonaCampoDocumento::Dato ? $transformacion : null,
                'label' => mb_substr(trim((string) ($campo['label'] ?? '')), 0, 191) ?: null,
                'required' => (bool) ($campo['required'] ?? true),
                'type' => ($campo['type'] ?? 'texto') === 'casilla' ? 'casilla' : 'texto',
                'sort_order' => $n,
                'updated_by' => $actor->id,
                'metadata' => [...(array) ($existente->metadata ?? []), 'bloqueado' => (bool) ($campo['bloqueado'] ?? false), 'motivo' => null],
            ];

            if ($esPdf) {
                $geometria = $this->geometria($campo, $n, $errores);
                $fila = [...$fila, ...$geometria];
            }

            $filas[$fieldKey] = $fila;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['campos' => array_slice($errores, 0, 8)]);
        }

        DB::transaction(function () use ($master, $filas, $existentes, $esPdf, $actor): void {
            if ($esPdf) {
                $master->campos()->whereNotIn('field_key', array_keys($filas))->delete();
            }

            foreach ($filas as $fieldKey => $fila) {
                $existente = $existentes->get($fieldKey);

                if ($existente !== null) {
                    $existente->update($fila);
                } else {
                    $master->campos()->create([...$fila, 'detection_method' => 'manual', 'created_by' => $actor->id]);
                }
            }
        });

        $this->auditoria->registrar('documento_maestro_mapa_guardado', $master, $actor, ['familia' => $master->familia, 'version' => $master->version, 'espacios' => count($filas)]);

        return $this->recompilar($master, $actor);
    }

    /**
     * «Datos largos»: la prueba de estrés del QA (nombre, domicilio,
     * puesto, correo, sucursal y contacto de emergencia largos). Resultado
     * en lenguaje humano, con el espacio de cada problema para resaltarlo.
     *
     * @return array{ok: bool, mensaje: string, campos: list<array{field_key: string|null, etiqueta: string, razon: string}>, url_pdf: string|null}
     */
    public function datosLargos(DocumentTemplate $master, User $actor): array
    {
        $master = $this->validacion->validar($master, $actor);
        $reporte = (array) ($master->visual_report ?? []);
        $porCampo = $this->camposPorNombreMotor($master);
        $campos = [];

        foreach ((array) ($reporte['desbordes'] ?? []) as $desborde) {
            $campo = $porCampo[(string) ($desborde['campo'] ?? '')] ?? null;
            $campos[] = ['field_key' => $campo?->field_key, 'etiqueta' => $campo->label ?? (string) ($desborde['campo'] ?? ''), 'razon' => 'No cabe en su espacio con un dato largo.'];
        }

        $advertencias = array_values(array_map('strval', (array) ($reporte['advertencias'] ?? [])));

        // Word que crece de páginas: los espacios con el dato de prueba más
        // largo respecto a su blanco original son los sospechosos.
        if ($master->motor === MotorPlantilla::Docx && $advertencias !== []) {
            foreach ($this->sospechososDocx($master, $porCampo) as $sospechoso) {
                $campos[] = $sospechoso;
            }
        }

        $url = $master->motor === MotorPlantilla::PdfOverlay ? $this->pdfDatosLargos($master) : null;
        $ok = $campos === [] && $advertencias === [];

        return [
            'ok' => $ok,
            'mensaje' => $ok ? 'Con datos largos todo cabe en su lugar.' : ($advertencias[0] ?? 'Revisa los espacios marcados.'),
            'campos' => $campos,
            'url_pdf' => $url,
        ];
    }

    // ───────────────────────── Leer ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function editor(DocumentTemplate $master): array
    {
        [$editable, $motivo] = $this->editable($master);
        $campos = $master->campos()->get();
        $configuracion = (array) ($master->configuracion ?? []);
        $esPdf = $master->motor === MotorPlantilla::PdfOverlay;
        $grupos = (array) ($master->grupos_puesto ?? []);

        return [
            'master' => [
                'id' => $master->id,
                'nombre' => (string) $master->nombre,
                'familia' => (string) $master->familia,
                'version' => $master->version,
                'motor' => $esPdf ? 'pdf_overlay' : 'docx',
                'activo' => $master->activo,
                'editable' => $editable,
                'motivo_no_editable' => $motivo,
                'aplica_a' => $grupos === [] ? 'General' : implode(', ', array_map(fn (mixed $g): string => (string) $this->catalogo->etiquetaGrupo((string) $g), $grupos)),
                'proceso_etiqueta' => $this->catalogo->etiquetaProceso($master->proceso),
                'tipo_archivo' => $configuracion['tipo_archivo'] ?? ($esPdf ? 'pdf_texto' : 'docx'),
                'aviso_deteccion' => $configuracion['aviso_deteccion'] ?? null,
            ],
            'paginas' => $esPdf ? $this->paginas($master) : [],
            'url_fondo' => $esPdf ? route('rh.documentos-maestros.original', $master) : null,
            'parrafos' => $esPdf ? [] : $this->parrafos($master, $campos),
            'campos' => $campos->map(fn (DocumentTemplateField $c): array => $this->aEditor($c))->values()->all(),
            'variables' => array_map(fn (array $g): array => [
                'clave' => $g['clave'],
                'etiqueta' => $g['etiqueta'],
                'variables' => array_map(fn (array $v): array => ['clave' => $v['clave'], 'etiqueta' => $v['etiqueta'], 'grupo' => $v['grupo'], 'ejemplo' => $v['ejemplo'], 'legal' => $v['legal'], 'tipo' => $v['tipo']], $g['variables']),
            ], $this->variables->agrupadas()),
            'preparacion' => $this->preparacion($master, $campos),
            'versiones' => DocumentTemplate::query()->where('familia', $master->familia)->orderByDesc('version')->get()->map(fn (DocumentTemplate $v): array => [
                'id' => $v->id,
                'version' => $v->version,
                'activo' => $v->activo,
                'estado_etiqueta' => $v->activo ? 'Activa' : ($v->disenoValidado() ? 'Validada' : 'En preparación'),
            ])->all(),
        ];
    }

    /**
     * Checklist de activación (Campos · Datos · Fuentes · Diseño · Prueba).
     *
     * @param  Collection<int, DocumentTemplateField>|null  $campos
     * @return array{porcentaje: int, chequeos: list<array{clave: string, etiqueta: string, ok: bool, detalle: string|null}>, faltan: list<string>, puede_activar: bool, activo: bool}
     */
    public function preparacion(DocumentTemplate $master, ?Collection $campos = null): array
    {
        $campos ??= $master->campos()->get();
        $porConfirmar = $campos->filter(fn (DocumentTemplateField $c): bool => $c->zona === ZonaCampoDocumento::Dato && ($c->estado !== EstadoCampoDocumento::Confirmado || ! $c->asignado()))->count();
        $analisis = (array) ($master->analisis ?? []);
        $pendientes = count((array) ($analisis['pendientes'] ?? [])) + count((array) ($analisis['reglas_pendientes'] ?? []));
        $desconocidos = $campos->filter(fn (DocumentTemplateField $c): bool => $c->source_key !== null && ! $this->variables->existe($c->source_key))->count();
        $fuentes = array_values(array_filter((array) ($master->diagnostico_fuentes ?? []), fn (mixed $f): bool => is_array($f) && ! ($f['disponible'] ?? true)));
        $reporte = (array) ($master->visual_report ?? []);
        $bloqueos = $this->importador->bloqueosActivacion($master);

        $chequeos = [
            ['clave' => 'campos', 'etiqueta' => 'Campos', 'ok' => $porConfirmar === 0 && $pendientes === 0, 'detalle' => $porConfirmar + $pendientes > 0 ? sprintf('%d espacio(s) por confirmar', max($porConfirmar, $pendientes)) : null],
            ['clave' => 'datos', 'etiqueta' => 'Datos', 'ok' => $desconocidos === 0, 'detalle' => $desconocidos > 0 ? sprintf('%d dato(s) desconocido(s)', $desconocidos) : null],
            ['clave' => 'fuentes', 'etiqueta' => 'Fuentes', 'ok' => $fuentes === [], 'detalle' => $fuentes !== [] ? 'Falta: '.implode(', ', array_map(fn (array $f): string => (string) ($f['fuente'] ?? ''), $fuentes)) : null],
            ['clave' => 'diseno', 'etiqueta' => 'Diseño', 'ok' => $master->disenoValidado() && ($reporte['desbordes'] ?? []) === [], 'detalle' => $master->visual_validation_status === EstadoValidacionVisual::Fallida ? 'La prueba de diseño no pasó' : ($master->visual_checked_at === null ? 'Falta la prueba de diseño' : null)],
            ['clave' => 'prueba', 'etiqueta' => 'Prueba', 'ok' => $master->ultima_prueba_en !== null || $master->activo, 'detalle' => $master->ultima_prueba_en === null ? 'Prueba con un colaborador' : null],
        ];

        $faltan = array_values(array_unique([
            ...($porConfirmar > 0 ? [sprintf('%d espacio(s) por asignar o confirmar', $porConfirmar)] : []),
            ...array_map(fn (array $f): string => sprintf('fuente %s', $f['fuente'] ?? ''), $fuentes),
            ...array_column($bloqueos, 'mensaje'),
        ]));

        $ok = count(array_filter($chequeos, fn (array $c): bool => $c['ok']));

        return [
            'porcentaje' => (int) round($ok / count($chequeos) * 100),
            'chequeos' => $chequeos,
            'faltan' => array_slice($faltan, 0, 6),
            'puede_activar' => ! $master->activo && $bloqueos === [],
            'activo' => $master->activo,
        ];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    public function editable(DocumentTemplate $master): array
    {
        if ($master->activo) {
            return [false, 'Es la versión activa: para cambiarla crea una versión nueva (la activa no se modifica).'];
        }

        if ($master->documentosGenerados()->exists()) {
            return [false, 'Esta versión ya generó documentos: para cambiarla crea una versión nueva.'];
        }

        if (! $master->operativo || $master->estado_master === 'bloqueado') {
            return [false, 'Documento de referencia o bloqueado: no se edita.'];
        }

        return [true, null];
    }

    // ───────────────────────── Internos ─────────────────────────

    private function recompilar(DocumentTemplate $master, User $actor): DocumentTemplate
    {
        return $this->importador->registrarVersion(
            (string) $master->familia,
            $master->version,
            $this->almacen->original($master),
            [(string) ($master->original_nombre ?? $master->original_name ?? 'original')],
            ['familia' => (string) $master->familia, 'version' => $master->version, 'activa' => false, 'bloqueada' => false, 'nota' => null],
            $actor,
            null,
            null,
            false,
        );
    }

    private function exigirEditable(DocumentTemplate $master): void
    {
        [$editable, $motivo] = $this->editable($master);

        if (! $editable) {
            throw ValidationException::withMessages(['master' => (string) $motivo]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function aEditor(DocumentTemplateField $campo): array
    {
        $metadata = (array) ($campo->metadata ?? []);

        return [
            'id' => $campo->id,
            'field_key' => $campo->field_key,
            'zona' => $campo->zona->value,
            'estado' => $campo->estado->value,
            'source_key' => $campo->source_key,
            'expression' => $campo->expression,
            'transform' => $campo->transform?->value,
            'label' => $campo->label ?? ($campo->source_key !== null ? $this->variables->etiqueta($campo->source_key) : null),
            'type' => $campo->type === 'casilla' ? 'casilla' : 'texto',
            'page' => $campo->page,
            'x' => $campo->x,
            'y' => $campo->y,
            'width' => $campo->width,
            'height' => $campo->height,
            'font_size' => $campo->font_size,
            'font_size_min' => $campo->font_size_min,
            'font_weight' => $campo->font_weight === 'bold' ? 'bold' : null,
            'text_align' => in_array($campo->text_align, ['L', 'C', 'R'], true) ? $campo->text_align : null,
            'line_height' => $campo->line_height,
            'multiline' => $campo->multiline,
            'required' => $campo->required,
            'background_color' => $campo->background_color,
            'selector' => null,
            'contexto' => isset($metadata['contexto']) ? (string) $metadata['contexto'] : null,
            'sugerencias' => array_values(array_filter((array) ($metadata['sugerencias'] ?? []), 'is_array')),
            'detection_method' => $campo->detection_method,
            'motivo' => isset($metadata['motivo']) ? (string) $metadata['motivo'] : null,
            'bloqueado' => (bool) ($metadata['bloqueado'] ?? false),
        ];
    }

    /**
     * Vista del DOCX para el editor: el documento con CADA espacio (de
     * cualquier estado) marcado, aplicado sobre el original.
     *
     * @param  Collection<int, DocumentTemplateField>  $campos
     * @return list<array<string, mixed>>
     */
    private function parrafos(DocumentTemplate $master, Collection $campos): array
    {
        try {
            $reglas = $this->reglasVista($campos);
            $resultado = $this->preparador->preparar($this->almacen->original($master), $reglas);
        } catch (Throwable) {
            return [];
        }

        $mapa = [];

        foreach ($campos as $campo) {
            $mapa[$this->campoVista($campo->field_key)] = $campo->field_key;
        }

        return $this->candidatosDocx->parrafosVista($resultado['documento'], $mapa, $resultado['instancias']);
    }

    /**
     * Reglas para la VISTA del editor (todas las anclas, con nombre propio
     * por espacio), en el orden del mapa.
     *
     * @param  Collection<int, DocumentTemplateField>  $campos
     * @return list<array<string, mixed>>
     */
    private function reglasVista(Collection $campos): array
    {
        $reglas = [];

        foreach ($campos as $campo) {
            if (is_array($campo->selector) && $campo->selector !== []) {
                $reglas[] = [...$campo->selector, 'campo' => $this->campoVista($campo->field_key), 'opcional' => true];
            }
        }

        return $reglas;
    }

    private function campoVista(string $fieldKey): string
    {
        return 've_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($fieldKey));
    }

    /**
     * @param  list<array{clave: string, etiqueta: string, confianza: float}>  $sugerencias
     * @param  array<string, mixed>|null  $ubicacion
     */
    private function estadoInicial(ZonaCampoDocumento $zona, array $sugerencias, ?array $ubicacion): EstadoCampoDocumento
    {
        if ($ubicacion === null) {
            return EstadoCampoDocumento::SinAsignar;
        }

        if ($zona !== ZonaCampoDocumento::Dato) {
            // Firma reconocida por el texto: se propone, RH la confirma.
            return EstadoCampoDocumento::Sugerido;
        }

        return $sugerencias === [] ? EstadoCampoDocumento::SinAsignar : EstadoCampoDocumento::Sugerido;
    }

    /**
     * @param  Collection<int, DocumentTemplateField>  $existentes
     */
    private function siguienteNumero(Collection $existentes): int
    {
        $maximo = 0;

        foreach ($existentes as $campo) {
            if (preg_match('/^c(\d+)$/', $campo->field_key, $m) === 1) {
                $maximo = max($maximo, (int) $m[1]);
            }
        }

        return $maximo + 1;
    }

    /**
     * @param  array{page: int, x: float, y: float, width: float, height: float}  $caja
     * @param  Collection<int, DocumentTemplateField>  $existentes
     */
    private function ocupado(array $caja, Collection $existentes): bool
    {
        foreach ($existentes as $campo) {
            if ($campo->page === $caja['page'] && $campo->y !== null && $campo->x !== null && abs($campo->y - $caja['y']) < 3
                && $campo->x < $caja['x'] + $caja['width'] && $campo->x + (float) $campo->width > $caja['x']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $campo
     * @param  list<string>  $errores
     * @return array<string, mixed>
     */
    private function geometria(array $campo, int $n, array &$errores): array
    {
        $numero = fn (string $llave, float $min, float $max, ?float $defecto = null): ?float => isset($campo[$llave]) && is_numeric($campo[$llave])
            ? max($min, min($max, round((float) $campo[$llave], 2)))
            : $defecto;

        $pagina = isset($campo['page']) && is_numeric($campo['page']) ? (int) $campo['page'] : null;

        if ($pagina === null || $pagina < 1 || ! isset($campo['x'], $campo['y'])) {
            $errores[] = "Espacio {$n}: falta su posición en la página.";
        }

        $fondo = isset($campo['background_color']) && is_string($campo['background_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $campo['background_color']) === 1 ? strtoupper($campo['background_color']) : null;

        return [
            'page' => $pagina,
            'x' => $numero('x', 0, 600),
            'y' => $numero('y', 0, 600),
            'width' => $numero('width', 2, 600, 60),
            'height' => $numero('height', 2, 300, 5),
            'font_size' => $numero('font_size', 4, 36, 9),
            'font_size_min' => $numero('font_size_min', 4, 36),
            'font_weight' => ($campo['font_weight'] ?? null) === 'bold' ? 'bold' : null,
            'text_align' => in_array($campo['text_align'] ?? null, ['L', 'C', 'R'], true) ? $campo['text_align'] : null,
            'line_height' => $numero('line_height', 2, 30),
            'multiline' => (bool) ($campo['multiline'] ?? false),
            'background_color' => $fondo,
        ];
    }

    /**
     * Espacios por el nombre con que el motor los llena (varios espacios
     * pueden compartir dato: se toma el primero).
     *
     * @return array<string, DocumentTemplateField>
     */
    private function camposPorNombreMotor(DocumentTemplate $master): array
    {
        $porCampo = [];

        foreach ($master->campos()->get() as $campo) {
            $porCampo[$campo->campoMotor()] ??= $campo;
        }

        return $porCampo;
    }

    /**
     * @param  array<string, DocumentTemplateField>  $porCampo
     * @return list<array{field_key: string|null, etiqueta: string, razon: string}>
     */
    private function sospechososDocx(DocumentTemplate $master, array $porCampo): array
    {
        $instancias = Docx\RellenadorDocx::instanciasDe($master->mapping);
        $campos = array_values(array_unique(array_map(fn (array $i): string => $i['campo'], $instancias)));
        $valores = $this->valoresPrueba->valoresDe((array) ($master->mapping ?? []), $campos);
        $excesos = [];

        foreach ($instancias as $instancia) {
            $exceso = mb_strlen((string) ($valores[$instancia['campo']] ?? '')) - mb_strlen($instancia['original']);

            if ($exceso > 8) {
                $excesos[$instancia['campo']] = max($excesos[$instancia['campo']] ?? 0, $exceso);
            }
        }

        arsort($excesos);
        $resultado = [];

        foreach (array_slice($excesos, 0, 3, true) as $campo => $exceso) {
            $espacio = $porCampo[(string) $campo] ?? null;
            $resultado[] = ['field_key' => $espacio?->field_key, 'etiqueta' => $espacio->label ?? (string) $campo, 'razon' => sprintf('%d caracteres más que el espacio del formato.', $exceso)];
        }

        return $resultado;
    }

    private function pdfDatosLargos(DocumentTemplate $master): ?string
    {
        try {
            $mapping = (array) ($master->mapping ?? []);
            $cajas = array_values(array_filter((array) ($mapping['campos'] ?? []), 'is_array'));
            $nombres = array_values(array_unique(array_map(fn (array $c): string => (string) $c['campo'], $cajas)));
            $pdf = $this->overlay->renderizar(
                $this->almacen->master($master),
                $cajas,
                $this->valoresPrueba->valoresDe($mapping, $nombres),
                array_values(array_map('intval', (array) ($mapping['paginas'] ?? []))),
                array_values(array_map('floatval', (array) ($mapping['copias_offset_y'] ?? [0.0]))),
                'PRUEBA CON DATOS LARGOS — NO VÁLIDA',
            );
            $token = (string) Str::uuid();
            $this->almacen->disco()->put(sprintf('%s/pruebas/%d-%s.pdf', config('documentos_maestros.carpeta', 'documentos-maestros'), $master->id, $token), $pdf);

            return route('rh.documentos-maestros.prueba', [$master, $token]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{numero: int, ancho: float, alto: float}>
     */
    private function paginas(DocumentTemplate $master): array
    {
        return array_values(array_map(
            fn (array $p): array => ['numero' => (int) ($p['numero'] ?? 1), 'ancho' => (float) ($p['ancho'] ?? 215.9), 'alto' => (float) ($p['alto'] ?? 279.4)],
            array_filter((array) (($master->analisis ?? [])['paginas'] ?? []), 'is_array'),
        ));
    }

    private function familiaLibre(string $familia): string
    {
        $candidata = $familia;
        $n = 2;

        while (DocumentTemplate::withTrashed()->where('familia', $candidata)->exists() || $this->catalogo->definicionLegado($candidata) !== null) {
            $candidata = sprintf('%s_%d', $familia, $n++);
        }

        return $candidata;
    }

    private function categoria(string $proceso): CategoriaDocumento
    {
        return match ($proceso) {
            'alta', 'renovacion' => CategoriaDocumento::Contratos,
            'permiso' => CategoriaDocumento::Permisos,
            'prestamo' => CategoriaDocumento::Prestamos,
            'baja', 'negativa_firma' => CategoriaDocumento::BajaFiniquito,
            default => CategoriaDocumento::Otros,
        };
    }
}
