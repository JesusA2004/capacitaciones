<?php

namespace App\Services\DocumentosMaestros;

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoFlujoDocumento as E;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\ResultadoEvaluacion;
use App\Enums\TipoSolicitudInterna;
use App\Exceptions\DatosDocumentoFaltantesException;
use App\Exceptions\DocumentoMaestroFaltanteException;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\EntregaActivo;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Nomina\PrestamoAutorizacionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Motor de reglas documentales del ciclo laboral: decide QUÉ documento
 * oficial toca en cada proceso y momento, en qué estado está y qué puede
 * hacer con él quien lo está viendo. Web y app móvil solo renderizan lo que
 * este servicio devuelve (la app nunca decide qué documento corresponde).
 *
 *   ALTA         expediente completo → paquete de contratación del puesto
 *   RENOVACIÓN   continuidad autorizada → contrato por tiempo indeterminado
 *   EVALUACIÓN   no acredita (RH validó) → formato de evaluación
 *   BAJA         según causa: renuncia / evaluación + aviso de terminación;
 *                finiquito (módulo de finiquitos); checklist del procedimiento
 *   NEGATIVA     colaborador se negó a firmar/recibir → acta con 2 testigos
 *   PERMISO      solicitud aprobada → formato de permiso MR. LANA
 *   PRÉSTAMO     autorización final → contrato, pagaré, carta de retención
 *   ACTIVOS      entrega → carta responsiva (si Jurídico entrega el formato)
 *
 * Toda generación pasa por MotorDocumentalService (mismo motor que el resto
 * del sistema) y queda ligada al registro del proceso (documentable).
 */
class DocumentoProcesoService
{
    public function __construct(
        private readonly MotorDocumentalService $motor,
        private readonly ResolvedorMaestroService $resolvedor,
        private readonly CatalogoMaestrosService $catalogo,
        private readonly DatosDocumentoService $datos,
        private readonly FlujoDocumentalService $flujo,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly ExpedienteService $expediente,
        private readonly AuditoriaService $auditoria,
    ) {}

    // ───────────────────────── Secciones (lectura) ─────────────────────────

    /**
     * Documentos de contratación y de renovación que se ven en la ficha
     * del colaborador.
     *
     * @return list<array<string, mixed>>
     */
    public function seccionesColaborador(Colaborador $colaborador, User $viewer): array
    {
        $this->exigirVer($viewer, $colaborador);
        $secciones = [];
        $contratos = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->orderByDesc('fecha_inicio')->orderByDesc('id')->get();
        $inicial = $contratos->first(fn (ContratoLaboral $c): bool => $c->contrato_anterior_id === null && $c->estado === EstadoContratoLaboral::Vigente)
            ?? $contratos->first(fn (ContratoLaboral $c): bool => $c->contrato_anterior_id === null);
        $renovacion = $contratos->first(fn (ContratoLaboral $c): bool => $c->contrato_anterior_id !== null);

        if ($inicial !== null) {
            $secciones[] = $this->seccionAlta($inicial, $viewer);
        }

        if ($renovacion !== null) {
            $secciones[] = $this->seccionRenovacion($renovacion, $viewer);
        }

        return $secciones;
    }

    /**
     * @return array<string, mixed>
     */
    public function seccionAlta(ContratoLaboral $contrato, User $viewer): array
    {
        $contrato->loadMissing('colaborador.puesto');
        $colaborador = $contrato->colaborador;
        $estado = $this->expediente->estadoDocumental($colaborador);
        $bloqueo = $estado['completo'] ? null : sprintf('El expediente aún no está completo (%d de %d documentos obligatorios aprobados). Al aprobarse el último, aquí se habilita el paquete de contratación.', $estado['aprobados'], $estado['requeridos']);
        $grupo = $colaborador->puesto?->grupo_documental;
        $items = [];

        // Decisión explícita de RH: el puesto no firma documentos laborales.
        if ($colaborador->puesto?->no_requiere_documentos_laborales) {
            return [
                'proceso' => 'alta',
                'titulo' => 'Documentos de contratación',
                'descripcion' => sprintf('El puesto %s no requiere documentos laborales: %s', $colaborador->puesto->nombre, (string) $colaborador->puesto->motivo_sin_documentos),
                'registro' => ['tipo' => 'contrato', 'id' => $contrato->id],
                'colaborador' => $this->resumenColaborador($colaborador),
                'expediente_completo' => $estado['completo'],
                'bloqueo' => null,
                'acciones' => [],
                'documentos' => [],
                'cobertura_url' => $this->puedeVerCobertura($viewer) ? '/rh/documentos-maestros/cobertura' : null,
            ];
        }

        foreach ($this->clavesAlta($contrato) as $indice => $clave) {
            $items[] = $this->item($clave, $colaborador, $contrato, $viewer, 'alta', [
                'motivo' => $indice === 0
                    ? sprintf('Contrato principal (%s) del puesto %s.', $contrato->tipo->etiqueta(), $colaborador->puesto->nombre ?? 'sin puesto')
                    : sprintf('Forma parte del paquete de contratación de %s.', $this->catalogo->etiquetaGrupo($grupo) ?? 'este puesto'),
                'bloqueo' => $bloqueo,
            ]);
        }

        $porGenerar = array_filter($items, fn (array $i): bool => in_array('generar', array_column($i['acciones'], 'clave'), true));

        return [
            'proceso' => 'alta',
            'titulo' => 'Documentos de contratación',
            'descripcion' => $grupo === null
                ? sprintf('El puesto %s no tiene grupo documental: RH debe definirlo en Cobertura documental para saber qué variante de contrato le corresponde.', $colaborador->puesto->nombre ?? 'sin puesto')
                : sprintf('Paquete para %s · %s.', $this->catalogo->etiquetaGrupo($grupo), $contrato->tipo->etiqueta()),
            'registro' => ['tipo' => 'contrato', 'id' => $contrato->id],
            'colaborador' => $this->resumenColaborador($colaborador),
            'expediente_completo' => $estado['completo'],
            'bloqueo' => $bloqueo,
            'acciones' => count($porGenerar) > 0 ? [['clave' => 'generar_paquete', 'etiqueta' => 'Generar paquete de contratación', 'tipo' => 'primaria']] : [],
            'documentos' => $items,
            'cobertura_url' => $this->puedeVerCobertura($viewer) ? '/rh/documentos-maestros/cobertura' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function seccionRenovacion(ContratoLaboral $contrato, User $viewer): array
    {
        $contrato->loadMissing('colaborador');
        $items = [];

        foreach ($this->clavesPaquete('renovacion', null, $contrato->colaborador) as $clave) {
            $items[] = $this->item($clave, $contrato->colaborador, $contrato, $viewer, 'renovacion', [
                'motivo' => 'Continuidad autorizada por RH: contrato por tiempo indeterminado según el puesto.',
            ]);
        }

        return [
            'proceso' => 'renovacion',
            'titulo' => 'Documentos de renovación',
            'descripcion' => sprintf('Contrato por tiempo indeterminado a partir del %s.', $contrato->fecha_inicio->format('d/m/Y')),
            'registro' => ['tipo' => 'contrato', 'id' => $contrato->id],
            'colaborador' => $this->resumenColaborador($contrato->colaborador),
            'bloqueo' => null,
            'acciones' => [],
            'documentos' => $items,
        ];
    }

    /**
     * Documentos de la baja (CierreLaboral), con la rama de negativa de
     * firma y el checklist final del procedimiento integral de baja.
     *
     * @return array<string, mixed>
     */
    public function seccionCierre(CierreLaboral $cierre, User $viewer): array
    {
        $cierre->loadMissing(['colaborador.puesto', 'evaluacion', 'solicitud']);
        $colaborador = $cierre->colaborador;
        $this->exigirVer($viewer, $colaborador);
        $items = [];
        $abierto = ! $cierre->estado->esFinal();

        foreach ($this->clavesCausa($cierre) as $clave) {
            $items[] = $this->item($clave, $colaborador, $cierre, $viewer, 'baja', [
                'motivo' => $this->motivoCausa($clave, $cierre),
                'bloqueo' => $abierto ? $this->bloqueoCierre($clave, $cierre) : 'El cierre ya concluyó.',
            ]);
        }

        if ($cierre->negativa_firma_en !== null) {
            foreach ((array) config('documentos_maestros.documentos_negativa', []) as $clave) {
                $items[] = $this->item((string) $clave, $colaborador, $cierre, $viewer, 'negativa_firma', [
                    'motivo' => sprintf('El %s se registró que el colaborador se negó a firmar/recibir los documentos.', $cierre->negativa_firma_en->format('d/m/Y H:i')),
                    'bloqueo' => $this->testigosCompletos($cierre) < 2 ? 'Captura el nombre y cargo de los dos testigos antes de generar el acta.' : null,
                ]);
            }
        }

        $items[] = $this->itemFiniquito($cierre);
        $acciones = [];
        $rh = $viewer->can('cierres.gestionar') || $viewer->can('documentos_laborales.generar');
        $entregables = $this->clavesCausa($cierre) !== [];

        if ($abierto && $rh && $entregables && $cierre->estado->autorizadoPorRh() && $cierre->negativa_firma_en === null) {
            $acciones[] = ['clave' => 'registrar_negativa', 'etiqueta' => 'El colaborador se negó a firmar/recibir', 'tipo' => 'peligro'];
        }

        if ($abierto && $rh && $cierre->negativa_firma_en !== null) {
            $acciones[] = ['clave' => 'capturar_testigos', 'etiqueta' => 'Testigos y participantes del acta', 'tipo' => 'secundaria'];
        }

        if ($abierto && $rh) {
            $acciones[] = ['clave' => 'registrar_etapa', 'etiqueta' => 'Registrar etapa del procedimiento', 'tipo' => 'secundaria'];
        }

        return [
            'proceso' => 'baja',
            'titulo' => 'Documentos de la baja',
            'descripcion' => sprintf('%s · fecha efectiva %s.', $cierre->tipo_baja->etiqueta(), $cierre->fecha_efectiva->format('d/m/Y')),
            'registro' => ['tipo' => 'cierre', 'id' => $cierre->id],
            'colaborador' => $this->resumenColaborador($colaborador),
            'bloqueo' => null,
            'acciones' => $acciones,
            'documentos' => $items,
            'negativa' => $cierre->negativa_firma_en !== null ? [
                'registrada_en' => $cierre->negativa_firma_en->toIso8601String(),
                'documentos' => $cierre->negativa_documentos ?? [],
                'testigos' => $cierre->testigos ?? [],
                'participantes' => (array) ($cierre->negativa_participantes ?? []),
                'finiquito_a_disposicion' => $cierre->finiquito_a_disposicion,
                'observaciones' => $cierre->negativa_observaciones,
            ] : null,
            'checklist' => $this->checklistCierre($cierre),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function seccionSolicitud(SolicitudInterna $solicitud, User $viewer): ?array
    {
        $tipos = (array) config('documentos_maestros.tipos_solicitud_permiso', []);

        if (! in_array($solicitud->tipo->value, $tipos, true)) {
            return null;
        }

        $colaborador = $solicitud->personaSolicitante();

        if ($colaborador === null) {
            return null;
        }

        $this->exigirVer($viewer, $colaborador);
        $bloqueo = $solicitud->estado === EstadoSolicitudInterna::Aprobada ? null : 'El formato se habilita cuando la solicitud queda aprobada.';
        $items = [$this->item('formato_permiso', $colaborador, $solicitud, $viewer, 'permiso', [
            'motivo' => sprintf('%s · folio %s.', $solicitud->tipo->etiqueta(), $solicitud->folio),
            'bloqueo' => $bloqueo,
        ])];

        if ($solicitud->tipo === TipoSolicitudInterna::PermisoConGoce && $solicitud->modalidad_permiso === 'extraordinario_maternidad') {
            $items[] = $this->item('permiso_extraordinario_goce', $colaborador, $solicitud, $viewer, 'permiso', [
                'motivo' => 'RH marcó el permiso como extraordinario con goce íntegro por protección a la maternidad.',
                'bloqueo' => $bloqueo,
            ]);
        }

        return [
            'proceso' => 'permiso',
            'titulo' => 'Formato de permiso',
            'descripcion' => 'Formato oficial MR. LANA con los datos de la solicitud; las firmas se recaban en papel.',
            'registro' => ['tipo' => 'solicitud', 'id' => $solicitud->id],
            'colaborador' => $this->resumenColaborador($colaborador),
            'bloqueo' => $bloqueo,
            'acciones' => [],
            'documentos' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function seccionPrestamo(Prestamo $prestamo, User $viewer): array
    {
        $prestamo->loadMissing('colaborador');
        $this->exigirVer($viewer, $prestamo->colaborador);
        $bloqueo = $prestamo->autorizado_en === null ? 'Los documentos se habilitan con la autorización final de RH (monto y plazo definitivos).' : null;
        $items = [];

        foreach ((array) config('documentos_maestros.documentos_prestamo', []) as $clave) {
            $items[] = $this->item((string) $clave, $prestamo->colaborador, $prestamo, $viewer, 'prestamo', [
                'motivo' => sprintf('Préstamo de $%s a %d pagos.', number_format((float) $prestamo->monto_original, 2), $prestamo->plazo),
                'bloqueo' => $bloqueo,
            ]);
        }

        return [
            'proceso' => 'prestamo',
            'titulo' => 'Documentos del préstamo',
            'descripcion' => 'Contrato de crédito, pagaré y carta de retención en nómina (formato oficial para trabajadores).',
            'registro' => ['tipo' => 'prestamo', 'id' => $prestamo->id],
            'colaborador' => $this->resumenColaborador($prestamo->colaborador),
            'bloqueo' => $bloqueo,
            'acciones' => $bloqueo === null && count(array_filter($items, fn (array $i): bool => in_array('generar', array_column($i['acciones'], 'clave'), true))) > 1
                ? [['clave' => 'generar_paquete', 'etiqueta' => 'Generar documentos del préstamo', 'tipo' => 'primaria']]
                : [],
            'documentos' => $items,
        ];
    }

    /**
     * Documentos de la evaluación del periodo de capacitación: si no
     * acredita (RH validó), el formato oficial de evaluación; si se
     * renueva, el contrato indeterminado.
     *
     * @return array<string, mixed>
     */
    public function seccionEvaluacion(EvaluacionPeriodoPrueba $evaluacion, User $viewer): array
    {
        $evaluacion->loadMissing(['colaborador', 'contratoRenovacion']);
        $colaborador = $evaluacion->colaborador;
        $this->exigirVer($viewer, $colaborador);
        $items = [];
        $autorizada = $evaluacion->estado === EstadoEvaluacionPrueba::Autorizada;

        if ($evaluacion->decision_renovar === true && $evaluacion->contratoRenovacion !== null) {
            return [...$this->seccionRenovacion($evaluacion->contratoRenovacion, $viewer), 'titulo' => 'Documentos de evaluación', 'registro' => ['tipo' => 'evaluacion', 'id' => $evaluacion->id]];
        }

        $cierre = CierreLaboral::query()->where('evaluacion_id', $evaluacion->id)->latest('id')->first();
        $registro = $cierre ?? $evaluacion;
        $items[] = $this->item('evaluacion_capacitacion', $colaborador, $registro, $viewer, 'evaluacion', [
            'motivo' => 'Formato oficial de evaluación de capacitación inicial (art. 39-B LFT).',
            'bloqueo' => match (true) {
                ! $autorizada => 'Se habilita cuando RH valida la evaluación.',
                $evaluacion->resultado !== ResultadoEvaluacion::NoAprobado => 'El formato oficial solo aplica cuando el colaborador NO acredita.',
                // El formato lleva la fecha de entrega (vencimiento) del cierre.
                $cierre === null => 'Se genera con la no renovación: primero se abre el cierre laboral (su fecha efectiva es la fecha de entrega del formato).',
                default => null,
            },
        ]);

        return [
            'proceso' => 'evaluacion',
            'titulo' => 'Documentos de evaluación',
            'descripcion' => $cierre !== null ? 'La no renovación ya abrió el cierre laboral: los demás documentos de la baja están en el cierre.' : 'Resultado capturado por el jefe inmediato y validado por RH.',
            'registro' => ['tipo' => 'evaluacion', 'id' => $evaluacion->id],
            'colaborador' => $this->resumenColaborador($colaborador),
            'bloqueo' => null,
            'acciones' => [],
            'documentos' => $items,
            'cierre_id' => $cierre?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function seccionEntregaActivo(EntregaActivo $entrega, User $viewer): array
    {
        $colaborador = Colaborador::query()->withTrashed()->where('id', $entrega->colaborador_id)->firstOrFail();
        $this->exigirVer($viewer, $colaborador);

        return [
            'proceso' => 'activos',
            'titulo' => 'Responsiva',
            'descripcion' => 'Carta responsiva del activo entregado.',
            'registro' => ['tipo' => 'entrega_activo', 'id' => $entrega->id],
            'colaborador' => $this->resumenColaborador($colaborador),
            'bloqueo' => null,
            'acciones' => [],
            'documentos' => [$this->item('carta_responsiva', $colaborador, $entrega, $viewer, 'activos', ['motivo' => 'Entrega de activo de trabajo.'])],
        ];
    }

    // ───────────────────────── Acciones ─────────────────────────

    /**
     * Genera (o regenera) un documento del proceso.
     *
     * @param  array<string, mixed>  $completar  Datos faltantes que el usuario capturó en el modal (columna => valor).
     * @param  array<string, string>  $manuales  Datos del acto (testigos…).
     */
    public function generar(string $proceso, Model $registro, string $clave, User $actor, array $completar = [], array $manuales = [], bool $regenerar = false, bool $revision = false, ?string $motivoRevision = null): GeneratedDocument
    {
        // El formato de evaluación vive en el cierre de la no renovación (su
        // fecha efectiva es la fecha de entrega): desde la pantalla de la
        // evaluación se genera sobre ese cierre, igual que lo muestra la sección.
        if ($registro instanceof EvaluacionPeriodoPrueba && $clave === 'evaluacion_capacitacion') {
            $registro = CierreLaboral::query()->where('evaluacion_id', $registro->id)->latest('id')->first() ?? $registro;
        }

        $colaborador = $this->colaboradorDe($registro);
        $this->exigirVer($actor, $colaborador);
        $this->exigirPuedeGenerar($actor, $clave);
        $this->exigirClaveDelProceso($proceso, $registro, $clave, $colaborador);

        $existente = $this->documentoVigente($registro, $clave);
        $revisionDe = null;

        // Revisión EXCEPCIONAL de un documento ya firmado: nunca se
        // sobrescribe ni se cancela el firmado; se emite una nueva instancia
        // ligada a él, con permiso, motivo y auditoría.
        if ($revision) {
            if ($existente === null || ! ($existente->estado_flujo?->firmaFisicaRegistrada() ?? false)) {
                throw ValidationException::withMessages(['documento' => 'La revisión excepcional solo aplica a un documento ya firmado. Si aún no se firma, usa "Regenerar".']);
            }

            if (! $actor->can('documentos_laborales.revisar_firmado')) {
                throw new AuthorizationException('No tienes permiso para emitir una nueva revisión de un documento firmado.');
            }

            $motivoRevision = trim((string) $motivoRevision);

            if (mb_strlen($motivoRevision) < 15) {
                throw ValidationException::withMessages(['motivo' => 'Explica el motivo de la nueva revisión (mínimo 15 caracteres): queda en la auditoría.']);
            }

            $revisionDe = $existente;
        }

        // El cierre puede haber concluido; la revisión de un firmado se
        // permite igual (el original firmado no se toca).
        $bloqueo = $revisionDe === null ? $this->bloqueoGeneracion($proceso, $registro, $clave) : null;

        if ($bloqueo !== null) {
            throw ValidationException::withMessages(['documento' => $bloqueo]);
        }

        if ($completar !== []) {
            $this->completarDatos($colaborador, $completar, $actor);
        }

        if ($existente !== null && $revisionDe === null) {
            if (! $regenerar) {
                throw ValidationException::withMessages(['documento' => 'Este documento ya está generado. Descárgalo o usa "Regenerar" si cambiaron los datos (solo antes de imprimirlo).']);
            }

            if (! in_array($existente->estado_flujo, [E::Generado, E::PendienteImpresion], true)) {
                throw ValidationException::withMessages(['documento' => 'El documento ya se imprimió o firmó: no se regenera encima. Si debe corregirse, emite una nueva revisión (con motivo).']);
            }
        }

        $master = $this->masterPara($clave, $colaborador, $proceso);
        $documento = DB::transaction(function () use ($existente, $revisionDe, $motivoRevision, $actor, $master, $colaborador, $registro, $manuales, $clave, $proceso): GeneratedDocument {
            if ($existente !== null && $revisionDe === null) {
                $this->flujo->cancelar($existente, $actor, 'Regenerado con datos actualizados (nueva instancia del mismo formato).');
            }

            $extra = [...$this->extraPorClave($clave, $registro), ...$manuales];
            $contexto = $this->motor->contextoDesde($colaborador, $registro, $actor, $extra);

            // Plantilla heredada (clave sin documento maestro): motor anterior.
            if ($master->estado_master === null) {
                return $this->motor->generar($colaborador, $master, $actor, $extra, $registro);
            }

            return $this->motor->generarDesdeMaestro($master, $contexto, $actor, $registro, $this->titulo($master, $colaborador), $proceso, $revisionDe, $revisionDe !== null ? $motivoRevision : null);
        });

        if ($revisionDe !== null) {
            $this->auditoria->registrar('documento_revision_firmado', $documento, $actor, [
                'documento_firmado_id' => $revisionDe->id,
                'motivo' => $motivoRevision,
                'colaborador_id' => $colaborador->id,
                'clave' => $clave,
            ]);
        }

        $this->ligarAlRegistro($documento, $registro, $clave);

        return $documento;
    }

    /**
     * Genera todos los documentos pendientes del proceso. Primero valida
     * TODOS (faltantes de datos o de formato) para no dejar el paquete a
     * medias; si algo falta, no genera ninguno.
     *
     * @param  array<string, mixed>  $completar
     * @return list<GeneratedDocument>
     */
    public function generarPaquete(string $proceso, Model $registro, User $actor, array $completar = []): array
    {
        $colaborador = $this->colaboradorDe($registro);
        $this->exigirVer($actor, $colaborador);

        if ($completar !== []) {
            $this->completarDatos($colaborador, $completar, $actor);
            $colaborador->refresh();
        }

        $claves = array_values(array_filter($this->clavesDeProceso($proceso, $registro, $colaborador), fn (string $c): bool => $this->documentoVigente($registro, $c) === null));
        $faltantes = [];
        $formatosFaltantes = [];

        foreach ($claves as $clave) {
            $this->exigirPuedeGenerar($actor, $clave);
            $bloqueo = $this->bloqueoGeneracion($proceso, $registro, $clave);

            if ($bloqueo !== null) {
                throw ValidationException::withMessages(['documento' => $bloqueo]);
            }

            try {
                $master = $this->masterPara($clave, $colaborador, $proceso);
            } catch (DocumentoMaestroFaltanteException $e) {
                $formatosFaltantes[] = $e;

                continue;
            }

            if ($master->estado_master === null) {
                continue;
            }

            $contexto = $this->motor->contextoDesde($colaborador, $registro, $actor, $this->extraPorClave($clave, $registro));

            foreach ($this->motor->faltantesDe($master, $this->datos->resolver($contexto)) as $faltante) {
                $faltantes[$faltante['base']] ??= [...$faltante, 'documentos' => []];
                $faltantes[$faltante['base']]['documentos'][] = $master->nombre;
            }
        }

        if ($formatosFaltantes !== []) {
            throw $formatosFaltantes[0];
        }

        if ($faltantes !== []) {
            throw new DatosDocumentoFaltantesException('Paquete de documentos', array_values($faltantes));
        }

        $generados = [];

        foreach ($claves as $clave) {
            $generados[] = $this->generar($proceso, $registro, $clave, $actor);
        }

        $this->auditoria->registrar('paquete_documental_generado', $registro, $actor, ['proceso' => $proceso, 'colaborador_id' => $colaborador->id, 'claves' => $claves]);

        return $generados;
    }

    /**
     * Guarda en la ficha los datos que faltaban (solo columnas permitidas
     * y solo si el usuario puede editarlas). Lo que ya existe no se pide de
     * nuevo; esto completa el expediente para futuros documentos.
     *
     * @param  array<string, mixed>  $datos  columna => valor (prefijo "sucursal." para la sucursal).
     */
    public function completarDatos(Colaborador $colaborador, array $datos, User $actor): void
    {
        $deColaborador = [];
        $deSucursal = [];

        foreach ($datos as $columna => $valor) {
            $columna = (string) $columna;

            if (str_starts_with($columna, 'sucursal.')) {
                $deSucursal[substr($columna, 9)] = $valor;
            } else {
                $deColaborador[$columna] = $valor;
            }
        }

        $reglasColaborador = array_intersect_key(DatosDocumentoService::columnasEditablesColaborador(), $deColaborador);
        $reglasSucursal = array_intersect_key(DatosDocumentoService::columnasEditablesSucursal(), $deSucursal);

        if (count($reglasColaborador) !== count($deColaborador) || count($reglasSucursal) !== count($deSucursal)) {
            throw ValidationException::withMessages(['completar' => 'Uno de los datos no se puede capturar desde aquí.']);
        }

        if ($deColaborador !== [] && ! ($actor->can('expedientes.editar') || $actor->can('colaboradores.alta'))) {
            throw new AuthorizationException('No tienes permiso para completar datos del expediente.');
        }

        if ($deSucursal !== [] && ! $actor->can('sucursales.administrar')) {
            throw new AuthorizationException('Solo quien administra sucursales puede completar su domicilio. Pídelo a RH.');
        }

        $validosColaborador = Validator::make($deColaborador, array_map(fn (string $r): string => str_replace('nullable', 'required', $r), $reglasColaborador))->validate();
        $validosSucursal = Validator::make($deSucursal, array_map(fn (string $r): string => str_replace('nullable', 'required', $r), $reglasSucursal))->validate();

        DB::transaction(function () use ($colaborador, $validosColaborador, $validosSucursal): void {
            if ($validosColaborador !== []) {
                $colaborador->update(array_map(fn (mixed $v): mixed => is_string($v) ? trim($v) : $v, $validosColaborador));
            }

            if ($validosSucursal !== [] && $colaborador->sucursal_principal_id !== null) {
                Sucursal::query()->whereKey($colaborador->sucursal_principal_id)->update(array_map(fn (mixed $v): mixed => is_string($v) ? trim($v) : $v, $validosSucursal));
            }
        });

        $this->auditoria->registrar('datos_documento_completados', $colaborador, $actor, ['colaborador_id' => $colaborador->id, 'campos' => array_keys([...$validosColaborador, ...$validosSucursal])]);
    }

    /**
     * Resuelve el registro del proceso desde la URL (tipo + id), sin
     * confiar en nada más que mande el cliente.
     */
    public function registro(string $tipo, int $id): Model
    {
        return match ($tipo) {
            'contrato' => ContratoLaboral::query()->findOrFail($id),
            'cierre' => CierreLaboral::query()->findOrFail($id),
            'solicitud' => SolicitudInterna::query()->findOrFail($id),
            'prestamo' => Prestamo::query()->findOrFail($id),
            'evaluacion' => EvaluacionPeriodoPrueba::query()->findOrFail($id),
            'entrega_activo' => EntregaActivo::query()->findOrFail($id),
            default => abort(404),
        };
    }

    /**
     * Sección del proceso para cualquier registro (lo usan los endpoints
     * genéricos después de una acción).
     *
     * null: el registro no lleva documentos oficiales (p. ej. una solicitud
     * que no es de permiso).
     *
     * @return array<string, mixed>|null
     */
    public function seccion(string $proceso, Model $registro, User $viewer): ?array
    {
        return match (true) {
            $registro instanceof CierreLaboral => $this->seccionCierre($registro, $viewer),
            $registro instanceof SolicitudInterna => $this->seccionSolicitud($registro, $viewer),
            $registro instanceof Prestamo => $this->seccionPrestamo($registro, $viewer),
            $registro instanceof EvaluacionPeriodoPrueba => $this->seccionEvaluacion($registro, $viewer),
            $registro instanceof EntregaActivo => $this->seccionEntregaActivo($registro, $viewer),
            $registro instanceof ContratoLaboral && ($proceso === 'renovacion' || $registro->contrato_anterior_id !== null) => $this->seccionRenovacion($registro, $viewer),
            $registro instanceof ContratoLaboral => $this->seccionAlta($registro, $viewer),
            default => abort(404),
        };
    }

    /**
     * Claves del paquete de contratación del contrato inicial según el
     * grupo documental del puesto.
     *
     * @return list<string>
     */
    public function clavesAlta(ContratoLaboral $contrato): array
    {
        $contrato->loadMissing('colaborador.puesto');

        return $this->clavesPaquete('alta', $contrato->tipo->value, $contrato->colaborador);
    }

    /**
     * @return list<string>
     */
    public function clavesPaquete(string $proceso, ?string $modalidad, Colaborador $colaborador): array
    {
        $paquetes = (array) config('documentos_maestros.paquetes.'.$proceso, []);

        if ($modalidad !== null) {
            $paquetes = (array) ($paquetes[$modalidad] ?? []);
        }

        $grupo = $colaborador->puesto?->grupo_documental;
        $claves = $grupo !== null && isset($paquetes[$grupo]) ? $paquetes[$grupo] : ($paquetes['*'] ?? []);

        return array_values(array_filter((array) $claves, 'is_string'));
    }

    // ───────────────────────── Internos ─────────────────────────

    /**
     * Un documento del proceso tal como lo pintan web y app: estado,
     * formato oficial que se usará ("Gestor v2"), línea de tiempo, la
     * SIGUIENTE acción (una sola, la principal) y las secundarias (menú).
     * La app y la web solo renderizan esto; nunca deciden.
     *
     * @param  array{motivo: string, bloqueo?: string|null}  $opciones
     * @return array<string, mixed>
     */
    private function item(string $clave, Colaborador $colaborador, Model $registro, User $viewer, string $proceso, array $opciones): array
    {
        $faltaFormato = null;

        try {
            $master = $this->masterPara($clave, $colaborador, $proceso);
        } catch (DocumentoMaestroFaltanteException $e) {
            $master = null;
            $faltaFormato = [
                'code' => 'DOCUMENT_TEMPLATE_MISSING',
                'mensaje' => $e->mensaje(),
                'detalle' => $e->detalle,
                'cobertura_url' => $this->puedeVerCobertura($viewer) ? '/rh/documentos-maestros/cobertura' : null,
            ];
        }

        $documento = $this->documentoVigente($registro, $clave);
        $historial = GeneratedDocument::query()
            ->where('documentable_type', $registro->getMorphClass())
            ->where('documentable_id', $registro->getKey())
            ->where('clave_plantilla', $clave)
            ->when($documento !== null, fn ($q) => $q->where('id', '!=', $documento?->id))
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        $bloqueo = $opciones['bloqueo'] ?? null;

        // Un master sin diseño validado no se usa para un documento oficial
        // (el motor respondería 422 DOCUMENT_VISUAL_VALIDATION_FAILED): se
        // avisa aquí, antes de que alguien pulse "Generar".
        $sinValidar = $documento === null && $master !== null && $master->estado_master !== null && ! $master->disenoValidado();

        if ($sinValidar && $bloqueo === null) {
            $bloqueo = sprintf('La versión v%d del formato aún no tiene el diseño validado: RH debe validarla en Documentos maestros.', $master->version);
        }

        $propio = $viewer->colaborador_id !== null && $viewer->colaborador_id === $colaborador->id;
        $estado = match (true) {
            $documento !== null => $documento->estado_flujo->value ?? 'generado',
            $faltaFormato !== null => 'sin_formato',
            $sinValidar => 'formato_sin_validar',
            $bloqueo !== null => 'bloqueado',
            default => 'por_generar',
        };
        $acciones = $propio && ! $viewer->can('documentos_laborales.generar')
            ? ($documento !== null && $viewer->can('descargar', $documento) ? [['clave' => 'descargar', 'etiqueta' => 'Ver PDF', 'tipo' => 'secundaria']] : [])
            : $this->acciones($documento, $master, $clave, $bloqueo, $viewer);
        $principal = collect($acciones)->first(fn (array $a): bool => $a['tipo'] === 'primaria');

        return [
            'clave' => $clave,
            'nombre' => $master->nombre ?? $this->catalogo->nombreClave($clave),
            'motivo' => $opciones['motivo'],
            'estado' => $estado,
            'estado_etiqueta' => match ($estado) {
                'sin_formato' => 'Formato no cargado',
                'formato_sin_validar' => 'Formato sin validar',
                'bloqueado' => 'En espera',
                'por_generar' => 'Listo para generar',
                default => $this->etiquetaEstado($documento),
            },
            'bloqueo' => $documento === null ? $bloqueo : null,
            'formato_faltante' => $faltaFormato,
            'master' => $master !== null ? [
                'id' => $master->id,
                'familia' => $master->familia,
                'version' => $master->version,
                'nombre' => $master->nombre,
                'motor' => $master->motor->value,
                'heredada' => $master->estado_master === null,
                // "Gestor v2", "General v1": qué formato oficial se usará.
                'etiqueta' => sprintf('%s v%d', $this->etiquetaAplica($master), $master->version),
                'diseno_validado' => $master->estado_master === null || $master->disenoValidado(),
            ] : null,
            'requiere' => [
                'impresion' => (bool) ($documento->requiere_impresion ?? $master->requiere_impresion ?? false),
                'firma_fisica' => (bool) ($documento->requiere_firma_fisica ?? $master->requiere_firma_fisica ?? false),
                'huella' => (bool) ($documento->requiere_huella ?? $master->requiere_huella ?? false),
                'testigos' => (bool) ($documento->requiere_testigos ?? $master->requiere_testigos ?? false),
                'cantidad_testigos' => (int) ($master->cantidad_testigos ?? 0),
                'envio_corporativo' => (bool) ($documento->requiere_envio_corporativo ?? $master->requiere_envio_corporativo ?? false),
            ],
            'documento' => $documento !== null ? $this->resumenDocumento($documento, $viewer) : null,
            'linea_tiempo' => $this->lineaTiempo($documento, $master),
            'siguiente_accion' => $principal,
            'historial' => $historial->map(fn (GeneratedDocument $d): array => [
                'id' => $d->id,
                'estado' => $d->estado_flujo?->value,
                'estado_etiqueta' => $d->estado_flujo?->etiqueta(),
                'version_plantilla' => $d->version_plantilla,
                'generado_en' => $d->created_at?->toIso8601String(),
                'motivo_cancelacion' => $d->motivo_cancelacion,
                'puede_descargar' => $viewer->can('descargar', $d),
            ])->values()->all(),
            'acciones' => $acciones,
        ];
    }

    /**
     * Acciones permitidas para quien mira, ordenadas: la primaria (la
     * SIGUIENTE en el flujo) primero; el resto son secundarias (menú "…").
     * Solo se incluye lo que el backend aceptaría (sin 403 sorpresa).
     *
     * @return list<array{clave: string, etiqueta: string, tipo: string}>
     */
    private function acciones(?GeneratedDocument $documento, ?DocumentTemplate $master, string $clave, ?string $bloqueo, User $viewer): array
    {
        $primarias = [];
        $secundarias = [];
        $genera = $this->puedeGenerar($viewer, $clave);

        if ($documento === null) {
            if ($master !== null && $bloqueo === null && $genera) {
                $primarias[] = ['clave' => 'generar', 'etiqueta' => 'Generar', 'tipo' => 'primaria'];
            }

            return $primarias;
        }

        $estado = $documento->estado_flujo;
        $opera = $viewer->can('operar', $documento);
        $fisico = $documento->requiere_impresion || $documento->requiere_firma_fisica;

        if ($opera) {
            if ($fisico && in_array($estado, [E::Generado, E::PendienteImpresion], true)) {
                $primarias[] = ['clave' => 'marcar_impreso', 'etiqueta' => 'Imprimir', 'tipo' => 'primaria'];
            }

            if ($documento->requiere_firma_fisica && in_array($estado, [E::Impreso, E::PendienteFirmaFisica], true)) {
                $primarias[] = ['clave' => 'registrar_firma', 'etiqueta' => $documento->requiere_huella ? 'Registrar firma y huella' : 'Registrar firma', 'tipo' => 'primaria'];
            }

            if ($estado === E::FirmadoFisicamente && $documento->requiere_envio_corporativo) {
                $primarias[] = ['clave' => 'registrar_envio', 'etiqueta' => 'Enviar original a corporativo', 'tipo' => 'primaria'];
            }

            if ($estado === E::EnviadoCorporativo) {
                $primarias[] = ['clave' => 'registrar_recepcion', 'etiqueta' => 'Registrar recepción en corporativo', 'tipo' => 'primaria'];
            }

            if ($estado === E::RecibidoCorporativo
                || ($estado === E::FirmadoFisicamente && ! $documento->requiere_envio_corporativo)
                || ($estado === E::Impreso && ! $documento->requiere_firma_fisica)) {
                $primarias[] = ['clave' => 'subir_escaneo', 'etiqueta' => 'Subir documento firmado', 'tipo' => 'primaria'];
            }

            if ($estado === E::Escaneado) {
                $primarias[] = ['clave' => 'archivar', 'etiqueta' => 'Archivar', 'tipo' => 'primaria'];
            }

            if ($estado === E::Impreso && ! $documento->requiere_firma_fisica) {
                $secundarias[] = ['clave' => 'archivar', 'etiqueta' => 'Archivar', 'tipo' => 'secundaria'];
            }
        }

        $disponible = $this->archivoDisponible($documento);

        if ($disponible && $viewer->can('descargar', $documento)) {
            // Sin acción física pendiente para quien mira, ver el PDF es lo principal.
            $secundarias[] = ['clave' => 'descargar', 'etiqueta' => 'Ver PDF', 'tipo' => $primarias === [] ? 'primaria' : 'secundaria'];
        }

        if ($disponible && $viewer->can('descargarWord', $documento)) {
            $secundarias[] = ['clave' => 'descargar_word', 'etiqueta' => 'Descargar Word', 'tipo' => 'secundaria'];
        }

        if ($genera && in_array($estado, [E::Generado, E::PendienteImpresion], true) && $master !== null) {
            $secundarias[] = ['clave' => 'regenerar', 'etiqueta' => 'Regenerar con datos actuales', 'tipo' => 'secundaria'];
        }

        if ($genera && ($estado?->firmaFisicaRegistrada() ?? false) && $master !== null && $master->estado_master !== null && $viewer->can('documentos_laborales.revisar_firmado')) {
            $secundarias[] = ['clave' => 'nueva_revision', 'etiqueta' => 'Nueva revisión (con motivo)', 'tipo' => 'peligro'];
        }

        // Una sola primaria: si el flujo diera dos, la segunda va al menú.
        $resultado = array_slice($primarias, 0, 1);

        foreach ([...array_slice($primarias, 1), ...$secundarias] as $accion) {
            $degradar = $accion['tipo'] === 'primaria' && $resultado !== [] && $resultado[0]['tipo'] === 'primaria';
            $resultado[] = ['clave' => $accion['clave'], 'etiqueta' => $accion['etiqueta'], 'tipo' => $degradar ? 'secundaria' : $accion['tipo']];
        }

        return $resultado;
    }

    /**
     * Pasos del documento según lo que exige su formato. estado: hecho |
     * actual | pendiente.
     *
     * @return list<array{clave: string, etiqueta: string, estado: string}>
     */
    private function lineaTiempo(?GeneratedDocument $documento, ?DocumentTemplate $master): array
    {
        $impresion = (bool) ($documento->requiere_impresion ?? $master->requiere_impresion ?? false);
        $firma = (bool) ($documento->requiere_firma_fisica ?? $master->requiere_firma_fisica ?? false);
        $envio = (bool) ($documento->requiere_envio_corporativo ?? $master->requiere_envio_corporativo ?? false);
        $estado = $documento?->estado_flujo;
        $orden = [
            E::Generado->value => 1, E::PendienteImpresion->value => 1, E::Impreso->value => 2, E::PendienteFirmaFisica->value => 2,
            E::FirmadoFisicamente->value => 3, E::EnviadoCorporativo->value => 4, E::RecibidoCorporativo->value => 5,
            E::Escaneado->value => 6, E::Archivado->value => 7,
        ];
        $avance = $estado !== null ? ($orden[$estado->value] ?? 1) : 0;
        $pasos = [['generado', 'Generado', 1]];

        if ($impresion || $firma) {
            $pasos[] = ['impreso', 'Impreso', 2];
        }

        if ($firma) {
            $pasos[] = ['firmado', 'Firma', 3];
        }

        if ($envio) {
            $pasos[] = ['enviado', 'Enviado', 4];
            $pasos[] = ['recibido', 'Corporativo', 5];
        }

        $pasos[] = ['escaneado', 'Escaneo', 6];
        $pasos[] = ['archivado', 'Archivado', 7];
        $actualMarcado = false;
        $linea = [];

        foreach ($pasos as [$clave, $etiqueta, $nivel]) {
            $hecho = $avance >= $nivel;

            // Sin firma física, "Impreso" basta para escanear: un archivado
            // con pasos intermedios que no aplican cuenta como hecho.
            if (! $hecho && $estado === E::Archivado) {
                $hecho = true;
            }

            $paso = $hecho ? 'hecho' : (! $actualMarcado && $estado !== E::Cancelado ? 'actual' : 'pendiente');
            $actualMarcado = $actualMarcado || $paso === 'actual';
            $linea[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'estado' => $paso];
        }

        return $linea;
    }

    private function archivoDisponible(GeneratedDocument $documento): bool
    {
        try {
            return Storage::disk($documento->disk)->exists($documento->path);
        } catch (Throwable) {
            return false;
        }
    }

    private function etiquetaEstado(?GeneratedDocument $documento): string
    {
        return match ($documento?->estado_flujo) {
            E::Generado, E::PendienteImpresion => 'Generado',
            E::Impreso => $documento->requiere_firma_fisica ? 'Firma pendiente' : 'Impreso',
            E::PendienteFirmaFisica => 'Firma pendiente',
            E::FirmadoFisicamente => 'Firmado',
            E::EnviadoCorporativo => 'Enviado',
            E::RecibidoCorporativo => 'Recibido',
            E::Escaneado => 'Escaneado',
            E::Archivado => 'Archivado',
            default => $documento?->estado_flujo?->etiqueta() ?? 'Generado',
        };
    }

    private function etiquetaAplica(DocumentTemplate $master): string
    {
        $grupos = array_values(array_filter((array) ($master->grupos_puesto ?? []), 'is_string'));

        if ($master->puesto_id !== null) {
            return (string) ($master->puesto->nombre ?? 'Puesto');
        }

        return $grupos === [] ? 'General' : implode(' / ', array_map(fn (string $g): string => (string) $this->catalogo->etiquetaGrupo($g), $grupos));
    }

    private function puedeVerCobertura(User $usuario): bool
    {
        return $usuario->can('plantillas_documentales.administrar') || $usuario->can('configuracion.rh');
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenDocumento(GeneratedDocument $documento, User $viewer): array
    {
        $documento->loadMissing('generadoPor');
        $estado = $documento->estado_flujo;

        return [
            'id' => $documento->id,
            'titulo' => $documento->titulo,
            'archivo' => $documento->original_name,
            'estado' => $estado?->value,
            'estado_etiqueta' => $estado?->etiqueta(),
            'version_plantilla' => $documento->version_plantilla,
            'master_familia' => $documento->master_familia,
            'master_version' => $documento->master_version ?? $documento->version_plantilla,
            'generado_en' => $documento->created_at?->toIso8601String(),
            'generado_por' => $documento->generadoPor?->name,
            'fidelidad' => $documento->conversion_fidelity ?? $documento->fidelidad,
            'conversion_engine' => $documento->conversion_engine,
            'paginas' => $documento->paginas,
            'tiene_word' => $documento->docx_path !== null && $viewer->can('descargarWord', $documento),
            // El registro existe pero el archivo ya no está en el almacenamiento
            // (NAS restaurado, demo sin archivos): no se ofrece un Ver PDF roto.
            'archivo_disponible' => $this->archivoDisponible($documento),
            'revision_de_id' => $documento->revision_de_id,
            'motivo_revision' => $documento->motivo_revision,
            'descargas' => $documento->descargas,
            'impreso' => $estado !== null && in_array($estado, [E::Impreso, E::PendienteFirmaFisica, E::FirmadoFisicamente, E::EnviadoCorporativo, E::RecibidoCorporativo, E::Escaneado, E::Archivado], true),
            'firmado' => $estado !== null && $estado->firmaFisicaRegistrada(),
            'escaneado' => $estado !== null && in_array($estado, [E::Escaneado, E::Archivado], true),
            'archivado' => $estado === E::Archivado,
            'escaneo_id' => $documento->signed_document_id,
        ];
    }

    /**
     * El finiquito lo produce su propio módulo (cálculo → autorización →
     * pago); aquí solo se muestra su estado dentro de "Documentos de la baja".
     *
     * @return array<string, mixed>
     */
    private function itemFiniquito(CierreLaboral $cierre): array
    {
        $finiquito = $cierre->solicitud_interna_id !== null ? FiniquitoCalculo::query()->where('solicitud_interna_id', $cierre->solicitud_interna_id)->first() : null;
        $estado = $finiquito?->estado;

        return [
            'clave' => 'finiquito',
            'nombre' => 'Finiquito',
            'motivo' => 'Cálculo, autorización y pago del finiquito (módulo de finiquitos).',
            'estado' => $estado->value ?? 'pendiente_rh',
            'estado_etiqueta' => match ($estado) {
                EstadoFiniquito::Borrador => 'Calculado (borrador)',
                EstadoFiniquito::Revisado => 'Revisado',
                EstadoFiniquito::Aprobado => 'Autorizado',
                EstadoFiniquito::Firmado => 'Firmado',
                EstadoFiniquito::Pagado => 'Pagado',
                default => 'Pendiente RH',
            },
            'bloqueo' => null,
            'formato_faltante' => null,
            'master' => null,
            'modulo' => 'finiquito',
            'requiere' => ['impresion' => true, 'firma_fisica' => true, 'huella' => true, 'testigos' => false, 'cantidad_testigos' => 0, 'envio_corporativo' => true],
            'documento' => $finiquito?->generated_document_id !== null ? ['id' => $finiquito->generated_document_id, 'estado' => null, 'estado_etiqueta' => null] : null,
            'neto' => $finiquito?->neto,
            'historial' => [],
            'linea_tiempo' => [],
            'siguiente_accion' => null,
            'acciones' => [],
        ];
    }

    /**
     * Checklist final del procedimiento integral de baja (vencimiento de
     * capacitación inicial). Solo aplica a no renovación / fin de contrato.
     *
     * @return list<array{clave: string, etiqueta: string, cumplido: bool, aplica: bool}>|null
     */
    private function checklistCierre(CierreLaboral $cierre): ?array
    {
        if (! in_array($cierre->tipo_baja->value, ['no_renovacion', 'fin_contrato'], true)) {
            return null;
        }

        $firmadoOEntregado = function (string $clave) use ($cierre): bool {
            $documento = $this->documentoVigente($cierre, $clave);

            return $documento !== null && ($documento->estado_flujo?->firmaFisicaRegistrada() ?? false);
        };
        $negativa = $cierre->negativa_firma_en !== null;
        $acta = $this->documentoVigente($cierre, 'acta_negativa_firma');
        $contratoCapacitacion = GeneratedDocument::query()
            ->where('colaborador_id', $cierre->colaborador_id)
            ->where('clave_plantilla', 'contrato_capacitacion')
            ->whereNotNull('estado_flujo')
            ->get()
            ->contains(fn (GeneratedDocument $d): bool => $d->estado_flujo?->firmaFisicaRegistrada() ?? false);
        $finiquito = $cierre->solicitud_interna_id !== null ? FiniquitoCalculo::query()->where('solicitud_interna_id', $cierre->solicitud_interna_id)->first() : null;

        return [
            ['clave' => 'contrato_capacitacion', 'etiqueta' => 'Contrato de capacitación inicial (firmado)', 'cumplido' => $contratoCapacitacion, 'aplica' => true],
            ['clave' => 'evaluacion', 'etiqueta' => 'Evaluación de capacitación', 'cumplido' => $firmadoOEntregado('evaluacion_capacitacion') || ($negativa && $this->documentoVigente($cierre, 'evaluacion_capacitacion') !== null), 'aplica' => true],
            ['clave' => 'aviso', 'etiqueta' => 'Aviso de terminación', 'cumplido' => $firmadoOEntregado('aviso_terminacion') || ($negativa && $this->documentoVigente($cierre, 'aviso_terminacion') !== null), 'aplica' => true],
            ['clave' => 'acta_negativa', 'etiqueta' => 'Acta de negativa (si aplica)', 'cumplido' => ! $negativa || ($acta !== null && ($acta->estado_flujo?->firmaFisicaRegistrada() ?? false)), 'aplica' => $negativa],
            ['clave' => 'notificacion', 'etiqueta' => 'Evidencia de notificación electrónica', 'cumplido' => $cierre->notificacion_electronica_en !== null, 'aplica' => true],
            ['clave' => 'finiquito', 'etiqueta' => 'Cálculo y pago/consignación de finiquito', 'cumplido' => $finiquito !== null && ($finiquito->pagado_en !== null || $cierre->consignacion_preventiva_en !== null), 'aplica' => true],
            ['clave' => 'baja_imss', 'etiqueta' => 'Baja IMSS', 'cumplido' => $cierre->baja_imss_en !== null, 'aplica' => true],
            ['clave' => 'baja_sistemas', 'etiqueta' => 'Baja de sistemas (accesos)', 'cumplido' => $cierre->accesos_cancelados_en !== null || $cierre->acceso_suspendido_en !== null, 'aplica' => true],
        ];
    }

    /**
     * true si el checklist del procedimiento está completo (o no aplica).
     */
    public function checklistCompleto(CierreLaboral $cierre): bool
    {
        $checklist = $this->checklistCierre($cierre);

        return $checklist === null || collect($checklist)->every(fn (array $p): bool => ! $p['aplica'] || $p['cumplido']);
    }

    /**
     * @return list<string>
     */
    public function clavesCausa(CierreLaboral $cierre): array
    {
        return array_values(array_filter((array) (config('documentos_maestros.documentos_por_causa', [])[$cierre->tipo_baja->value] ?? []), 'is_string'));
    }

    /**
     * Documento maestro de la variante correcta; si la clave no tiene
     * ningún master en el registro (Jurídico no lo ha entregado) se usa la
     * plantilla heredada activa que RH tuviera. Si no hay ninguna:
     * DocumentoMaestroFaltanteException (422 DOCUMENT_TEMPLATE_MISSING).
     */
    private function masterPara(string $clave, Colaborador $colaborador, string $proceso): DocumentTemplate
    {
        if (! $this->resolvedor->esClaveMaestra($clave)) {
            $legado = $this->motor->plantillaActiva($clave);

            if ($legado !== null) {
                return $legado;
            }
        }

        return $this->resolvedor->resolver($clave, $colaborador, $proceso);
    }

    private function testigosCompletos(CierreLaboral $cierre): int
    {
        return count(array_filter($cierre->testigos ?? [], fn (array $t): bool => trim($t['nombre']) !== '' && trim($t['cargo']) !== ''));
    }

    private function motivoCausa(string $clave, CierreLaboral $cierre): string
    {
        return match ($clave) {
            'carta_renuncia' => 'Renuncia voluntaria: el colaborador la firma de puño y letra (huella si corresponde).',
            'aviso_terminacion' => sprintf('Se entrega el día del vencimiento (%s), junto con la evaluación y el finiquito.', $cierre->fecha_efectiva->format('d/m/Y')),
            'evaluacion_capacitacion' => 'Resultado de la evaluación de capacitación inicial (NO acredita), validado por RH.',
            default => $cierre->tipo_baja->etiqueta(),
        };
    }

    private function bloqueoCierre(string $clave, CierreLaboral $cierre): ?string
    {
        if ($clave === 'carta_renuncia') {
            return null;
        }

        if (! $cierre->estado->autorizadoPorRh()) {
            return 'Se habilita cuando RH autoriza la baja.';
        }

        if ($clave === 'evaluacion_capacitacion') {
            $evaluacion = $cierre->evaluacion;

            if ($evaluacion === null) {
                return 'El cierre no tiene evaluación de capacitación ligada.';
            }

            if ($evaluacion->estado !== EstadoEvaluacionPrueba::Autorizada) {
                return 'La evaluación aún no está validada por RH.';
            }

            if ($evaluacion->resultado !== ResultadoEvaluacion::NoAprobado) {
                return 'El formato oficial solo aplica cuando el colaborador NO acredita.';
            }
        }

        return null;
    }

    private function bloqueoGeneracion(string $proceso, Model $registro, string $clave): ?string
    {
        return match (true) {
            $registro instanceof CierreLaboral && $clave === 'acta_negativa_firma' => $registro->negativa_firma_en === null
                ? 'El acta solo se genera cuando se registró la negativa del colaborador a firmar/recibir.'
                : ($this->testigosCompletos($registro) < 2 ? 'Captura los dos testigos antes de generar el acta.' : null),
            $registro instanceof CierreLaboral => $registro->estado->esFinal() ? 'El cierre ya concluyó.' : $this->bloqueoCierre($clave, $registro),
            $registro instanceof SolicitudInterna => $registro->estado === EstadoSolicitudInterna::Aprobada ? null : 'El formato se habilita cuando la solicitud queda aprobada.',
            $registro instanceof Prestamo => $registro->autorizado_en === null ? 'Los documentos se habilitan con la autorización final de RH.' : null,
            $registro instanceof EvaluacionPeriodoPrueba => match (true) {
                $registro->estado !== EstadoEvaluacionPrueba::Autorizada => 'Se habilita cuando RH valida la evaluación.',
                $registro->resultado !== ResultadoEvaluacion::NoAprobado => 'El formato oficial solo aplica cuando el colaborador NO acredita.',
                ! CierreLaboral::query()->where('evaluacion_id', $registro->id)->exists() => 'Se genera con la no renovación: primero se abre el cierre laboral.',
                default => null,
            },
            $registro instanceof ContratoLaboral && $proceso === 'alta' => $this->expediente->estadoDocumental($registro->colaborador)['completo'] ? null : 'El expediente aún no está completo.',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function clavesDeProceso(string $proceso, Model $registro, Colaborador $colaborador): array
    {
        return match (true) {
            $registro instanceof ContratoLaboral && $proceso === 'renovacion' => $this->clavesPaquete('renovacion', null, $colaborador),
            $registro instanceof ContratoLaboral => $this->clavesAlta($registro),
            $registro instanceof CierreLaboral && $proceso === 'negativa_firma' => array_values(array_map('strval', (array) config('documentos_maestros.documentos_negativa', []))),
            $registro instanceof CierreLaboral => $this->clavesCausa($registro),
            $registro instanceof Prestamo => array_values(array_map('strval', (array) config('documentos_maestros.documentos_prestamo', []))),
            $registro instanceof SolicitudInterna => ['formato_permiso'],
            $registro instanceof EvaluacionPeriodoPrueba => ['evaluacion_capacitacion'],
            $registro instanceof EntregaActivo => ['carta_responsiva'],
            default => [],
        };
    }

    /**
     * Nunca se genera un documento que no corresponde al proceso (no se
     * confía en la clave que manda el cliente).
     */
    private function exigirClaveDelProceso(string $proceso, Model $registro, string $clave, Colaborador $colaborador): void
    {
        $validas = $this->clavesDeProceso($proceso, $registro, $colaborador);

        if ($registro instanceof CierreLaboral) {
            $validas = [...$validas, ...$this->clavesCausa($registro), ...array_map('strval', (array) config('documentos_maestros.documentos_negativa', []))];
        }

        if ($registro instanceof SolicitudInterna && $registro->modalidad_permiso === 'extraordinario_maternidad') {
            $validas[] = 'permiso_extraordinario_goce';
        }

        if ($registro instanceof ContratoLaboral && $registro->contrato_anterior_id !== null) {
            $validas = [...$validas, ...$this->clavesPaquete('renovacion', null, $colaborador)];
        }

        if (! in_array($clave, $validas, true)) {
            throw ValidationException::withMessages(['documento' => 'Ese documento no corresponde a este proceso.']);
        }
    }

    /**
     * Datos del acto según el documento (fecha que lleva, etc.).
     *
     * @return array<string, string>
     */
    private function extraPorClave(string $clave, Model $registro): array
    {
        if ($registro instanceof CierreLaboral) {
            return match ($clave) {
                // El aviso se fecha el día del vencimiento (Fase 3 del procedimiento).
                'aviso_terminacion' => ['fecha_documento' => $registro->fecha_efectiva->toDateString()],
                'acta_negativa_firma' => ['fecha_documento' => ($registro->negativa_firma_en ?? now())->toDateString()],
                default => [],
            };
        }

        // Plantillas heredadas de préstamo: los datos AUTORIZADOS del préstamo
        // (monto, plazo, pago…). Los masters los toman de DatosDocumentoService.
        if ($registro instanceof Prestamo) {
            return app(PrestamoAutorizacionService::class)->variablesDocumento($registro);
        }

        return [];
    }

    private function ligarAlRegistro(GeneratedDocument $documento, Model $registro, string $clave): void
    {
        if ($registro instanceof SolicitudInterna) {
            $documento->update(['solicitud_id' => $registro->id]);
        }

        if ($registro instanceof Prestamo) {
            match ($clave) {
                'prestamo_contrato' => $registro->update(['contrato_documento_id' => $documento->id]),
                'prestamo_pagare' => $registro->update(['pagare_documento_id' => $documento->id]),
                default => null,
            };
        }

        if ($registro instanceof EntregaActivo) {
            $registro->update(['generated_document_id' => $documento->id]);
        }

        if ($registro instanceof ContratoLaboral && $registro->generated_document_id === null && in_array($clave, ['contrato_capacitacion', 'contrato_indeterminado', 'contrato_periodo_prueba', 'contrato_tiempo_determinado'], true)) {
            $registro->update(['generated_document_id' => $documento->id]);
        }
    }

    public function documentoVigente(Model $registro, string $clave): ?GeneratedDocument
    {
        return GeneratedDocument::query()
            ->where('documentable_type', $registro->getMorphClass())
            ->where('documentable_id', $registro->getKey())
            ->where('clave_plantilla', $clave)
            ->where(fn ($q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', E::Cancelado->value))
            ->latest('id')
            ->first();
    }

    private function colaboradorDe(Model $registro): Colaborador
    {
        $colaborador = match (true) {
            $registro instanceof ContratoLaboral, $registro instanceof CierreLaboral, $registro instanceof EvaluacionPeriodoPrueba, $registro instanceof Prestamo => Colaborador::query()->withTrashed()->where('id', $registro->colaborador_id)->first(),
            $registro instanceof SolicitudInterna => $registro->personaSolicitante(),
            $registro instanceof EntregaActivo => Colaborador::query()->withTrashed()->where('id', $registro->colaborador_id)->first(),
            default => null,
        };

        if (! $colaborador instanceof Colaborador) {
            abort(404, 'El proceso no tiene colaborador.');
        }

        return $colaborador;
    }

    private function titulo(DocumentTemplate $master, Colaborador $colaborador): string
    {
        return (string) preg_replace('/\s+—\s+.*$/u', '', $master->nombre);
    }

    public function puedeGenerar(User $usuario, string $clave): bool
    {
        $reglas = (array) config('documentos_maestros.permisos_generacion', []);
        $permisos = (array) ($reglas[$clave] ?? $reglas['*'] ?? ['documentos_laborales.generar']);

        foreach ($permisos as $permiso) {
            if (is_string($permiso) && $usuario->can($permiso)) {
                return true;
            }
        }

        return false;
    }

    private function exigirPuedeGenerar(User $usuario, string $clave): void
    {
        if (! $this->puedeGenerar($usuario, $clave)) {
            throw new AuthorizationException('No tienes permiso para generar este documento.');
        }
    }

    /**
     * Ver los documentos de una persona: alcance organizacional o cadena de
     * mando (gerente/regional sobre su gente). El propio colaborador solo ve
     * (sin acciones de operación).
     */
    public function puedeVer(User $usuario, Colaborador $colaborador): bool
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id) {
            return true;
        }

        if (! $usuario->can('documentos_laborales.ver') && ! $usuario->can('documentos_laborales.generar') && ! $usuario->can('cierres.solicitar')) {
            return false;
        }

        if ($this->alcance->alcanzaColaborador($usuario, $colaborador)) {
            return true;
        }

        return $usuario->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($usuario->colaborador, $colaborador);
    }

    private function exigirVer(User $usuario, Colaborador $colaborador): void
    {
        if (! $this->puedeVer($usuario, $colaborador)) {
            throw new AuthorizationException('Este colaborador está fuera de tu alcance.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenColaborador(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['puesto', 'sucursalPrincipal']);

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'grupo_documental' => $colaborador->puesto?->grupo_documental,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'dado_de_baja' => $colaborador->trashed(),
        ];
    }

    /**
     * Fecha de hoy en la zona de la empresa (para pruebas deterministas).
     */
    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Mexico_City');
    }
}
