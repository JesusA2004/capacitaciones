<?php

namespace App\Services\Expedientes\DivisionHistorico;

use App\Enums\EstadoDatoDetectado;
use App\Enums\EstadoDivisionHistorico;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoParteDivision;
use App\Enums\MetodoLecturaPagina;
use App\Models\Colaborador;
use App\Models\DivisionExpedienteHistorico;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\ExpedienteHistorico;
use App\Models\ParteDivisionHistorico;
use App\Models\User;
use App\Services\Documentos\DocumentExtractionService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * División del PDF histórico ÚNICO de la migración inicial por checklist
 * (docs/DIVISION_EXPEDIENTE_HISTORICO.md):
 *
 *  1. Lee cada página (texto embebido; OCR local si es escaneo).
 *  2. La clasifica contra los 14 tipos del checklist (reglas en config).
 *  3. Genera COPIAS por documento en {expediente}/Historico/Division por
 *     checklist/{división}/ — el original nunca se corta, mueve ni borra.
 *  4. Carga al checklist, EN REVISIÓN (RH aprueba), cada tipo que el
 *     colaborador no tenía cubierto; las páginas sin clasificar se reportan
 *     y quedan como partes para que RH las asigne a mano.
 *  5. Reporta los datos personales encontrados contra el colaborador
 *     (faltantes / discrepancias). Nunca aplica nada solo: RH confirma.
 *
 * @phpstan-import-type PaginaClasificada from ClasificadorPaginas
 * @phpstan-import-type DatoDetectado from ComparadorDatosHistorico
 *
 * @phpstan-type PartePlan array{tipo: string|null, tipo_id: int|null, tipo_nombre: string|null, paginas: list<int>, confianza: string|null, accion: EstadoParteDivision}
 * @phpstan-type Analisis array{hash: string, total_paginas: int, paginas: list<PaginaClasificada>, partes: list<PartePlan>, datos: list<DatoDetectado>, advertencias: list<string>, ocr_disponible: bool, ocr_motor: string}
 * @phpstan-type AnalisisConPdf array{hash: string, total_paginas: int, paginas: list<PaginaClasificada>, partes: list<PartePlan>, datos: list<DatoDetectado>, advertencias: list<string>, ocr_disponible: bool, ocr_motor: string, pdf: string}
 * @phpstan-type PartePreparada array{orden: int, plan: PartePlan, tipo: DocumentType|null, bytes: string, ruta: string, hash: string, size: int}
 */
class DivisionExpedienteHistoricoService
{
    /** employee_documents.origen de lo que viene del PDF histórico (máx. 20 caracteres). */
    public const ORIGEN = 'division_historico';

    /** Un documento vigente en estos estados ya cubre el tipo: no se reemplaza solo. */
    private const ESTADOS_QUE_CUBREN = [EstadoDocumento::Aprobado, EstadoDocumento::EnRevision, EstadoDocumento::Cargado, EstadoDocumento::CambioSolicitado];

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly ExpedienteService $expedientes,
        private readonly LectorPaginasPdf $lector,
        private readonly ClasificadorPaginas $clasificador,
        private readonly CortadorPdf $cortador,
        private readonly ComparadorDatosHistorico $comparador,
        private readonly DocumentExtractionService $extraccion,
    ) {}

    public function elegible(ExpedienteHistorico $historico): bool
    {
        return DocumentExtractionService::historicoElegible($historico);
    }

    /**
     * @return array{disponible: bool, motor: string, motivo: string|null}
     */
    public function estadoOcr(): array
    {
        $ocr = $this->lector->ocr();

        return ['disponible' => $ocr->disponible(), 'motor' => $ocr->nombre(), 'motivo' => $ocr->motivoNoDisponible()];
    }

    /**
     * Última división COMPLETADA de este mismo archivo con las reglas
     * actuales: repetirla daría lo mismo (se repite solo con --forzar).
     */
    public function divisionVigente(ExpedienteHistorico $historico): ?DivisionExpedienteHistorico
    {
        return DivisionExpedienteHistorico::query()
            ->where('expediente_historico_id', $historico->id)
            ->where('estado', EstadoDivisionHistorico::Completada->value)
            ->where('hash_origen', $historico->hash)
            ->where('version_reglas', $this->versionReglas())
            ->latest('id')
            ->first();
    }

    /**
     * SIMULACIÓN: lee y clasifica sin escribir nada (ni NAS ni BD).
     *
     * @return Analisis
     */
    public function analizar(ExpedienteHistorico $historico): array
    {
        $this->exigirElegible($historico);
        $resultado = $this->leerYClasificar($historico, $this->colaboradorDe($historico));
        unset($resultado['pdf']);

        return $resultado;
    }

    /**
     * Ejecuta la división (idempotente: si ya hay una vigente la regresa,
     * salvo $forzar). Un fallo deja la corrida como «fallida» con su motivo;
     * las copias ya escritas se conservan (nunca se borra nada).
     */
    public function dividir(ExpedienteHistorico $historico, User $usuario, bool $forzar = false): DivisionExpedienteHistorico
    {
        $this->exigirElegible($historico);

        if (! $forzar && ($vigente = $this->divisionVigente($historico)) !== null) {
            return $vigente;
        }

        $candado = Cache::lock(sprintf('division-historico-%d', $historico->id), 3600);

        if (! $candado->get()) {
            throw new RuntimeException('Ya se está dividiendo este expediente histórico; espera a que termine.');
        }

        try {
            return $this->ejecutar($historico, $this->colaboradorDe($historico), $usuario);
        } finally {
            $candado->release();
        }
    }

    /**
     * RH asigna a mano una parte (sin clasificar, o ya cubierta) a un tipo
     * del checklist: se carga como nueva versión EN REVISIÓN.
     */
    public function asignarParte(ParteDivisionHistorico $parte, DocumentType $tipo, User $usuario): ParteDivisionHistorico
    {
        $colaborador = $parte->division->colaborador;
        $this->exigirNoPropio($colaborador, $usuario);

        if (! $tipo->requerido || ! $tipo->activo) {
            throw ValidationException::withMessages(['document_type_id' => 'Elige un documento del checklist del expediente.']);
        }

        if ($parte->estado->asignada()) {
            throw ValidationException::withMessages(['document_type_id' => 'Esta parte ya se cargó al checklist.']);
        }

        if ($parte->path === null || $parte->disk === null) {
            throw ValidationException::withMessages(['document_type_id' => 'Esta parte no tiene archivo generado.']);
        }

        $bytes = Storage::disk($parte->disk)->get($parte->path);

        if ($bytes === null) {
            throw ValidationException::withMessages(['document_type_id' => 'El archivo de esta parte ya no está en el almacenamiento.']);
        }

        $documento = $this->cargarAlChecklist($colaborador, $tipo, $bytes, $parte->paginas, $usuario);

        $parte->update([
            'document_type_id' => $tipo->id,
            'estado' => EstadoParteDivision::AsignadaManual->value,
            'employee_document_id' => $documento->id,
            'asignado_por' => $usuario->id,
            'asignado_en' => now(),
        ]);

        return $parte->fresh() ?? $parte;
    }

    /**
     * RH confirma un dato detectado en el PDF y lo aplica al colaborador
     * (misma regla de aplicación que las sugerencias de extracción). Solo
     * se acepta uno de los valores detectados — nunca texto libre — y nunca
     * sobre el propio expediente.
     */
    public function aplicarDato(DivisionExpedienteHistorico $division, string $campo, string $valor, User $usuario): DivisionExpedienteHistorico
    {
        $colaborador = $division->colaborador;
        $this->exigirNoPropio($colaborador, $usuario);

        $datos = $this->datosDe($division);
        $indice = null;
        $elegido = null;

        foreach ($datos as $i => $dato) {
            if ($dato['campo'] !== $campo) {
                continue;
            }

            $indice = $i;

            foreach ($dato['valores'] as $opcion) {
                if (Str::upper(trim($opcion['valor'])) === Str::upper(trim($valor))) {
                    $elegido = $opcion['valor'];
                }
            }
        }

        if ($indice === null) {
            throw ValidationException::withMessages(['campo' => 'Ese dato no se detectó en el PDF histórico.']);
        }

        if ($elegido === null) {
            throw ValidationException::withMessages(['valor' => 'Solo puedes aplicar uno de los valores detectados en el PDF.']);
        }

        DB::transaction(function () use ($division, $colaborador, $campo, $elegido, $usuario, $datos, $indice): void {
            $this->extraccion->aplicarAlColaborador($colaborador, [$campo => $elegido]);
            $colaborador->refresh();

            $datos[$indice]['aplicado'] = ['valor' => $elegido, 'por' => $usuario->id, 'en' => now()->toIso8601String()];
            $division->update(['datos_detectados' => array_map(fn (array $dato): array => $this->comparador->reevaluar($colaborador, $dato), $datos)]);
        });

        return $division->fresh() ?? $division;
    }

    /**
     * Copia de una parte para verla (endpoint protegido; nunca la ruta real).
     */
    public function respuestaParte(ParteDivisionHistorico $parte): StreamedResponse
    {
        abort_if($parte->path === null, 404, 'Esta parte no tiene archivo generado.');

        return $this->storage->respuesta((string) $parte->path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes(basename((string) $parte->path))),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Todo lo que necesita la pantalla de RH para un colaborador: sus PDFs
     * históricos con la última división, el checklist (¿completo?) y el
     * reporte de datos.
     *
     * @return array<string, mixed>
     */
    public function detalle(Colaborador $colaborador): array
    {
        $historicos = ExpedienteHistorico::query()->where('colaborador_id', $colaborador->id)->orderBy('id')->get();
        $estado = $this->expedientes->estadoDocumental($colaborador);
        $documentosDivision = [];
        $listaHistoricos = [];

        foreach ($historicos as $historico) {
            $division = DivisionExpedienteHistorico::query()
                ->where('expediente_historico_id', $historico->id)
                ->with(['partes.tipo', 'partes.documento', 'ejecutadoPor:id,name,apellidos'])
                ->latest('id')
                ->first();

            if ($division !== null) {
                foreach ($division->partes as $parte) {
                    if ($parte->employee_document_id !== null) {
                        $documentosDivision[$parte->employee_document_id] = $parte->rangoPaginas();
                    }
                }
            }

            $listaHistoricos[] = [
                'id' => $historico->id,
                'nombre' => $historico->stored_name,
                'original' => $historico->original_name,
                'size' => $historico->size,
                'elegible' => $this->elegible($historico),
                'ver_url' => route('rh.expedientes.historico.ver', $historico),
                'division' => $division !== null ? $this->presentarDivision($division) : null,
            ];
        }

        $faltantes = collect($estado['documentos'])->filter(fn (array $d): bool => $d['documento_id'] === null || in_array($d['estado'], [EstadoDocumento::Rechazado->value, EstadoDocumento::RequiereCorreccion->value, EstadoDocumento::Vencido->value], true));

        return [
            'historicos' => $listaHistoricos,
            'checklist' => [
                'requeridos' => $estado['requeridos'],
                'entregados' => $estado['entregados'],
                'aprobados' => $estado['aprobados'],
                'en_revision' => $estado['en_revision'],
                'porcentaje' => $estado['porcentaje'],
                // Completo de verdad = todo aprobado (regla única ProgresoExpediente).
                'completo' => $estado['completo'],
                // Todos los obligatorios tienen archivo: queda solo la revisión de RH.
                'cubierto' => $estado['requeridos'] > 0 && $estado['entregados'] === $estado['requeridos'],
                'faltantes' => $faltantes->pluck('nombre')->values()->all(),
                'documentos' => array_map(fn (array $d): array => [
                    'document_type_id' => $d['document_type_id'],
                    'nombre' => $d['nombre'],
                    'estado' => $d['estado'],
                    'estado_etiqueta' => EstadoDocumento::from($d['estado'])->etiqueta(),
                    'documento_id' => $d['documento_id'],
                    'desde_historico' => $d['documento_id'] !== null && isset($documentosDivision[$d['documento_id']]) ? $documentosDivision[$d['documento_id']] : null,
                ], $estado['documentos']),
            ],
            'ocr' => $this->estadoOcr(),
        ];
    }

    /**
     * @return Collection<int, DocumentType>
     */
    public function tiposChecklist(): Collection
    {
        return DocumentType::query()->where('requerido', true)->where('activo', true)->orderBy('orden')->orderBy('id')->get();
    }

    /**
     * Resumen del checklist tras dividir (comando y pantalla).
     *
     * @return array{requeridos: int, entregados: int, aprobados: int, completo: bool, cubierto: bool, faltantes: list<string>}
     */
    public function resumenChecklist(Colaborador $colaborador): array
    {
        $estado = $this->expedientes->estadoDocumental($colaborador);

        return [
            'requeridos' => $estado['requeridos'],
            'entregados' => $estado['entregados'],
            'aprobados' => $estado['aprobados'],
            'completo' => $estado['completo'],
            'cubierto' => $estado['requeridos'] > 0 && $estado['entregados'] === $estado['requeridos'],
            'faltantes' => array_values(array_map(
                fn (array $d): string => (string) $d['nombre'],
                array_filter($estado['documentos'], fn (array $d): bool => $d['documento_id'] === null || in_array($d['estado'], [EstadoDocumento::Rechazado->value, EstadoDocumento::RequiereCorreccion->value, EstadoDocumento::Vencido->value], true)),
            )),
        ];
    }

    /**
     * Dos fases claramente separadas (ver docblock de la clase):
     *
     *  FASE A — se lee, clasifica, corta y preparan en NAS TODAS las partes.
     *    Aquí no se toca el checklist ni la BD de documentos; solo se generan
     *    las copias físicas (el NAS no participa en transacciones SQL).
     *  FASE B — se aplica al checklist en UNA sola DB::transaction: cada
     *    EmployeeDocument y su ParteDivisionHistorico se crean juntos y la
     *    corrida se marca «Completada» solo si TODAS las partes se aplicaron.
     *    Si algo falla a mitad, el rollback externo revierte también los
     *    EmployeeDocument de subirVersion() (transacciones anidadas de Laravel
     *    = savepoints), así que la BD/checklist nunca queda a medias.
     */
    private function ejecutar(ExpedienteHistorico $historico, Colaborador $colaborador, User $usuario): DivisionExpedienteHistorico
    {
        $ocr = $this->estadoOcr();
        $division = DivisionExpedienteHistorico::query()->create([
            'expediente_historico_id' => $historico->id,
            'colaborador_id' => $colaborador->id,
            'estado' => EstadoDivisionHistorico::Procesando->value,
            'version_reglas' => $this->versionReglas(),
            'ocr_disponible' => $ocr['disponible'],
            'ocr_motor' => $ocr['motor'],
            'ejecutado_por' => $usuario->id,
        ]);

        /** @var list<string> $copiasEnNas Rutas ya escritas en NAS (para reconciliar huérfanas si FASE B falla). */
        $copiasEnNas = [];

        try {
            // FASE A: nada de esto escribe en la BD de documentos.
            $analisis = $this->leerYClasificar($historico, $colaborador);
            $preparadas = $this->prepararPartes($analisis, $colaborador, $division, $copiasEnNas);

            // FASE B: todo o nada.
            DB::transaction(function () use ($preparadas, $colaborador, $usuario, $division, $analisis): void {
                foreach ($preparadas as $preparada) {
                    $this->aplicarParte($preparada, $colaborador, $usuario, $division);
                }

                $division->update([
                    'estado' => EstadoDivisionHistorico::Completada->value,
                    'hash_origen' => $analisis['hash'],
                    'total_paginas' => $analisis['total_paginas'],
                    'paginas_clasificadas' => count(array_filter($analisis['paginas'], fn (array $p): bool => $p['tipo'] !== null)),
                    'paginas_sin_clasificar' => count(array_filter($analisis['paginas'], fn (array $p): bool => $p['tipo'] === null)),
                    'paginas_sin_texto' => count(array_filter($analisis['paginas'], fn (array $p): bool => $p['metodo'] === MetodoLecturaPagina::SinTexto->value)),
                    'paginas' => $analisis['paginas'],
                    'datos_detectados' => $analisis['datos'],
                    'advertencias' => $analisis['advertencias'],
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('division_historico: no se pudo dividir el expediente histórico.', ['expediente_historico_id' => $historico->id, 'division_id' => $division->id, 'error' => $e->getMessage()]);

            // El NAS no se revierte con la transacción: una copia física que
            // alcanzó a escribirse queda huérfana. No se borra (es evidencia);
            // se registra para reconciliación manual. La BD sí quedó limpia.
            if ($copiasEnNas !== []) {
                Log::warning('division_historico: copias en NAS posiblemente huérfanas tras el fallo (no se borran).', ['division_id' => $division->id, 'copias' => $copiasEnNas]);
            }

            $division->update(['estado' => EstadoDivisionHistorico::Fallida->value, 'error_message' => Str::limit($e->getMessage(), 1000)]);
        }

        return $division->fresh() ?? $division;
    }

    /**
     * FASE A: corta cada parte del PDF, la escribe en NAS (sin sobrescribir) y
     * la deja lista para aplicarse. No toca la BD de documentos. Las rutas que
     * va escribiendo se acumulan en $copiasEnNas para poder reconciliar
     * huérfanas si la FASE B falla.
     *
     * @param  AnalisisConPdf  $analisis
     * @param  list<string>  $copiasEnNas
     * @return list<PartePreparada>
     */
    private function prepararPartes(array $analisis, Colaborador $colaborador, DivisionExpedienteHistorico $division, array &$copiasEnNas): array
    {
        $pdf = $analisis['pdf'];
        $carpeta = sprintf(
            '%s/%s/%s/%d',
            $this->storage->asignarRutaBaseColaborador($colaborador),
            $this->storage->sanitizarSegmento((string) config('expedientes.migracion_inicial.carpeta_historico', 'Historico')),
            $this->storage->sanitizarSegmento((string) config('expedientes.division_historico.carpeta_partes', 'Division por checklist')),
            $division->id,
        );
        $tipos = $this->tiposChecklist()->keyBy('id');
        $preparadas = [];

        foreach ($analisis['partes'] as $orden => $plan) {
            $bytes = $this->cortador->extraer($pdf, $plan['paginas']);
            $nombre = sprintf('%02d - %s (p. %s).pdf', $orden + 1, $plan['tipo_nombre'] ?? 'Sin clasificar', ParteDivisionHistorico::formatearRango($plan['paginas']));
            $ruta = $carpeta.'/'.$this->storage->sanitizarSegmento($nombre);
            $this->escribirSinSobrescribir($ruta, $bytes);
            $copiasEnNas[] = $ruta;

            $preparadas[] = [
                'orden' => $orden + 1,
                'plan' => $plan,
                'tipo' => $plan['tipo_id'] !== null ? $tipos->get($plan['tipo_id']) : null,
                'bytes' => $bytes,
                'ruta' => $ruta,
                'hash' => hash('sha256', $bytes),
                'size' => strlen($bytes),
            ];
        }

        return $preparadas;
    }

    /**
     * FASE B (dentro de la transacción): carga una parte al checklist si
     * corresponde y crea su ParteDivisionHistorico. El EmployeeDocument de
     * cargarAlChecklist y esta fila nacen en la misma transacción externa: si
     * el create de la parte falla, el rollback revierte también el documento.
     *
     * @param  PartePreparada  $preparada
     */
    private function aplicarParte(array $preparada, Colaborador $colaborador, User $usuario, DivisionExpedienteHistorico $division): void
    {
        $plan = $preparada['plan'];
        $tipo = $preparada['tipo'];
        $documento = null;
        $estadoParte = $plan['accion'];

        // Se vuelve a revisar justo antes de cargar: otra parte o RH pudo
        // cubrir el tipo mientras tanto.
        if ($tipo !== null && $estadoParte === EstadoParteDivision::Asignada) {
            if ($this->tipoCubierto($colaborador, $tipo)) {
                $estadoParte = EstadoParteDivision::YaCubierta;
            } else {
                $documento = $this->cargarAlChecklist($colaborador, $tipo, $preparada['bytes'], $plan['paginas'], $usuario);
            }
        }

        ParteDivisionHistorico::query()->create([
            'division_id' => $division->id,
            'document_type_id' => $tipo?->id,
            'orden' => $preparada['orden'],
            'paginas' => $plan['paginas'],
            'confianza' => $plan['confianza'],
            'estado' => $estadoParte->value,
            'disk' => (string) config('expedientes.disk'),
            'path' => $preparada['ruta'],
            'hash' => $preparada['hash'],
            'size' => $preparada['size'],
            'employee_document_id' => $documento?->id,
            'asignado_por' => $documento !== null ? $usuario->id : null,
            'asignado_en' => $documento !== null ? now() : null,
        ]);
    }

    /**
     * @return array{hash: string, total_paginas: int, paginas: list<PaginaClasificada>, partes: list<PartePlan>, datos: list<DatoDetectado>, advertencias: list<string>, ocr_disponible: bool, ocr_motor: string, pdf: string}
     */
    private function leerYClasificar(ExpedienteHistorico $historico, Colaborador $colaborador): array
    {
        $original = Storage::disk($historico->disk)->get($historico->path);

        if ($original === null || $original === '') {
            throw new RuntimeException('El PDF histórico no está en el almacenamiento.');
        }

        $advertencias = [];
        $hash = hash('sha256', $original);

        if ($historico->hash !== '' && $hash !== $historico->hash) {
            $advertencias[] = 'El PDF del NAS ya no es idéntico al registrado en la migración (SHA-256 distinto). Se dividió el archivo actual.';
        }

        $pdf = $this->cortador->preparar($original);
        $total = $this->cortador->contarPaginas($pdf);
        $maximo = max(1, (int) config('expedientes.division_historico.max_paginas', 250));
        $aLeer = min($total, $maximo);

        $temporal = sys_get_temp_dir().DIRECTORY_SEPARATOR.'division-'.Str::uuid().'.pdf';

        try {
            file_put_contents($temporal, $pdf);
            $leidas = $this->lector->leer($temporal, $aLeer);
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }

        $tipos = $this->tiposChecklist();
        $paginas = $this->clasificador->clasificar($leidas, array_values($tipos->map(fn (DocumentType $t): string => $t->clave)->all()));

        for ($numero = $aLeer + 1; $numero <= $total; $numero++) {
            $paginas[] = ['pagina' => $numero, 'tipo' => null, 'sugerido' => null, 'confianza' => null, 'puntaje' => 0, 'coincidencias' => [], 'metodo' => MetodoLecturaPagina::SinTexto->value, 'motivo' => sprintf('Fuera del límite de %d páginas por PDF; no se leyó.', $maximo)];
        }

        if ($total > $maximo) {
            $advertencias[] = sprintf('El PDF tiene %d páginas; solo se leyeron las primeras %d (límite configurado).', $total, $maximo);
        }

        $sinTexto = count(array_filter($paginas, fn (array $p): bool => $p['metodo'] === MetodoLecturaPagina::SinTexto->value));

        if ($sinTexto > 0 && ! $this->lector->ocr()->disponible()) {
            $advertencias[] = sprintf('%d página(s) escaneada(s) sin OCR disponible: %s', $sinTexto, $this->lector->ocr()->motivoNoDisponible() ?? 'OCR no disponible.');
        }

        $sinClasificar = array_values(array_map(fn (array $p): int => $p['pagina'], array_filter($paginas, fn (array $p): bool => $p['tipo'] === null)));

        if ($sinClasificar !== []) {
            $advertencias[] = sprintf('Páginas sin clasificar (RH las asigna a mano): %s.', ParteDivisionHistorico::formatearRango($sinClasificar));
        }

        $textoPorPagina = [];

        foreach ($leidas as $leida) {
            $textoPorPagina[$leida['pagina']] = $leida['texto'];
        }

        $tiposPorClave = $tipos->keyBy('clave');
        $partes = [];
        $partesConTexto = [];

        foreach ($this->clasificador->agrupar($paginas) as $grupo) {
            $tipo = $grupo['tipo'] !== null ? $tiposPorClave->get($grupo['tipo']) : null;
            $partes[] = [
                'tipo' => $grupo['tipo'],
                'tipo_id' => $tipo?->id,
                'tipo_nombre' => $tipo?->nombre,
                'paginas' => $grupo['paginas'],
                'confianza' => $grupo['confianza'],
                'accion' => match (true) {
                    $tipo === null => EstadoParteDivision::SinClasificar,
                    $this->tipoCubierto($colaborador, $tipo) => EstadoParteDivision::YaCubierta,
                    default => EstadoParteDivision::Asignada,
                },
            ];

            if ($tipo !== null) {
                $partesConTexto[] = [
                    'tipo' => $tipo->clave,
                    'tipo_nombre' => $tipo->nombre,
                    'paginas' => $grupo['paginas'],
                    'texto' => implode("\n", array_map(fn (int $n): string => $textoPorPagina[$n] ?? '', $grupo['paginas'])),
                ];
            }
        }

        // Una sola parte de cada tipo se carga: si el mismo documento
        // aparece en dos partes, la primera gana y la otra queda a RH.
        $vistos = [];

        foreach ($partes as $i => $parte) {
            if ($parte['tipo'] !== null && $parte['accion'] === EstadoParteDivision::Asignada) {
                if (isset($vistos[$parte['tipo']])) {
                    $partes[$i]['accion'] = EstadoParteDivision::YaCubierta;
                }
                $vistos[$parte['tipo']] = true;
            }
        }

        $textoCompleto = implode("\n", $textoPorPagina);

        if (trim($textoCompleto) !== '' && ! $this->comparador->nombreAparece($colaborador, $textoCompleto)) {
            $advertencias[] = sprintf('No se encontró el nombre «%s» en el texto del PDF: confirma que el expediente sea de esta persona antes de aprobar.', $colaborador->nombreCompleto());
        }

        return [
            'hash' => $hash,
            'total_paginas' => $total,
            'paginas' => $paginas,
            'partes' => $partes,
            'datos' => $this->comparador->comparar($colaborador, $partesConTexto),
            'advertencias' => $advertencias,
            'ocr_disponible' => $this->lector->ocr()->disponible(),
            'ocr_motor' => $this->lector->ocr()->nombre(),
            'pdf' => $pdf,
        ];
    }

    private function tipoCubierto(Colaborador $colaborador, DocumentType $tipo): bool
    {
        $vigente = EmployeeDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('document_type_id', $tipo->id)
            ->where('status', '!=', EstadoDocumento::Archivado->value)
            ->orderByDesc('version')
            ->first();

        return $vigente !== null && in_array($vigente->status, self::ESTADOS_QUE_CUBREN, true);
    }

    /**
     * @param  list<int>  $paginas
     */
    private function cargarAlChecklist(Colaborador $colaborador, DocumentType $tipo, string $bytes, array $paginas, User $usuario): EmployeeDocument
    {
        $temporal = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parte-'.Str::uuid().'.pdf';
        file_put_contents($temporal, $bytes);

        try {
            $nombre = sprintf('%s - p. %s.pdf', (string) config('expedientes.migracion_inicial.nombre_pdf', 'Expediente historico unificado'), ParteDivisionHistorico::formatearRango($paginas));
            $archivo = new UploadedFile($temporal, $nombre, 'application/pdf', null, true);

            return $this->storage->subirVersion($colaborador, $tipo, $archivo, $usuario->id, EstadoDocumento::EnRevision, self::ORIGEN);
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }
    }

    private function escribirSinSobrescribir(string $ruta, string $bytes): void
    {
        if ($this->storage->existe($ruta)) {
            throw new RuntimeException(sprintf('Ya existe «%s» en el NAS; no se sobrescribe.', $ruta));
        }

        $this->storage->disco()->put($ruta, $bytes);

        if (! $this->storage->existe($ruta)) {
            throw new RuntimeException(sprintf('No se pudo guardar la parte en el NAS: %s', $ruta));
        }
    }

    /**
     * Reporte de datos de una división, re-tipado y reevaluado contra el
     * colaborador ACTUAL (pudo cambiar después de dividir).
     *
     * @return list<DatoDetectado>
     */
    public function datosDe(DivisionExpedienteHistorico $division): array
    {
        $colaborador = $division->colaborador;

        return array_map(fn (array $dato): array => $this->comparador->reevaluar($colaborador, $dato), $division->datos_detectados ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarDivision(DivisionExpedienteHistorico $division): array
    {
        $nombres = $this->tiposChecklist()->mapWithKeys(fn (DocumentType $t): array => [$t->clave => $t->nombre])->all();

        return [
            'id' => $division->id,
            'estado' => $division->estado->value,
            'estado_etiqueta' => $division->estado->etiqueta(),
            'creada_en' => $division->created_at?->toIso8601String(),
            'ejecutado_por' => $division->ejecutadoPor?->nombreCompleto(),
            'total_paginas' => $division->total_paginas,
            'paginas_clasificadas' => $division->paginas_clasificadas,
            'paginas_sin_clasificar' => $division->paginas_sin_clasificar,
            'paginas_sin_texto' => $division->paginas_sin_texto,
            'ocr_disponible' => $division->ocr_disponible,
            'ocr_motor' => $division->ocr_motor,
            'error' => $division->error_message,
            'vigente' => $division->version_reglas === $this->versionReglas(),
            'advertencias' => $division->advertencias ?? [],
            'paginas' => array_map(fn (array $p): array => [
                ...$p,
                'tipo_nombre' => isset($p['tipo']) ? ($nombres[$p['tipo']] ?? $p['tipo']) : null,
                'metodo_etiqueta' => MetodoLecturaPagina::tryFrom((string) ($p['metodo'] ?? ''))?->etiqueta(),
            ], $division->paginas ?? []),
            'partes' => $division->partes->map(fn (ParteDivisionHistorico $parte): array => [
                'id' => $parte->id,
                'orden' => $parte->orden,
                'tipo' => $parte->tipo?->nombre,
                'document_type_id' => $parte->document_type_id,
                'paginas' => $parte->rangoPaginas(),
                'total_paginas' => count($parte->paginas),
                'confianza' => $parte->confianza,
                'estado' => $parte->estado->value,
                'estado_etiqueta' => $parte->estado->etiqueta(),
                'asignada' => $parte->estado->asignada(),
                'documento_estado' => $parte->documento?->status->etiqueta(),
                'ver_url' => route('rh.expedientes.division-historico.partes.ver', $parte),
            ])->values()->all(),
            // Se reevalúa al mostrar: el expediente pudo cambiar después de dividir.
            'datos' => array_map(fn (array $dato): array => [
                ...$dato,
                'estado_etiqueta' => EstadoDatoDetectado::tryFrom($dato['estado'])?->etiqueta(),
            ], $this->datosDe($division)),
        ];
    }

    private function colaboradorDe(ExpedienteHistorico $historico): Colaborador
    {
        return $historico->colaborador ?? throw new InvalidArgumentException('Este expediente histórico no está vinculado a un colaborador.');
    }

    private function exigirElegible(ExpedienteHistorico $historico): void
    {
        if (! $this->elegible($historico)) {
            throw new InvalidArgumentException('Este expediente histórico no está vinculado a un colaborador; vincúlalo antes de dividirlo.');
        }
    }

    /**
     * Nadie edita su propio expediente, ni RH (memoria del proyecto: en
     * «Mi expediente» se solicita corrección).
     */
    private function exigirNoPropio(Colaborador $colaborador, User $usuario): void
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id) {
            throw ValidationException::withMessages(['colaborador' => 'No puedes modificar tu propio expediente; solicita la corrección a RH.']);
        }
    }

    private function versionReglas(): int
    {
        return max(1, (int) config('expedientes.division_historico.version_reglas', 1));
    }
}
