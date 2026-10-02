<?php

namespace App\Services\CicloLaboral;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoReingreso;
use App\Enums\EstadoUsuario;
use App\Enums\PrioridadTarea;
use App\Enums\ProcesoAprobacion;
use App\Enums\TipoContratacion;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\OnboardingProceso;
use App\Models\Reingreso;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Colaboradores\IdentidadColaboradorService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reingreso (Etapa 6 → 2 → 3). Nunca crea otro colaborador:
 *
 *   RH busca a la persona (número de empleado, CURP, RFC, NSS, nombre)
 *   → consulta causa de salida, historial, contratos, evaluaciones
 *   → decide ¿reingreso viable?  NO: motivo y cierre de la solicitud.
 *                                 SÍ: reactiva la MISMA persona
 *   → pide SOLO documentos vencidos, faltantes o requeridos expresamente
 *     (la documentación vigente no se recarga)
 *   → nuevo contrato (paquete de alta) cuando el expediente vuelve a estar
 *     completo → firmas → onboarding aplicable → activo.
 *
 * La vida laboral anterior (cierres, contratos, evaluaciones, documentos,
 * timeline) se conserva intacta.
 */
class ReingresoService
{
    public const PERMISO_GESTIONAR = 'reingresos.gestionar';

    public const PERMISO_SOLICITAR = 'reingresos.solicitar';

    public function __construct(
        private readonly AprobacionService $aprobaciones,
        private readonly IdentidadColaboradorService $identidad,
        private readonly ContratoLaboralService $contratos,
        private readonly ExpedienteService $expediente,
        private readonly MovimientoLaboralService $movimientos,
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function buscar(string $termino, User $usuario): array
    {
        $this->exigirAlguno($usuario, [self::PERMISO_GESTIONAR, self::PERMISO_SOLICITAR]);

        return array_values($this->identidad->buscar($termino)
            ->filter(fn (Colaborador $c) => $this->alcance->tieneAlcanceGlobal($usuario) || $this->alcance->alcanzaColaborador($usuario, $c))
            ->map(function (Colaborador $c): array {
                $ultimoCierre = CierreLaboral::query()->where('colaborador_id', $c->id)->latest('id')->first();

                return [
                    'id' => $c->id,
                    'nombre' => $c->nombreCompleto(),
                    'numero_empleado' => $c->numero_empleado,
                    'curp' => $c->curp,
                    'rfc' => $c->rfc,
                    'puesto' => $c->puesto?->nombre,
                    'sucursal' => $c->sucursalPrincipal?->nombre,
                    'estatus' => $c->estatus->value,
                    'dado_de_baja' => $c->trashed() || $c->estado_alta === EstadoAltaColaborador::Baja,
                    'fecha_baja' => $c->fecha_baja?->toDateString(),
                    'causa_salida' => $ultimoCierre?->tipo_baja->etiqueta(),
                    'reingreso_abierto' => Reingreso::query()->where('colaborador_abierto_id', $c->id)->exists(),
                ];
            })
            ->all());
    }

    /**
     * Historial resumido para decidir (causa de salida, contratos,
     * evaluaciones, reingresos previos, expediente).
     *
     * @return array<string, mixed>
     */
    public function historial(Colaborador $colaborador, User $usuario): array
    {
        $this->exigirAlguno($usuario, [self::PERMISO_GESTIONAR, self::PERMISO_SOLICITAR]);
        $colaborador->loadMissing(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);

        return [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'fecha_baja' => $colaborador->fecha_baja?->toDateString(),
                'estatus' => $colaborador->estatus->etiqueta(),
            ],
            'salidas' => CierreLaboral::query()->where('colaborador_id', $colaborador->id)->orderByDesc('id')->get()
                ->map(fn (CierreLaboral $c) => [
                    'id' => $c->id,
                    'causa' => $c->tipo_baja->etiqueta(),
                    'motivo' => $c->motivo,
                    'fecha_efectiva' => $c->fecha_efectiva->toDateString(),
                    'estado' => $c->estado->etiqueta(),
                ])->values()->all(),
            'contratos' => ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->orderByDesc('fecha_inicio')->get()
                ->map(fn (ContratoLaboral $c) => [
                    'id' => $c->id,
                    'tipo' => $c->tipo->etiqueta(),
                    'inicio' => $c->fecha_inicio->toDateString(),
                    'fin' => $c->fecha_fin?->toDateString(),
                    'estado' => $c->estado->etiqueta(),
                ])->values()->all(),
            'evaluaciones' => EvaluacionPeriodoPrueba::query()->where('colaborador_id', $colaborador->id)->orderByDesc('id')->get()
                ->map(fn (EvaluacionPeriodoPrueba $e) => [
                    'id' => $e->id,
                    'calificacion' => $e->calificacion,
                    'recomienda_renovar' => $e->recomienda_renovar,
                    'decision_renovar' => $e->decision_renovar,
                    'estado' => $e->estado->etiqueta(),
                ])->values()->all(),
            'reingresos' => Reingreso::query()->where('colaborador_id', $colaborador->id)->orderByDesc('id')->get()
                ->map(fn (Reingreso $r) => ['id' => $r->id, 'estado' => $r->estado->etiqueta(), 'fecha' => $r->created_at?->toDateString()])
                ->values()->all(),
            'expediente' => $this->expediente->estadoDocumental($colaborador),
            'documentos_a_renovar' => $this->documentosARenovar($colaborador, []),
        ];
    }

    /**
     * @param  array{motivo: string, puesto_id?: int|null, sucursal_id?: int|null, jefe_id?: int|null, tipo_contratacion?: string|null, sueldo_mensual?: float|int|string|null, fecha_reingreso?: string|null, documentos_adicionales?: list<int>|null}  $datos
     */
    public function solicitar(Colaborador $colaborador, array $datos, User $actor): Reingreso
    {
        $this->exigirAlguno($actor, [self::PERMISO_GESTIONAR, self::PERMISO_SOLICITAR]);
        $this->exigirAlcance($actor, $colaborador);

        if (! $colaborador->trashed() && $colaborador->estado_alta !== EstadoAltaColaborador::Baja && $colaborador->estatus !== EstadoUsuario::Inactivo) {
            throw ValidationException::withMessages(['colaborador' => 'Esta persona sigue activa: el reingreso aplica solo a excolaboradores.']);
        }

        $cierreAbierto = CierreLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('estado', array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::abiertos()))
            ->exists();

        if ($cierreAbierto) {
            throw ValidationException::withMessages(['colaborador' => 'La persona tiene un cierre laboral sin concluir.']);
        }

        try {
            $reingreso = DB::transaction(function () use ($colaborador, $datos, $actor): Reingreso {
                $reingreso = Reingreso::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'cierre_anterior_id' => CierreLaboral::query()->where('colaborador_id', $colaborador->id)->latest('id')->value('id'),
                    'estado' => EstadoReingreso::RevisionRh,
                    'motivo' => $datos['motivo'],
                    'puesto_id' => $datos['puesto_id'] ?? $colaborador->puesto_id,
                    'sucursal_id' => $datos['sucursal_id'] ?? $colaborador->sucursal_principal_id,
                    'jefe_id' => $datos['jefe_id'] ?? null,
                    'tipo_contratacion' => $datos['tipo_contratacion'] ?? TipoContratacion::PeriodoPrueba->value,
                    'sueldo_mensual' => $datos['sueldo_mensual'] ?? $colaborador->sueldo_mensual,
                    'fecha_reingreso' => $datos['fecha_reingreso'] ?? null,
                    'documentos_requeridos' => array_map('intval', $datos['documentos_adicionales'] ?? []),
                    'solicitado_por' => $actor->id,
                    'colaborador_abierto_id' => $colaborador->id,
                ]);

                $esRh = $actor->can(self::PERMISO_GESTIONAR);
                $this->aprobaciones->abrir($reingreso, ProcesoAprobacion::Reingreso, [
                    'colaborador' => $colaborador,
                    'solicitante' => $actor,
                    'aprobador_user' => $esRh ? null : $actor,
                    'aprobador_colaborador' => $esRh ? null : $actor->colaborador,
                    'implicita' => ! $esRh,
                    'motivo_omision' => $esRh ? 'Solicitado directamente por RH: la decisión es de RH.' : null,
                    'comentario' => $esRh ? null : 'Solicitado por operación.',
                ]);

                return $reingreso;
            });
        } catch (QueryException) {
            throw ValidationException::withMessages(['colaborador' => 'Ya hay un reingreso en curso para esta persona.']);
        }

        $this->auditoria->registrar('reingreso_solicitado', $reingreso, $actor, ['colaborador_id' => $colaborador->id, 'motivo' => $reingreso->motivo]);

        $this->tareas->abrir(TipoTarea::ReingresoRevision, $reingreso, [
            'titulo' => sprintf('Revisar reingreso: %s', $colaborador->nombreCompleto()),
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $colaborador,
            'permiso' => OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH,
            'accion' => 'decidir_reingreso',
        ]);

        $this->notificador->notificarEvento('reingreso_solicitado', $colaborador, ['solicitante' => $actor, 'excluir' => [$actor->id]], 'Reingreso por decidir', sprintf('Se solicitó el reingreso de %s.', $colaborador->nombreCompleto()), $reingreso, 'decidir_reingreso', 'alta');

        return $reingreso;
    }

    /**
     * Decisión de RH. Si es viable reactiva a la misma persona y la regresa
     * a Etapa 2 pidiendo solo lo vencido/faltante.
     */
    public function decidir(Reingreso $reingreso, User $actor, bool $viable, ?string $comentario = null): Reingreso
    {
        // RH es la autorización final: nunca la decide quien solo solicita.
        if (! $actor->can(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH)) {
            throw new AuthorizationException('Solo RH decide un reingreso.');
        }

        $persona = Colaborador::withTrashed()->where('id', $reingreso->colaborador_id)->first();

        if ($persona !== null) {
            $this->exigirAlcance($actor, $persona);
        }

        if (! $viable && trim((string) $comentario) === '') {
            throw ValidationException::withMessages(['comentario' => 'Indica el motivo por el que el reingreso no es viable.']);
        }

        $reingreso = DB::transaction(function () use ($reingreso, $actor, $viable, $comentario): Reingreso {
            $reingreso = Reingreso::query()->lockForUpdate()->findOrFail($reingreso->id);

            if ($reingreso->estado !== EstadoReingreso::RevisionRh) {
                throw ValidationException::withMessages(['estado' => "El reingreso ya está «{$reingreso->estado->etiqueta()}»."]);
            }

            if (! $viable) {
                $this->aprobaciones->rechazar($reingreso, ProcesoAprobacion::Reingreso, $actor, (string) $comentario);
                $reingreso->update([
                    'estado' => EstadoReingreso::Rechazado,
                    'comentario_decision' => $comentario,
                    'decidido_por' => $actor->id,
                    'decidido_en' => now(),
                    'colaborador_abierto_id' => null,
                ]);

                return $reingreso;
            }

            $this->aprobaciones->autorizarRh($reingreso, ProcesoAprobacion::Reingreso, $actor, $comentario);
            $this->reactivar($reingreso, $actor);

            $reingreso->update([
                'estado' => EstadoReingreso::EnContratacion,
                'comentario_decision' => $comentario,
                'decidido_por' => $actor->id,
                'decidido_en' => now(),
            ]);

            return $reingreso;
        });

        $this->tareas->resolver(TipoTarea::ReingresoRevision, $reingreso, $actor);
        $this->auditoria->registrar($viable ? 'reingreso_autorizado' : 'reingreso_rechazado', $reingreso, $actor, [
            'colaborador_id' => $reingreso->colaborador_id,
            'comentario' => $comentario,
            'documentos_requeridos' => $reingreso->documentos_requeridos,
        ]);

        if ($viable) {
            $colaborador = $reingreso->colaborador->refresh();
            app(AltaColaboradorService::class)->recalcularEstado($colaborador, $actor);

            if ($colaborador->user !== null) {
                $this->notificador->notificar([$colaborador->user], 'reingreso_autorizado', 'Tu reingreso fue autorizado', 'Actualiza los documentos que se te piden desde la app.', $reingreso, 'subir_documentos', 'alta');
            }
        }

        return $reingreso->refresh();
    }

    /**
     * Llamado por OnboardingService::completar() cuando la persona terminó
     * el onboarding de su reingreso.
     */
    public function alCompletarOnboarding(OnboardingProceso $proceso, User $actor): void
    {
        $reingreso = Reingreso::query()->find($proceso->reingreso_id);

        if ($reingreso === null || $reingreso->estado !== EstadoReingreso::EnContratacion) {
            return;
        }

        $reingreso->update(['estado' => EstadoReingreso::Completado, 'completado_en' => now(), 'colaborador_abierto_id' => null]);
        $this->auditoria->registrar('reingreso_completado', $reingreso, $actor, ['colaborador_id' => $reingreso->colaborador_id]);
    }

    public function abiertoDe(Colaborador $colaborador): ?Reingreso
    {
        return Reingreso::query()->where('colaborador_abierto_id', $colaborador->id)->first();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, Reingreso>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $this->exigirAlguno($usuario, [self::PERMISO_GESTIONAR, self::PERMISO_SOLICITAR]);

        $query = Reingreso::query()->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id', 'puesto:id,nombre', 'sucursal:id,nombre']);

        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::withTrashed(), $usuario)->select('id'));
        }

        return $query
            ->when($filtros['estado'] ?? null, fn ($q, string $estado) => $q->where('estado', $estado))
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(Reingreso $reingreso, ?User $viewer = null): array
    {
        $reingreso->loadMissing(['colaborador', 'puesto:id,nombre', 'sucursal:id,nombre', 'solicitadoPor', 'decididoPor']);
        $tipos = DocumentType::query()->whereIn('id', $reingreso->documentos_requeridos ?? [])->pluck('nombre', 'id');
        $puedeDecidir = $viewer !== null && $reingreso->estado === EstadoReingreso::RevisionRh && $viewer->can(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH);

        return [
            'id' => $reingreso->id,
            'colaborador' => ['id' => $reingreso->colaborador->id, 'nombre' => $reingreso->colaborador->nombreCompleto(), 'numero_empleado' => $reingreso->colaborador->numero_empleado],
            'estado' => $reingreso->estado->value,
            'estado_etiqueta' => $reingreso->estado->etiqueta(),
            'motivo' => $reingreso->motivo,
            'puesto' => $reingreso->puesto?->nombre,
            'sucursal' => $reingreso->sucursal?->nombre,
            'tipo_contratacion' => $reingreso->tipo_contratacion?->etiqueta(),
            'fecha_reingreso' => $reingreso->fecha_reingreso?->toDateString(),
            'documentos_requeridos' => collect($reingreso->documentos_requeridos ?? [])->map(fn (int $id) => ['id' => $id, 'nombre' => $tipos->get($id)])->values()->all(),
            'comentario_decision' => $reingreso->comentario_decision,
            'solicitado_por' => $reingreso->solicitadoPor?->nombreCompleto(),
            'decidido_por' => $reingreso->decididoPor?->nombreCompleto(),
            'decidido_en' => $reingreso->decidido_en?->toIso8601String(),
            'completado_en' => $reingreso->completado_en?->toIso8601String(),
            'creado_en' => $reingreso->created_at?->toIso8601String(),
            'aprobaciones' => $this->aprobaciones->resumen($reingreso, ProcesoAprobacion::Reingreso),
            'acciones_permitidas' => $puedeDecidir
                ? [['clave' => 'autorizar', 'etiqueta' => 'Autorizar reingreso', 'tipo' => 'primaria'], ['clave' => 'rechazar', 'etiqueta' => 'No viable', 'tipo' => 'peligro']]
                : [],
        ];
    }

    /**
     * Reactiva a la MISMA persona: restaura el registro, la regresa a
     * Etapa 2, actualiza su estructura, marca como vencidos solo los
     * documentos que deben renovarse y crea el contrato del reingreso.
     */
    private function reactivar(Reingreso $reingreso, User $actor): void
    {
        $colaborador = Colaborador::withTrashed()->lockForUpdate()->findOrFail($reingreso->colaborador_id);
        $inicio = $reingreso->fecha_reingreso !== null ? Carbon::parse($reingreso->fecha_reingreso)->startOfDay() : now()->startOfDay();

        if ($colaborador->trashed()) {
            $colaborador->restore();
        }

        $colaborador->update([
            'estatus' => EstadoUsuario::EnIncorporacion,
            'estado_alta' => EstadoAltaColaborador::PendienteDocumentos,
            'puesto_id' => $reingreso->puesto_id ?? $colaborador->puesto_id,
            'sucursal_principal_id' => $reingreso->sucursal_id ?? $colaborador->sucursal_principal_id,
            // jefe_id no se toca: sale del organigrama (JefeDirectoService).
            'sueldo_mensual' => $reingreso->sueldo_mensual ?? $colaborador->sueldo_mensual,
            'fecha_ingreso' => $inicio->toDateString(),
            'fecha_baja' => null,
            'expediente_cerrado_en' => null,
            'expediente_cerrado_por' => null,
            'incorporacion_decision' => null,
            'activado_en' => null,
        ]);

        $porRenovar = $this->documentosARenovar($colaborador, $reingreso->documentos_requeridos ?? []);
        $vigentes = $this->expediente->documentosVigentes($colaborador);

        foreach ($porRenovar as $documento) {
            $vigente = $vigentes->get($documento['document_type_id']);

            // Se conserva el documento anterior (historial): solo cambia su
            // estado a "vencido" para que la persona suba la versión nueva.
            $vigente?->update(['status' => EstadoDocumento::Vencido->value, 'comments' => sprintf('Requerido de nuevo para el reingreso: %s.', $documento['motivo'])]);
        }

        $reingreso->documentos_requeridos = array_values(array_unique(array_map(fn (array $d) => (int) $d['document_type_id'], $porRenovar)));

        $tipo = $reingreso->tipo_contratacion ?? TipoContratacion::PeriodoPrueba;
        $fin = $tipo->tieneVencimiento() ? $this->contratos->fechaFinPeriodoPrueba($colaborador->puesto_id, $inicio) : null;
        $contrato = $this->contratos->crearContrato($colaborador, $tipo, $inicio, $fin, $actor);
        $reingreso->contrato_laboral_id = $contrato->id;
        $reingreso->save();

        $this->movimientos->registrarAlta($colaborador, $actor);

        $cuenta = $colaborador->user;

        if ($cuenta !== null && $cuenta->acceso_bloqueado_en !== null) {
            $cuenta->forceFill(['acceso_bloqueado_en' => null, 'acceso_bloqueado_motivo' => null, 'acceso_bloqueado_por' => null])->save();
        }
    }

    /**
     * Documentos que se piden de nuevo: obligatorios faltantes, vencidos por
     * vigencia del catálogo (document_types.vigencia_meses) y los que RH pide
     * expresamente. La documentación vigente NO se recarga.
     *
     * @param  list<int>  $adicionales
     * @return list<array{document_type_id: int, nombre: string, motivo: string}>
     */
    public function documentosARenovar(Colaborador $colaborador, array $adicionales): array
    {
        $vigentes = $this->expediente->documentosVigentes($colaborador);
        $resultado = [];

        $tipos = DocumentType::query()
            ->where('activo', true)
            ->where(fn ($q) => $q->where('requerido', true)->orWhereIn('id', $adicionales))
            ->orderBy('nombre')
            ->get();

        foreach ($tipos as $tipo) {
            /** @var EmployeeDocument|null $documento */
            $documento = $vigentes->get($tipo->id);
            $motivo = null;

            if ($documento === null || in_array($documento->status, [EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion, EstadoDocumento::Vencido], true)) {
                $motivo = 'faltante';
            } elseif ($tipo->vigencia_meses !== null && ($documento->reviewed_at ?? $documento->created_at)?->copy()->addMonths($tipo->vigencia_meses)->isPast()) {
                $motivo = sprintf('vencido (vigencia de %d meses)', $tipo->vigencia_meses);
            } elseif (in_array($tipo->id, $adicionales, true)) {
                $motivo = 'requerido expresamente por RH';
            }

            if ($motivo !== null) {
                $resultado[] = ['document_type_id' => $tipo->id, 'nombre' => $tipo->nombre, 'motivo' => $motivo];
            }
        }

        return $resultado;
    }

    private function exigirAlcance(User $usuario, Colaborador $colaborador): void
    {
        if (! $this->alcance->tieneAlcanceGlobal($usuario) && ! $this->alcance->alcanzaColaborador($usuario, $colaborador)) {
            throw new AuthorizationException('La persona está fuera de tu alcance.');
        }
    }

    /**
     * @param  list<string>  $permisos
     */
    private function exigirAlguno(User $usuario, array $permisos): void
    {
        foreach ($permisos as $permiso) {
            if ($usuario->can($permiso)) {
                return;
            }
        }

        throw new AuthorizationException('No tienes permiso para gestionar reingresos.');
    }
}
