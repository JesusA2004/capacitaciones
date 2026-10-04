<?php

namespace App\Services\DocumentosLaborales;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumentoGenerado;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\MotorPlantilla;
use App\Exceptions\DatosDocumentoFaltantesException;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosMaestros\AlmacenMaestrosService;
use App\Services\DocumentosMaestros\ContextoDocumento;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use App\Services\DocumentosMaestros\Docx\RellenadorDocx;
use App\Services\DocumentosMaestros\Pdf\RenderizadorOverlayMaestro;
use App\Services\DocumentosMaestros\ResolvedorMaestroService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\FormatoPreviewService;
use App\Services\Formatos\GeneradorFormatoService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use App\Services\Formatos\Variables\ContextoFormato;
use App\Services\Plantillas\PlaceholderResolver;
use App\Services\Plantillas\PlantillaDocumentoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Motor general de documentos laborales. Un solo camino para TODOS los
 * formatos (contratos, pagaré, finiquito, actas, comprobantes, recibos...):
 *
 *   plantilla (DocumentTemplate: clave + versión + motor + banderas)
 *   + variables del colaborador (PlaceholderResolver) + extra del contexto
 *   → PDF (HTML→DomPDF, DOCX→PhpWord→PDF, o overlay sobre formato oficial)
 *   → archivo en el expediente del colaborador (carpeta de su categoría en el NAS)
 *   → GeneratedDocument con SNAPSHOT del payload usado + checksum + flujo.
 *
 * El snapshot es lo que hace que un contrato histórico nunca cambie: si
 * después cambia el sueldo del colaborador, el documento emitido conserva
 * el payload y el PDF con el que se generó.
 *
 * El sistema nunca redacta cláusulas: el texto lo aporta RH/Jurídico en la
 * plantilla. Si no hay plantilla activa para una clave, se lanza una
 * ValidationException explícita (no se inventa un formato).
 */
class MotorDocumentalService
{
    public function __construct(
        private readonly PlaceholderResolver $resolver,
        private readonly PlantillaDocumentoService $docx,
        private readonly FormatoPreviewService $convertidor,
        private readonly GeneradorFormatoService $formatosOficiales,
        private readonly DocumentoStorageService $expediente,
        private readonly FlujoDocumentalService $flujo,
        private readonly AuditoriaService $auditoria,
        private readonly ResolvedorMaestroService $maestros,
        private readonly DatosDocumentoService $datos,
        private readonly RellenadorDocx $rellenador,
        private readonly RenderizadorOverlayMaestro $overlay,
        private readonly AlmacenMaestrosService $almacen,
        private readonly ConversorDocxPdf $conversor,
    ) {}

    /**
     * Versión activa más reciente de una plantilla por clave.
     */
    public function plantillaActiva(string $clave): ?DocumentTemplate
    {
        // Solo plantillas heredadas: los documentos maestros (por variante
        // de puesto) se eligen con ResolvedorMaestroService.
        return DocumentTemplate::query()
            ->where('clave', $clave)
            ->whereNull('estado_master')
            ->where('activo', true)
            ->orderByDesc('version')
            ->first();
    }

    public function tienePlantillaActiva(string $clave, ?Colaborador $colaborador = null): bool
    {
        if ($colaborador !== null && $this->maestros->esClaveMaestra($clave)) {
            return $this->maestros->buscar($clave, $colaborador) !== null;
        }

        return $this->plantillaActiva($clave) !== null
            || DocumentTemplate::query()->where('clave', $clave)->where('estado_master', 'listo')->where('activo', true)->exists();
    }

    /**
     * Catálogo de variables disponibles para las plantillas ({{variable}}).
     *
     * @return list<string>
     */
    public function variablesDisponibles(): array
    {
        return array_values(array_unique([
            ...array_keys($this->resolver->resolver(null)),
            'referencia_documento',
        ]));
    }

    /**
     * Genera y persiste un documento laboral para el colaborador.
     *
     * @param  array<string, mixed>  $extra  Variables del contexto (fechas del contrato, montos del préstamo, datos del acta...).
     *
     * @throws ValidationException Si no hay plantilla activa o el render/almacenamiento falla.
     */
    public function generar(
        Colaborador $colaborador,
        DocumentTemplate|string $plantilla,
        User $actor,
        array $extra = [],
        ?Model $documentable = null,
        ?string $titulo = null,
    ): GeneratedDocument {
        $plantilla = $this->resolverPlantilla($plantilla, $colaborador);

        if ($plantilla->estado_master !== null) {
            return $this->generarDesdeMaestro($plantilla, $this->contextoDesde($colaborador, $documentable, $actor, $extra), $actor, $documentable, $titulo);
        }

        $colaborador->loadMissing(['sucursalPrincipal.empresa', 'puesto', 'departamento', 'jefe.jefe', 'gerente', 'user']);

        $referencia = strtoupper(Str::random(10));
        $payload = $this->resolver->resolver($colaborador, [...$extra, 'referencia_documento' => $referencia]);
        $titulo ??= $plantilla->nombre;

        $pdf = $this->renderizar($plantilla, $payload, $titulo, $referencia, $colaborador, $documentable, $actor);

        return $this->registrarPdf($colaborador, $pdf, $titulo, $actor, [
            'plantilla' => $plantilla,
            'clave' => $plantilla->clave,
            'version' => $plantilla->version,
            'categoria' => $this->categoriaDe($plantilla),
            'payload' => $payload,
            'documentable' => $documentable,
            'requiere_firma_digital' => $plantilla->requiere_firma_digital,
            'requiere_impresion' => $plantilla->requiere_impresion,
            'requiere_firma_fisica' => $plantilla->requiere_firma_fisica,
            'requiere_huella' => $plantilla->requiere_huella,
            'requiere_testigos' => $plantilla->requiere_testigos,
        ]);
    }

    /**
     * Persiste un PDF ya renderizado como documento laboral del colaborador:
     * archivo en la carpeta de su categoría en el expediente (NAS) +
     * GeneratedDocument con snapshot, checksum y flujo documental. Lo usan
     * generar() y los módulos cuyo PDF se arma con una vista propia no
     * jurídica (recibo interno de nómina, comprobantes, respaldo de finiquito).
     *
     * @param  array{plantilla?: DocumentTemplate|null, clave?: string|null, version?: int|null, categoria: CategoriaDocumento, payload?: array<string, string>, documentable?: Model|null, requiere_firma_digital?: bool, requiere_impresion?: bool, requiere_firma_fisica?: bool, requiere_huella?: bool, requiere_testigos?: bool, requiere_envio_corporativo?: bool, master_familia?: string|null, master_hash?: string|null, proceso?: string|null, fidelidad?: string|null, docx?: string|null, nombre_archivo?: string|null}  $opciones
     */
    public function registrarPdf(Colaborador $colaborador, string $pdf, string $titulo, User $actor, array $opciones): GeneratedDocument
    {
        $colaborador->loadMissing(['sucursalPrincipal.empresa', 'user']);
        $plantilla = $opciones['plantilla'] ?? null;
        $documentable = $opciones['documentable'] ?? null;
        $categoria = $opciones['categoria'];
        $payload = $opciones['payload'] ?? [];
        $nombreArchivo = $opciones['nombre_archivo'] ?? sprintf('%s - %s.pdf', $titulo, now()->format('Y-m-d His'));

        try {
            $ruta = $this->expediente->guardarContenidoEnExpediente($colaborador, $categoria, $nombreArchivo, $pdf);
            $rutaDocx = isset($opciones['docx']) && $opciones['docx'] !== ''
                ? $this->expediente->guardarContenidoEnExpediente($colaborador, $categoria, (string) preg_replace('/\.pdf$/i', '.docx', $nombreArchivo), $opciones['docx'])
                : null;
        } catch (Throwable $e) {
            Log::error('MotorDocumentalService: no se pudo guardar el PDF en el almacenamiento.', [
                'colaborador_id' => $colaborador->id,
                'clave' => $opciones['clave'] ?? null,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'documento' => 'No se pudo guardar el documento en el almacenamiento (NAS no disponible). Intenta de nuevo; si continúa, avisa a sistemas.',
            ]);
        }

        try {
            $documento = DB::transaction(function () use ($colaborador, $plantilla, $actor, $documentable, $titulo, $payload, $pdf, $categoria, $ruta, $rutaDocx, $nombreArchivo, $opciones): GeneratedDocument {
                $documento = GeneratedDocument::query()->create([
                    'document_template_id' => $plantilla?->id,
                    'user_id' => $colaborador->user?->id,
                    'colaborador_id' => $colaborador->id,
                    'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
                    'sucursal_id' => $colaborador->sucursal_principal_id,
                    'disk' => config('expedientes.disk'),
                    'path' => $ruta,
                    'original_name' => $nombreArchivo,
                    'generated_name' => basename($ruta),
                    'mime' => 'application/pdf',
                    'size' => strlen($pdf),
                    'status' => EstadoDocumentoGenerado::Generado,
                    'generated_by' => $actor->id,
                    'documentable_type' => $documentable?->getMorphClass(),
                    'documentable_id' => $documentable?->getKey(),
                    'clave_plantilla' => $opciones['clave'] ?? null,
                    'version_plantilla' => $opciones['version'] ?? null,
                    'categoria' => $categoria,
                    'titulo' => $titulo,
                    'payload' => $payload,
                    'checksum' => hash('sha256', $pdf),
                    'estado_flujo' => EstadoFlujoDocumento::Generado,
                    'requiere_firma_digital' => $opciones['requiere_firma_digital'] ?? false,
                    'requiere_impresion' => $opciones['requiere_impresion'] ?? false,
                    'requiere_firma_fisica' => $opciones['requiere_firma_fisica'] ?? false,
                    'requiere_huella' => $opciones['requiere_huella'] ?? false,
                    'requiere_testigos' => $opciones['requiere_testigos'] ?? false,
                    'requiere_envio_corporativo' => $opciones['requiere_envio_corporativo'] ?? false,
                    'master_familia' => $opciones['master_familia'] ?? null,
                    'master_hash' => $opciones['master_hash'] ?? null,
                    'proceso' => $opciones['proceso'] ?? null,
                    'fidelidad' => $opciones['fidelidad'] ?? null,
                    'docx_path' => $rutaDocx,
                ]);

                $this->flujo->iniciar($documento, $actor);

                return $documento;
            });
        } catch (Throwable $e) {
            // La fila no llegó a existir: el PDF recién escrito no debe
            // quedar huérfano en el NAS.
            $this->expediente->eliminar($ruta);

            if ($rutaDocx !== null) {
                $this->expediente->eliminar($rutaDocx);
            }

            throw $e;
        }

        $this->auditoria->registrar('documento_generado', $documento, $actor, [
            'clave_plantilla' => $opciones['clave'] ?? null,
            'version_plantilla' => $opciones['version'] ?? null,
            'master_familia' => $opciones['master_familia'] ?? null,
            'master_hash' => $opciones['master_hash'] ?? null,
            'proceso' => $opciones['proceso'] ?? null,
            'colaborador_id' => $colaborador->id,
            'checksum' => $documento->checksum,
        ]);

        return $documento->refresh();
    }

    /**
     * Vista previa sin persistir nada (mismo render que generar()).
     *
     * @param  array<string, mixed>  $extra
     */
    public function previsualizar(Colaborador $colaborador, DocumentTemplate|string $plantilla, array $extra = []): string
    {
        $plantilla = $this->resolverPlantilla($plantilla);
        $payload = $this->resolver->resolver($colaborador, [...$extra, 'referencia_documento' => 'VISTA-PREVIA']);

        return $this->renderizar($plantilla, $payload, $plantilla->nombre, 'VISTA-PREVIA', $colaborador);
    }

    /**
     * @param  array<string, string>  $payload
     *
     * @throws ValidationException Si la plantilla no puede renderizarse.
     */
    public function renderizar(DocumentTemplate $plantilla, array $payload, string $titulo, string $referencia, ?Colaborador $colaborador = null, ?Model $documentable = null, ?User $actor = null): string
    {
        try {
            $pdf = match ($plantilla->motor) {
                MotorPlantilla::Html => $this->renderizarHtml($plantilla, $payload, $titulo, $referencia),
                MotorPlantilla::Docx => $this->convertidor->aPdf($this->docx->generarConValores($plantilla, $payload)),
                MotorPlantilla::PdfOverlay => $this->renderizarOverlay($plantilla, $payload, $colaborador, $documentable, $actor, $referencia),
            };
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('MotorDocumentalService: fallo al renderizar la plantilla.', ['plantilla_id' => $plantilla->id, 'error' => $e->getMessage()]);
            $pdf = null;
        }

        if ($pdf === null || $pdf === '') {
            throw ValidationException::withMessages([
                'plantilla' => "No se pudo generar el PDF con la plantilla «{$plantilla->nombre}». Revisa que su contenido/archivo sea válido.",
            ]);
        }

        return $pdf;
    }

    /**
     * Genera la instancia de un DOCUMENTO MAESTRO para una persona: mismos
     * diseño, cláusulas y firmas del original; solo cambian los datos
     * variables, que salen de PEOPLE (snapshot) — nunca se genera con huecos.
     *
     * @throws DatosDocumentoFaltantesException Si faltan datos requeridos (422 DATOS_FALTANTES).
     */
    public function generarDesdeMaestro(DocumentTemplate $master, ContextoDocumento $contexto, User $actor, ?Model $documentable = null, ?string $titulo = null, ?string $proceso = null): GeneratedDocument
    {
        $colaborador = $contexto->colaborador;
        $valores = $this->datos->resolver($contexto);
        $this->exigirCompletos($master, $valores);
        $render = $this->renderizarMaestro($master, $valores);
        $titulo ??= $master->nombre;
        $campos = $this->camposDelMaestro($master);

        return $this->registrarPdf($colaborador, $render['pdf'], $titulo, $actor, [
            'plantilla' => $master,
            'clave' => $master->clave,
            'version' => $master->version,
            'categoria' => $this->categoriaDe($master),
            // Snapshot: SOLO los datos que el documento usó.
            'payload' => array_intersect_key($valores, array_flip($campos)),
            'documentable' => $documentable,
            'requiere_firma_digital' => false,
            'requiere_impresion' => $master->requiere_impresion,
            'requiere_firma_fisica' => $master->requiere_firma_fisica,
            'requiere_huella' => $master->requiere_huella,
            'requiere_testigos' => $master->requiere_testigos,
            'requiere_envio_corporativo' => $master->requiere_envio_corporativo,
            'master_familia' => $master->familia,
            'master_hash' => $master->master_hash,
            'proceso' => $proceso ?? $master->proceso,
            'fidelidad' => $render['fidelidad'],
            'docx' => $render['docx'],
            'nombre_archivo' => $this->nombreArchivo($colaborador, $titulo, $contexto->fechaDocumento()),
        ]);
    }

    /**
     * QA administrativo ("Probar con colaborador"): mismo render que
     * generarDesdeMaestro() pero sin persistir y marcando los datos que
     * falten como «[FALTA: …]» para detectarlos a simple vista.
     *
     * @return array{pdf: string, fidelidad: string, faltantes: list<array<string, mixed>>, conversor: string}
     */
    public function previsualizarMaestro(DocumentTemplate $master, ContextoDocumento $contexto): array
    {
        $valores = $this->datos->resolver($contexto);
        $faltantes = $this->faltantesDe($master, $valores);

        foreach ($faltantes as $faltante) {
            $valores[$faltante['campo']] = sprintf('[FALTA: %s]', $faltante['etiqueta']);
        }

        $render = $this->renderizarMaestro($master, $valores, 'VISTA PREVIA — NO VÁLIDA PARA FIRMA');

        return ['pdf' => $render['pdf'], 'fidelidad' => $render['fidelidad'], 'faltantes' => $faltantes, 'conversor' => $render['conversor']];
    }

    /**
     * Datos requeridos por el master que la persona/proceso no tiene.
     *
     * @param  array<string, string>  $valores
     * @return list<array{campo: string, base: string, fuente: string, columna: string, etiqueta: string, tipo: string, editable: bool}>
     */
    public function faltantesDe(DocumentTemplate $master, array $valores): array
    {
        $mapping = (array) ($master->mapping ?? []);
        $requeridos = [];

        if (($mapping['motor'] ?? 'docx') === 'pdf_overlay') {
            $requeridos = array_map('strval', (array) ($mapping['requeridos'] ?? []));
        } else {
            foreach ((array) ($mapping['instancias'] ?? []) as $instancia) {
                if (is_array($instancia) && ! ($instancia['opcional'] ?? false)) {
                    $requeridos[] = (string) $instancia['campo'];
                }
            }
        }

        $faltantes = [];
        $bases = [];

        foreach (array_unique($requeridos) as $campo) {
            if (trim((string) ($valores[$campo] ?? '')) !== '') {
                continue;
            }

            $fuente = $this->datos->fuente($campo);

            // Un solo renglón por dato base (nombre / nombre en mayúsculas…).
            if (isset($bases[$fuente['base']])) {
                continue;
            }

            $bases[$fuente['base']] = true;
            $faltantes[] = [...$fuente, 'editable' => in_array($fuente['fuente'], ['colaborador', 'sucursal', 'manual'], true)];
        }

        return $faltantes;
    }

    /**
     * Contexto del documento a partir del registro de negocio que lo
     * origina (contrato, cierre, evaluación, solicitud o préstamo).
     *
     * @param  array<string, mixed>  $extra  Datos del acto (testigos, fecha_documento Y-m-d…).
     */
    public function contextoDesde(Colaborador $colaborador, ?Model $documentable, ?User $actor, array $extra = []): ContextoDocumento
    {
        $contrato = $documentable instanceof ContratoLaboral ? $documentable : null;
        $cierre = $documentable instanceof CierreLaboral ? $documentable : null;
        $evaluacion = $documentable instanceof EvaluacionPeriodoPrueba ? $documentable : $cierre?->evaluacion;
        $solicitud = $documentable instanceof SolicitudInterna ? $documentable : null;
        $prestamo = $documentable instanceof Prestamo ? $documentable : null;
        $contrato ??= $evaluacion->contrato ?? ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->orderByDesc('fecha_inicio')->first();
        $fecha = isset($extra['fecha_documento']) && is_string($extra['fecha_documento']) ? CarbonImmutable::parse($extra['fecha_documento'], 'America/Mexico_City') : null;
        $manuales = [];

        foreach ($extra as $clave => $valor) {
            if (is_scalar($valor) && $clave !== 'fecha_documento') {
                $manuales[(string) $clave] = (string) $valor;
            }
        }

        return new ContextoDocumento($colaborador, $contrato, $cierre, $evaluacion, $solicitud, $prestamo, $actor, $manuales, $fecha);
    }

    /**
     * @param  array<string, string>  $valores
     */
    private function exigirCompletos(DocumentTemplate $master, array $valores): void
    {
        $definicion = config('documentos_maestros.documentos', [])[(string) $master->familia] ?? [];
        $soloResultado = is_array($definicion) ? ($definicion['solo_resultado'] ?? null) : null;

        if ($soloResultado === 'no_acredita' && ($valores['resultado_acredita'] ?? '') === '☒') {
            throw ValidationException::withMessages([
                'documento' => sprintf('«%s» solo contempla la determinación de NO acreditación. Con resultado ACREDITA no se genera (Jurídico debe entregar la variante si se requiere).', $master->nombre),
            ]);
        }

        $faltantes = $this->faltantesDe($master, $valores);

        if ($faltantes !== []) {
            throw new DatosDocumentoFaltantesException($master->nombre, $faltantes);
        }
    }

    /**
     * @param  array<string, string>  $valores
     * @return array{pdf: string, fidelidad: string, docx: string|null, conversor: string}
     */
    private function renderizarMaestro(DocumentTemplate $master, array $valores, ?string $leyenda = null): array
    {
        $mapping = (array) ($master->mapping ?? []);

        try {
            if (($mapping['motor'] ?? 'docx') === 'pdf_overlay') {
                $pdf = $this->overlay->renderizar(
                    $this->almacen->master($master),
                    array_values(array_filter((array) ($mapping['campos'] ?? []), 'is_array')),
                    $valores,
                    array_values(array_map('intval', (array) ($mapping['paginas'] ?? []))),
                    array_values(array_map('floatval', (array) ($mapping['copias_offset_y'] ?? [0.0]))),
                    $leyenda,
                );

                return ['pdf' => $pdf, 'fidelidad' => 'exacta', 'docx' => null, 'conversor' => 'overlay'];
            }

            $instancias = [];

            foreach ((array) ($mapping['instancias'] ?? []) as $n => $instancia) {
                if (is_array($instancia)) {
                    $instancias[(int) $n] = ['campo' => (string) ($instancia['campo'] ?? ''), 'original' => (string) ($instancia['original'] ?? ''), 'opcional' => (bool) ($instancia['opcional'] ?? false)];
                }
            }

            $docx = $this->rellenador->rellenar($this->almacen->master($master), $instancias, $valores)['docx'];
            $convertido = $this->conversor->convertir($docx);
        } catch (Throwable $e) {
            Log::warning('MotorDocumentalService: fallo al generar desde el documento maestro.', ['master_id' => $master->id, 'familia' => $master->familia, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['documento' => sprintf('No se pudo generar «%s». Intenta de nuevo; si continúa, avisa a sistemas.', $master->nombre)]);
        }

        if ($convertido === null) {
            throw ValidationException::withMessages(['documento' => sprintf('No se pudo convertir «%s» a PDF (no hay conversor disponible).', $master->nombre)]);
        }

        return ['pdf' => $convertido['pdf'], 'fidelidad' => $convertido['fidelidad'], 'docx' => $docx, 'conversor' => $convertido['conversor']];
    }

    /**
     * @return list<string>
     */
    private function camposDelMaestro(DocumentTemplate $master): array
    {
        $mapping = (array) ($master->mapping ?? []);

        if (($mapping['motor'] ?? 'docx') === 'pdf_overlay') {
            return array_values(array_unique(array_map(fn (mixed $c): string => is_array($c) ? (string) ($c['campo'] ?? '') : '', (array) ($mapping['campos'] ?? []))));
        }

        return array_values(array_unique(array_map(fn (mixed $i): string => is_array($i) ? (string) ($i['campo'] ?? '') : '', (array) ($mapping['instancias'] ?? []))));
    }

    /**
     * EMP-0123_Juan_Perez_Contrato_de_capacitacion_inicial_2026-10-04.pdf
     */
    private function nombreArchivo(Colaborador $colaborador, string $titulo, CarbonImmutable $fecha): string
    {
        $limpiar = fn (string $texto): string => trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($texto)), '_');
        $titulo = (string) preg_replace('/\s+—\s+.*$/u', '', $titulo);

        return sprintf(
            '%s%s_%s_%s.pdf',
            $colaborador->numero_empleado ? $limpiar((string) $colaborador->numero_empleado).'_' : '',
            $limpiar($colaborador->nombreCompleto()),
            Str::limit($limpiar($titulo), 60, ''),
            $fecha->format('Y-m-d'),
        );
    }

    public function disco(GeneratedDocument $documento): Filesystem
    {
        return Storage::disk($documento->disk);
    }

    public function contenido(GeneratedDocument $documento): string
    {
        $contenido = $this->disco($documento)->get($documento->path);

        if ($contenido === null) {
            throw new RuntimeException('El archivo del documento no está disponible en el almacenamiento.');
        }

        return $contenido;
    }

    /**
     * Descarga/visualización privada: siempre por streaming a través del
     * backend (la autorización la hace la Policy antes de llegar aquí);
     * nunca una URL pública al NAS.
     */
    public function respuesta(GeneratedDocument $documento, bool $inline = true): StreamedResponse
    {
        $disco = $this->disco($documento);

        abort_unless($disco->exists($documento->path), 404, 'El archivo del documento no está disponible.');

        // Auditoría de descargas (quién y cuándo); un fallo aquí no impide descargar.
        try {
            $documento->forceFill(['descargas' => $documento->descargas + 1, 'ultima_descarga_en' => now()])->saveQuietly();
            $this->auditoria->registrar('documento_descargado', $documento, auth()->user() instanceof User ? auth()->user() : null, ['colaborador_id' => $documento->colaborador_id, 'clave_plantilla' => $documento->clave_plantilla]);
        } catch (Throwable $e) {
            Log::warning('MotorDocumentalService: no se pudo registrar la descarga.', ['documento_id' => $documento->id, 'error' => $e->getMessage()]);
        }

        $nombre = str_replace(['"', '\\', '/'], '', $documento->original_name);

        return $disco->response($documento->path, $nombre, [
            'Content-Type' => $documento->mime ?? 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$nombre.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function categoriaDe(DocumentTemplate $plantilla): CategoriaDocumento
    {
        if ($plantilla->categoria !== null) {
            return $plantilla->categoria;
        }

        $configurada = config("contratos.plantillas.{$plantilla->clave}.categoria");

        return is_string($configurada) ? (CategoriaDocumento::tryFrom($configurada) ?? CategoriaDocumento::Otros) : CategoriaDocumento::Otros;
    }

    private function resolverPlantilla(DocumentTemplate|string $plantilla, ?Colaborador $colaborador = null): DocumentTemplate
    {
        if ($plantilla instanceof DocumentTemplate) {
            if (! $plantilla->activo) {
                throw ValidationException::withMessages(['plantilla' => "La plantilla «{$plantilla->nombre}» está inactiva."]);
            }

            return $plantilla;
        }

        // Clave manejada por documentos maestros: variante correcta para la
        // persona (puesto/grupo/empresa) o 422 DOCUMENT_TEMPLATE_MISSING.
        if ($colaborador !== null && $this->maestros->esClaveMaestra($plantilla)) {
            return $this->maestros->resolver($plantilla, $colaborador);
        }

        $activa = $this->plantillaActiva($plantilla);

        if ($activa === null) {
            $nombre = config("contratos.plantillas.{$plantilla}.nombre", $plantilla);

            throw ValidationException::withMessages([
                'plantilla' => sprintf('No hay una plantilla activa para «%s» (clave %s). RH/Jurídico debe cargar el formato antes de generarlo.', is_string($nombre) ? $nombre : $plantilla, $plantilla),
            ]);
        }

        return $activa;
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function renderizarHtml(DocumentTemplate $plantilla, array $payload, string $titulo, string $referencia): string
    {
        $cuerpo = (string) $plantilla->contenido_html;

        // El HTML de la plantilla lo captura RH; se retiran scripts/iframes
        // por higiene aunque DomPDF no los ejecute.
        $cuerpo = (string) preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $cuerpo);

        $cuerpo = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $m): string => e($payload[$m[1]] ?? ''),
            $cuerpo,
        );

        return Pdf::loadView('pdf.documento-laboral', [
            'cuerpo' => $cuerpo,
            'titulo' => $titulo,
            'folio' => $referencia,
            'clave' => $plantilla->clave,
            'version' => $plantilla->version,
            'generadoEn' => now()->format('d/m/Y H:i'),
        ])->setPaper('letter', 'portrait')->output();
    }

    /**
     * Formato oficial (plantilla versionada): lo genera el motor de
     * plantillas oficiales con el contexto del documento de origen
     * (contrato, préstamo, finiquito…); las variables de `extra` que no
     * están en el catálogo llenan los campos manuales del mismo nombre.
     *
     * @param  array<string, string>  $payload
     */
    private function renderizarOverlay(DocumentTemplate $plantilla, array $payload, ?Colaborador $colaborador, ?Model $documentable, ?User $actor, string $referencia): string
    {
        $formato = $plantilla->formatoOficial;

        if ($formato === null || ! $formato->tieneConfiguracion()) {
            throw ValidationException::withMessages([
                'plantilla' => "La plantilla «{$plantilla->nombre}» no tiene un formato oficial configurado.",
            ]);
        }

        $contexto = $colaborador !== null
            ? $this->formatosOficiales->contextoDesde($colaborador, $documentable, $actor, $referencia)
            : new ContextoFormato(null, referencia: $referencia);

        return $this->formatosOficiales->renderizarPara($formato, $contexto, $payload);
    }
}
