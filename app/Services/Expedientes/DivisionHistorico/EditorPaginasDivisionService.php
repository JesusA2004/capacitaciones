<?php

namespace App\Services\Expedientes\DivisionHistorico;

use App\Enums\AccionDocumentoReorganizado;
use App\Enums\EstadoDivisionHistorico;
use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\DivisionExpedienteHistorico;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\ParteDivisionHistorico;
use App\Models\RevisionDivisionHistorico;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Editor de hojas del PDF histórico (docs/DIVISION_EXPEDIENTE_HISTORICO.md,
 * «Corregir hojas a mano»). RH arma, hoja por hoja, qué documentos salen
 * del PDF grandote: asigna tipo por hoja, une hojas a un documento ya
 * clasificado («continúa el documento anterior»), parte un bloque, mueve y
 * reordena hojas. El editor trabaja en el navegador; aquí solo llegan:
 *
 *  - vistaPrevia(): valida el plan y dice qué pasaría — NO escribe nada.
 *  - pdfVistaPrevia(): el PDF de un documento resultante, en memoria.
 *  - confirmar(): regenera los PDFs de los documentos que cambiaron, deja
 *    sin efecto (nunca borra) las partes anteriores, crea una revisión
 *    versionada con la auditoría hoja por hoja y actualiza el expediente.
 *    Idempotente: el mismo plan dos veces no crea nada la segunda.
 *
 * Las hojas corregidas quedan marcadas `manual` en la división: volver a
 * dividir con el OCR las respeta (DivisionExpedienteHistoricoService).
 *
 * @phpstan-type DocumentoPlan array{document_type_id: int|null, paginas: list<int>}
 * @phpstan-type Cambio array{pagina: int, de: string, a: string}
 * @phpstan-type Advertencia array{nivel: string, mensaje: string}
 * @phpstan-type DocumentoResultado array{orden: int, document_type_id: int|null, tipo: string|null, paginas: list<int>, rango: string, total_paginas: int, accion: AccionDocumentoReorganizado, parte_id: int|null}
 * @phpstan-type Previa array{plan: list<DocumentoPlan>, hash_plan: string, documentos: list<DocumentoResultado>, cambios: list<Cambio>, advertencias: list<Advertencia>, faltantes: list<string>, archivar: list<int>, sin_cambios: bool, revision_actual: int}
 */
class EditorPaginasDivisionService
{
    /** Estados de un documento vigente que todavía no aprobó RH: si pierde todas sus hojas, se archiva. */
    private const ESTADOS_ARCHIVABLES = [EstadoDocumento::EnRevision, EstadoDocumento::Cargado];

    public function __construct(
        private readonly DivisionExpedienteHistoricoService $divisiones,
        private readonly CortadorPdf $cortador,
        private readonly ExpedienteService $expedientes,
        private readonly AuditoriaService $auditoria,
        private readonly DocumentoStorageService $storage,
    ) {}

    /**
     * Props de la pantalla del editor.
     *
     * @return array<string, mixed>
     */
    public function estado(DivisionExpedienteHistorico $division): array
    {
        $this->exigirEditable($division);
        $division->loadMissing(['historico', 'revisiones.autor:id,name,apellidos', 'partesVigentes.documento']);
        $tipos = $this->divisiones->tiposChecklist();
        $nombresPorClave = $tipos->mapWithKeys(fn (DocumentType $t): array => [$t->clave => $t->nombre])->all();

        return [
            'division' => [
                'id' => $division->id,
                'total_paginas' => $division->total_paginas,
                'creada_en' => $division->created_at?->toIso8601String(),
                'revision_actual' => $this->versionActual($division),
                'original_url' => route('rh.expedientes.historico.ver', $division->historico),
                'nombre_archivo' => $division->historico->stored_name,
            ],
            'paginas' => array_map(fn (array $p): array => [
                'pagina' => (int) ($p['pagina'] ?? 0),
                'tipo_ocr' => ($clave = array_key_exists('tipo_ocr', $p) ? $p['tipo_ocr'] : ($p['tipo'] ?? null)) !== null ? ($nombresPorClave[$clave] ?? (string) $clave) : null,
                'confianza' => $p['confianza'] ?? null,
                'metodo' => $p['metodo'] ?? null,
                'motivo' => $p['motivo'] ?? null,
                'manual' => (bool) ($p['manual'] ?? false),
            ], $division->paginas ?? []),
            'documentos' => $this->planVigente($division),
            'partes' => $division->partesVigentes->map(fn (ParteDivisionHistorico $parte): array => [
                'id' => $parte->id,
                'paginas' => $parte->paginas,
                'estado' => $parte->estado->value,
                'estado_etiqueta' => $parte->estado->etiqueta(),
                'documento_estado' => $parte->documento?->status->etiqueta(),
            ])->values()->all(),
            'revisiones' => $division->revisiones->take(10)->map(fn (RevisionDivisionHistorico $r): array => [
                'version' => $r->version,
                'por' => $r->autor?->nombreCompleto(),
                'en' => $r->created_at?->toIso8601String(),
                'cambios' => count($r->cambios),
                'detalle' => array_slice($r->cambios, 0, 50),
            ])->values()->all(),
            'tipos' => $tipos->map(fn (DocumentType $t): array => ['id' => $t->id, 'nombre' => $t->nombre, 'clave' => $t->clave, 'requerido' => $t->requerido])->values()->all(),
        ];
    }

    /**
     * Qué pasaría con este plan. No escribe nada (ni BD ni NAS).
     *
     * @param  array<int|string, mixed>  $documentos  Plan del editor: [{document_type_id, paginas}].
     * @return Previa
     */
    public function vistaPrevia(DivisionExpedienteHistorico $division, array $documentos): array
    {
        $this->exigirEditable($division);
        $colaborador = $division->colaborador;
        $tipos = $this->divisiones->tiposChecklist()->keyBy('id');
        $plan = $this->normalizar($division, $documentos, $tipos);
        $vigente = $this->planVigente($division);
        $partes = $division->partesVigentes()->get();
        $porFirma = [];

        foreach ($partes as $parte) {
            $porFirma[$this->firma($parte->document_type_id, $parte->paginas)] = $parte;
        }

        $documentosDeEstePdf = $this->documentosDeEsteHistorico($division);
        $resultado = [];
        $tiposVistos = [];
        $tiposQueQuedan = [];

        foreach ($plan as $i => $documento) {
            $tipoId = $documento['document_type_id'];
            $tipo = $tipoId !== null ? $tipos->get($tipoId) : null;
            $existente = $porFirma[$this->firma($tipoId, $documento['paginas'])] ?? null;

            $accion = match (true) {
                $existente !== null => AccionDocumentoReorganizado::SinCambios,
                $tipo === null => AccionDocumentoReorganizado::SinClasificar,
                isset($tiposVistos[$tipo->id]) => AccionDocumentoReorganizado::Duplicado,
                default => $this->accionParaTipo($colaborador, $tipo, $documentosDeEstePdf),
            };

            if ($tipo !== null) {
                $tiposVistos[$tipo->id] = true;

                if ($accion === AccionDocumentoReorganizado::SinCambios || $accion->cargaAlExpediente()) {
                    $tiposQueQuedan[$tipo->id] = true;
                }
            }

            $resultado[] = [
                'orden' => $i + 1,
                'document_type_id' => $tipoId,
                'tipo' => $tipo?->nombre,
                'paginas' => $documento['paginas'],
                'rango' => ParteDivisionHistorico::formatearRango($documento['paginas']),
                'total_paginas' => count($documento['paginas']),
                'accion' => $accion,
                'parte_id' => $existente?->id,
            ];
        }

        $advertencias = [];
        $archivar = [];
        $reusadas = array_filter(array_map(fn (array $d): ?int => $d['parte_id'], $resultado));

        // Documentos que vinieron de este PDF y se quedan sin hojas.
        foreach ($partes as $parte) {
            if (in_array($parte->id, $reusadas, true) || $parte->employee_document_id === null || $parte->document_type_id === null || isset($tiposQueQuedan[$parte->document_type_id])) {
                continue;
            }

            $doc = EmployeeDocument::query()->where('id', $parte->employee_document_id)->first();

            if ($doc === null || $doc->status === EstadoDocumento::Archivado) {
                continue;
            }

            $nombre = $tipos->get($parte->document_type_id)->nombre ?? 'Documento';

            if (in_array($doc->status, self::ESTADOS_ARCHIVABLES, true)) {
                $archivar[] = $doc->id;
                $advertencias[] = ['nivel' => 'aviso', 'mensaje' => sprintf('«%s» (p. %s) se quedó sin hojas: la versión en revisión que salió de este PDF se archiva (no se borra).', $nombre, $parte->rangoPaginas())];
            } else {
                $advertencias[] = ['nivel' => 'aviso', 'mensaje' => sprintf('«%s» (p. %s) ya está %s y se quedó sin hojas en esta corrección: no se toca; revísalo en el expediente.', $nombre, $parte->rangoPaginas(), mb_strtolower($doc->status->etiqueta()))];
            }
        }

        $sinClasificar = [];

        foreach ($resultado as $documento) {
            if ($documento['document_type_id'] === null) {
                array_push($sinClasificar, ...$documento['paginas']);
            }
        }

        if ($sinClasificar !== []) {
            $advertencias[] = ['nivel' => 'aviso', 'mensaje' => sprintf('Las hojas %s quedan sin clasificar: se guarda la copia, pero no entran al expediente.', ParteDivisionHistorico::formatearRango($sinClasificar))];
        }

        $repetidos = [];

        foreach ($resultado as $documento) {
            if ($documento['accion'] === AccionDocumentoReorganizado::Duplicado) {
                $repetidos[(string) $documento['tipo']][] = $documento['rango'];
            }
        }

        foreach ($repetidos as $nombre => $rangos) {
            $advertencias[] = ['nivel' => 'aviso', 'mensaje' => sprintf('«%s» aparece en más de un documento. El expediente guarda una sola versión vigente por documento: solo se carga el primero; el de p. %s queda como copia. Si son las mismas hojas, únelas en un solo documento.', $nombre, implode(' y ', $rangos))];
        }

        foreach ($resultado as $documento) {
            if ($documento['accion'] === AccionDocumentoReorganizado::YaCubierta) {
                $advertencias[] = ['nivel' => 'info', 'mensaje' => sprintf('«%s» ya tiene un documento vigente que no salió de este PDF: no se reemplaza; la copia de p. %s se conserva.', (string) $documento['tipo'], $documento['rango'])];
            }
        }

        $faltantes = $this->faltantesTrasAplicar($colaborador, $resultado, $archivar);

        if ($faltantes !== []) {
            $advertencias[] = ['nivel' => 'info', 'mensaje' => sprintf('Siguen faltando en el expediente (documentos requeridos): %s.', implode(', ', $faltantes))];
        }

        $sinCambios = $this->hash($plan) === $this->hash($vigente);

        if ($sinCambios) {
            $advertencias[] = ['nivel' => 'info', 'mensaje' => 'No hay cambios respecto a lo guardado.'];
        }

        return [
            'plan' => $plan,
            'hash_plan' => $this->hash($plan),
            'documentos' => $resultado,
            'cambios' => $this->cambios($vigente, $plan, $tipos),
            'advertencias' => $advertencias,
            'faltantes' => $faltantes,
            'archivar' => $archivar,
            'sin_cambios' => $sinCambios,
            'revision_actual' => $this->versionActual($division),
        ];
    }

    /**
     * Vista previa en PDF de un documento resultante (hojas en el orden
     * dado), armada en memoria. No escribe nada.
     *
     * @param  list<int>  $paginas
     */
    public function pdfVistaPrevia(DivisionExpedienteHistorico $division, array $paginas): string
    {
        $this->exigirEditable($division);
        $total = $division->total_paginas;

        if ($paginas === [] || count(array_unique($paginas)) !== count($paginas)) {
            throw ValidationException::withMessages(['paginas' => 'Indica las hojas del documento, sin repetir.']);
        }

        foreach ($paginas as $numero) {
            if ($numero < 1 || $numero > $total) {
                throw ValidationException::withMessages(['paginas' => sprintf('La hoja %d no existe: el PDF tiene %d.', $numero, $total)]);
            }
        }

        return $this->cortador->extraer($this->pdfPreparado($division), $paginas);
    }

    /**
     * Guarda la corrección: regenera los documentos que cambiaron (un PDF por
     * documento con sus hojas en orden), deja sin efecto las partes
     * anteriores (se conservan), crea la revisión versionada con la auditoría
     * hoja por hoja y actualiza el expediente. Regresa null si el plan es el
     * mismo que ya está guardado (idempotente).
     *
     * @param  array<int|string, mixed>  $documentos
     */
    public function confirmar(DivisionExpedienteHistorico $division, array $documentos, int $revisionBase, User $usuario): ?RevisionDivisionHistorico
    {
        $this->exigirEditable($division);
        $colaborador = $division->colaborador;
        $this->divisiones->exigirNoPropio($colaborador, $usuario);

        // Mismo candado que dividir(): nunca se corrige mientras el OCR re-procesa.
        $candado = Cache::lock(sprintf('division-historico-%d', $division->expediente_historico_id), 600);

        if (! $candado->get()) {
            throw ValidationException::withMessages(['documentos' => 'Este PDF histórico se está procesando en este momento; intenta en unos minutos.']);
        }

        try {
            $division->refresh();
            $previa = $this->vistaPrevia($division, $documentos);

            if ($previa['sin_cambios']) {
                return null;
            }

            if ($revisionBase !== $previa['revision_actual']) {
                throw ValidationException::withMessages(['revision_base' => 'Alguien más guardó cambios en estas hojas mientras editabas. Recarga la página para ver la versión actual.']);
            }

            return $this->aplicar($division, $colaborador, $previa, $usuario);
        } finally {
            $candado->release();
        }
    }

    /**
     * @param  Previa  $previa
     */
    private function aplicar(DivisionExpedienteHistorico $division, Colaborador $colaborador, array $previa, User $usuario): RevisionDivisionHistorico
    {
        $version = $previa['revision_actual'] + 1;
        $tipos = $this->divisiones->tiposChecklist()->keyBy('id');

        // FASE A — copias físicas en el NAS (no participa en la transacción).
        $pdf = $this->pdfPreparado($division);
        $carpeta = sprintf('%s/Revision %d', $this->divisiones->carpetaDivision($colaborador, $division), $version);
        $archivos = [];

        foreach ($previa['documentos'] as $documento) {
            if ($documento['accion'] === AccionDocumentoReorganizado::SinCambios) {
                continue;
            }

            $bytes = $this->cortador->extraer($pdf, $documento['paginas']);
            $nombre = sprintf('%02d - %s (p. %s).pdf', $documento['orden'], $documento['tipo'] ?? 'Sin clasificar', $documento['rango']);
            $ruta = $carpeta.'/'.$this->storage->sanitizarSegmento($nombre);
            $this->divisiones->escribirSinSobrescribir($ruta, $bytes);
            $archivos[$documento['orden']] = ['bytes' => $bytes, 'ruta' => $ruta];
        }

        // FASE B — todo o nada en la BD.
        $revision = DB::transaction(function () use ($division, $colaborador, $previa, $usuario, $version, $tipos, $archivos): RevisionDivisionHistorico {
            $revision = RevisionDivisionHistorico::query()->create([
                'division_id' => $division->id,
                'version' => $version,
                'plan' => $previa['plan'],
                'hash_plan' => $previa['hash_plan'],
                'cambios' => $previa['cambios'],
                'advertencias' => array_map(fn (array $a): string => $a['mensaje'], $previa['advertencias']),
                'hecha_por' => $usuario->id,
            ]);

            $reusadas = array_values(array_filter(array_map(fn (array $d): ?int => $d['parte_id'], $previa['documentos'])));

            ParteDivisionHistorico::query()
                ->where('division_id', $division->id)
                ->whereNull('reemplazada_por_revision_id')
                ->whereNotIn('id', $reusadas)
                ->update(['reemplazada_por_revision_id' => $revision->id, 'reemplazada_en' => now()]);

            foreach ($previa['documentos'] as $documento) {
                if ($documento['accion'] === AccionDocumentoReorganizado::SinCambios) {
                    ParteDivisionHistorico::query()->where('id', $documento['parte_id'])->update(['orden' => $documento['orden']]);

                    continue;
                }

                $archivo = $archivos[$documento['orden']] ?? throw new RuntimeException('Falta la copia de un documento de la corrección.');
                $tipo = $documento['document_type_id'] !== null ? $tipos->get($documento['document_type_id']) : null;
                $empleado = null;

                if ($tipo !== null && $documento['accion']->cargaAlExpediente()) {
                    $empleado = $this->divisiones->cargarAlChecklist($colaborador, $tipo, $archivo['bytes'], $documento['paginas'], $usuario);
                }

                ParteDivisionHistorico::query()->create([
                    'division_id' => $division->id,
                    'revision_id' => $revision->id,
                    'document_type_id' => $tipo?->id,
                    'orden' => $documento['orden'],
                    'paginas' => $documento['paginas'],
                    'confianza' => $tipo !== null ? 'manual' : null,
                    'estado' => $documento['accion']->estadoParte()->value,
                    'disk' => (string) config('expedientes.disk'),
                    'path' => $archivo['ruta'],
                    'hash' => hash('sha256', $archivo['bytes']),
                    'size' => strlen($archivo['bytes']),
                    'employee_document_id' => $empleado?->id,
                    'asignado_por' => $usuario->id,
                    'asignado_en' => now(),
                ]);
            }

            if ($previa['archivar'] !== []) {
                EmployeeDocument::query()->whereIn('id', $previa['archivar'])->update(['status' => EstadoDocumento::Archivado->value]);
            }

            $paginas = $this->paginasActualizadas($division, $previa, $tipos);
            $division->update([
                'paginas' => $paginas,
                ...$this->divisiones->contadoresPaginas($paginas),
                'advertencias' => $this->advertenciasActualizadas($division->advertencias ?? [], $paginas, $version),
            ]);

            return $revision;
        });

        try {
            $this->auditoria->registrar('expediente_historico.hojas_corregidas', $colaborador, $usuario, [
                'division_id' => $division->id,
                'revision' => $version,
                'hojas_movidas' => count($previa['cambios']),
                'cambios' => array_slice($previa['cambios'], 0, 200),
                'documentos' => array_map(fn (array $d): array => ['tipo' => $d['tipo'] ?? 'Sin clasificar', 'paginas' => $d['rango'], 'accion' => $d['accion']->value], $previa['documentos']),
            ]);
        } catch (Throwable $e) {
            Log::warning('division_historico: no se pudo registrar la auditoría de la corrección de hojas.', ['division_id' => $division->id, 'error' => $e->getMessage()]);
        }

        return $revision;
    }

    /**
     * Plan que está guardado hoy: las partes vigentes, en su orden. Una hoja
     * que no esté en ninguna parte (no debería pasar) sale «sin clasificar».
     *
     * @return list<DocumentoPlan>
     */
    public function planVigente(DivisionExpedienteHistorico $division): array
    {
        $documentos = [];
        $cubiertas = [];

        foreach ($division->partesVigentes()->get() as $parte) {
            $paginas = array_values(array_filter($parte->paginas, fn (int $n): bool => $n >= 1 && $n <= $division->total_paginas && ! isset($cubiertas[$n])));

            foreach ($paginas as $n) {
                $cubiertas[$n] = true;
            }

            if ($paginas !== []) {
                $documentos[] = ['document_type_id' => $parte->document_type_id, 'paginas' => $paginas];
            }
        }

        for ($n = 1; $n <= $division->total_paginas; $n++) {
            if (! isset($cubiertas[$n])) {
                $documentos[] = ['document_type_id' => null, 'paginas' => [$n]];
            }
        }

        return $this->canonico($documentos);
    }

    /**
     * Valida el plan del editor: cada hoja del PDF exactamente una vez y solo
     * tipos del checklist. Las hojas sin tipo se reagrupan en tramos
     * consecutivos (como las deja el OCR).
     *
     * @param  array<int|string, mixed>  $documentos
     * @param  Collection<int, DocumentType>  $tipos
     * @return list<DocumentoPlan>
     */
    private function normalizar(DivisionExpedienteHistorico $division, array $documentos, Collection $tipos): array
    {
        $total = $division->total_paginas;
        $vistas = [];
        $plan = [];

        foreach (array_values($documentos) as $i => $documento) {
            if (! is_array($documento)) {
                throw ValidationException::withMessages(['documentos' => sprintf('El documento %d no es válido.', $i + 1)]);
            }

            $tipoId = $documento['document_type_id'] ?? null;
            $tipoId = $tipoId === null || $tipoId === '' ? null : (int) $tipoId;

            if ($tipoId !== null && ! $tipos->has($tipoId)) {
                throw ValidationException::withMessages(['documentos' => sprintf('El documento %d tiene un tipo que no es del checklist del expediente.', $i + 1)]);
            }

            $paginas = [];

            foreach ((array) ($documento['paginas'] ?? []) as $numero) {
                $numero = (int) $numero;

                if ($numero < 1 || $numero > $total) {
                    throw ValidationException::withMessages(['documentos' => sprintf('La hoja %d no existe: el PDF tiene %d hojas.', $numero, $total)]);
                }

                if (isset($vistas[$numero])) {
                    throw ValidationException::withMessages(['documentos' => sprintf('La hoja %d está en dos documentos; cada hoja va en uno solo.', $numero)]);
                }

                $vistas[$numero] = true;
                $paginas[] = $numero;
            }

            if ($paginas !== []) {
                $plan[] = ['document_type_id' => $tipoId, 'paginas' => $paginas];
            }
        }

        $faltan = [];

        for ($n = 1; $n <= $total; $n++) {
            if (! isset($vistas[$n])) {
                $faltan[] = $n;
            }
        }

        if ($faltan !== []) {
            throw ValidationException::withMessages(['documentos' => sprintf('Faltan las hojas %s: cada hoja debe quedar en un documento o «Sin clasificar».', ParteDivisionHistorico::formatearRango($faltan))]);
        }

        return $this->canonico($plan);
    }

    /**
     * Forma única de un plan (para comparar y para el hash): los documentos
     * con tipo conservan su orden interno; las hojas sin tipo se juntan en
     * tramos consecutivos; todo se ordena por la primera hoja del PDF.
     *
     * @param  list<DocumentoPlan>  $documentos
     * @return list<DocumentoPlan>
     */
    private function canonico(array $documentos): array
    {
        $plan = [];
        $sueltas = [];

        foreach ($documentos as $documento) {
            if ($documento['document_type_id'] === null) {
                array_push($sueltas, ...$documento['paginas']);

                continue;
            }

            $plan[] = $documento;
        }

        sort($sueltas);
        $tramo = [];

        foreach ($sueltas as $numero) {
            if ($tramo !== [] && $numero !== $tramo[count($tramo) - 1] + 1) {
                $plan[] = ['document_type_id' => null, 'paginas' => $tramo];
                $tramo = [];
            }

            $tramo[] = $numero;
        }

        if ($tramo !== []) {
            $plan[] = ['document_type_id' => null, 'paginas' => $tramo];
        }

        usort($plan, fn (array $a, array $b): int => ($a['paginas'] === [] ? 0 : min($a['paginas'])) <=> ($b['paginas'] === [] ? 0 : min($b['paginas'])));

        return $plan;
    }

    /**
     * @param  list<DocumentoPlan>  $plan
     */
    private function hash(array $plan): string
    {
        return hash('sha256', (string) json_encode($plan));
    }

    /**
     * @param  list<int>  $paginas
     */
    private function firma(?int $tipoId, array $paginas): string
    {
        return sprintf('%s|%s', $tipoId ?? '-', implode(',', $paginas));
    }

    /**
     * Auditoría hoja por hoja: una hoja «se movió» si cambió su tipo o el
     * documento al que pertenece (identificado por su primera hoja).
     *
     * @param  list<DocumentoPlan>  $antes
     * @param  list<DocumentoPlan>  $despues
     * @param  Collection<int, DocumentType>  $tipos
     * @return list<Cambio>
     */
    private function cambios(array $antes, array $despues, Collection $tipos): array
    {
        $ubicar = function (array $plan) use ($tipos): array {
            $donde = [];

            foreach ($plan as $documento) {
                $nombre = $documento['document_type_id'] !== null ? ($tipos->get($documento['document_type_id'])->nombre ?? 'Documento') : 'Sin clasificar';
                $descripcion = sprintf('%s (p. %s)', $nombre, ParteDivisionHistorico::formatearRango($documento['paginas']));

                // Los tramos «sin clasificar» son artificiales (hojas sueltas
                // consecutivas): ahí solo cuenta si la hoja ganó un tipo.
                $clave = $documento['document_type_id'] !== null ? sprintf('%d|%d', $documento['document_type_id'], $documento['paginas'][0]) : '-';

                foreach ($documento['paginas'] as $numero) {
                    $donde[$numero] = ['clave' => $clave, 'texto' => $descripcion];
                }
            }

            return $donde;
        };

        $de = $ubicar($antes);
        $a = $ubicar($despues);
        ksort($a);
        $cambios = [];

        foreach ($a as $numero => $destino) {
            $origen = $de[$numero] ?? ['clave' => '-', 'texto' => 'Sin clasificar'];

            if ($origen['clave'] !== $destino['clave']) {
                $cambios[] = ['pagina' => (int) $numero, 'de' => $origen['texto'], 'a' => $destino['texto']];
            }
        }

        return $cambios;
    }

    /**
     * IDs de los EmployeeDocument que salieron de CUALQUIER división de este
     * mismo PDF histórico: esos sí se reemplazan con la corrección.
     *
     * @return list<int>
     */
    private function documentosDeEsteHistorico(DivisionExpedienteHistorico $division): array
    {
        return array_values(ParteDivisionHistorico::query()
            ->whereIn('division_id', DivisionExpedienteHistorico::query()->select('id')->where('expediente_historico_id', $division->expediente_historico_id))
            ->whereNotNull('employee_document_id')
            ->pluck('employee_document_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all());
    }

    /**
     * @param  list<int>  $documentosDeEstePdf
     */
    private function accionParaTipo(Colaborador $colaborador, DocumentType $tipo, array $documentosDeEstePdf): AccionDocumentoReorganizado
    {
        $vigente = $this->divisiones->documentoVigente($colaborador, $tipo);

        if ($vigente !== null && in_array($vigente->id, $documentosDeEstePdf, true)) {
            return AccionDocumentoReorganizado::NuevaVersion;
        }

        return $this->divisiones->tipoCubierto($colaborador, $tipo) ? AccionDocumentoReorganizado::YaCubierta : AccionDocumentoReorganizado::CargaNueva;
    }

    /**
     * Documentos requeridos que seguirían sin archivo después de aplicar.
     *
     * @param  list<DocumentoResultado>  $resultado
     * @param  list<int>  $archivar
     * @return list<string>
     */
    private function faltantesTrasAplicar(Colaborador $colaborador, array $resultado, array $archivar): array
    {
        $seCargan = [];

        foreach ($resultado as $documento) {
            if ($documento['document_type_id'] !== null && $documento['accion']->cargaAlExpediente()) {
                $seCargan[$documento['document_type_id']] = true;
            }
        }

        $sinArchivo = [EstadoDocumento::Rechazado->value, EstadoDocumento::RequiereCorreccion->value, EstadoDocumento::Vencido->value];
        $faltantes = [];

        foreach ($this->expedientes->estadoDocumental($colaborador)['documentos'] as $documento) {
            $tieneArchivo = $documento['documento_id'] !== null
                && ! in_array($documento['estado'], $sinArchivo, true)
                && ! in_array((int) $documento['documento_id'], $archivar, true);

            if (! $tieneArchivo && ! isset($seCargan[(int) $documento['document_type_id']])) {
                $faltantes[] = (string) $documento['nombre'];
            }
        }

        return $faltantes;
    }

    /**
     * `paginas` de la división con el tipo nuevo de cada hoja; las que se
     * movieron quedan `manual` (y conservan lo que dijo el OCR en `tipo_ocr`).
     *
     * @param  Previa  $previa
     * @param  Collection<int, DocumentType>  $tipos
     * @return list<array<string, mixed>>
     */
    private function paginasActualizadas(DivisionExpedienteHistorico $division, array $previa, Collection $tipos): array
    {
        $paginas = $division->paginas ?? [];
        $movidas = array_map(fn (array $c): int => $c['pagina'], $previa['cambios']);

        foreach ($previa['plan'] as $documento) {
            $clave = $documento['document_type_id'] !== null ? $tipos->get($documento['document_type_id'])?->clave : null;
            $tocadas = array_values(array_intersect($documento['paginas'], $movidas));

            if ($tocadas !== []) {
                $paginas = $this->divisiones->paginasConAsignacion($paginas, $tocadas, $clave);
            }
        }

        return $paginas;
    }

    /**
     * Las advertencias de la corrida se conservan, salvo la lista de hojas
     * sin clasificar, que se recalcula con lo que RH dejó.
     *
     * @param  list<string>  $advertencias
     * @param  list<array<string, mixed>>  $paginas
     * @return list<string>
     */
    private function advertenciasActualizadas(array $advertencias, array $paginas, int $version): array
    {
        $prefijo = 'Páginas sin clasificar';
        $resultado = array_values(array_filter($advertencias, fn (string $a): bool => ! str_starts_with($a, $prefijo)));
        $sinClasificar = [];

        foreach ($paginas as $pagina) {
            if (($pagina['tipo'] ?? null) === null) {
                $sinClasificar[] = (int) ($pagina['pagina'] ?? 0);
            }
        }

        if ($sinClasificar !== []) {
            $resultado[] = sprintf('%s tras la corrección %d de RH: %s.', $prefijo, $version, ParteDivisionHistorico::formatearRango($sinClasificar));
        }

        return $resultado;
    }

    private function versionActual(DivisionExpedienteHistorico $division): int
    {
        return (int) RevisionDivisionHistorico::query()->where('division_id', $division->id)->max('version');
    }

    private function pdfPreparado(DivisionExpedienteHistorico $division): string
    {
        $historico = $division->historico;
        $original = Storage::disk($historico->disk)->get($historico->path);

        if ($original === null || $original === '') {
            throw ValidationException::withMessages(['documentos' => 'El PDF histórico original no está en el almacenamiento.']);
        }

        $preparado = $this->cortador->preparar($original);

        if ($this->cortador->contarPaginas($preparado) !== $division->total_paginas) {
            throw ValidationException::withMessages(['documentos' => 'El PDF histórico ya no tiene las mismas hojas que cuando se dividió. Vuelve a dividirlo antes de corregir.']);
        }

        return $preparado;
    }

    /**
     * Solo la división completada MÁS RECIENTE de un histórico vinculado se
     * edita (corregir una vieja confundiría qué vale).
     */
    private function exigirEditable(DivisionExpedienteHistorico $division): void
    {
        if ($division->estado !== EstadoDivisionHistorico::Completada) {
            throw ValidationException::withMessages(['division' => 'Solo se corrigen divisiones completadas.']);
        }

        if (! $this->divisiones->elegible($division->historico)) {
            throw ValidationException::withMessages(['division' => 'Este PDF histórico no está vinculado a un colaborador.']);
        }

        $ultima = DivisionExpedienteHistorico::query()
            ->where('expediente_historico_id', $division->expediente_historico_id)
            ->latest('id')
            ->value('id');

        if ((int) $ultima !== $division->id) {
            throw ValidationException::withMessages(['division' => 'Hay una división más reciente de este PDF; corrige esa.']);
        }
    }
}
