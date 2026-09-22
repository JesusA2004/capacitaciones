<?php

namespace App\Services\Nomina;

use App\Enums\EstadoFlujoDocumento;
use App\Enums\PrioridadTarea;
use App\Enums\TipoSolicitudInterna;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Flujo completo del préstamo personal:
 *
 *   colaborador solicita (SolicitudInterna tipo "prestamo")
 *   → jefe inmediato da visto bueno (VistoBuenoService)
 *   → RH/Dirección autoriza con monto y plazo autorizados (o rechaza)
 *   → contrato de préstamo + pagaré (motor documental) → firma → resguardo.
 *
 * MR. LANA PEOPLE NO ejecuta descuentos de nómina ni se integra con el
 * sistema de nómina: el saldo del préstamo es administrativo/informativo
 * (ledger PrestamoMovimiento que RH alimenta).
 */
class PrestamoAutorizacionService
{
    public const PERMISO_AUTORIZAR = 'prestamos.autorizar';

    public function __construct(
        private readonly SolicitudesService $solicitudes,
        private readonly AprobacionJerarquicaService $aprobaciones,
        private readonly MotorDocumentalService $motor,
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  monto_autorizado, plazo_autorizado, periodicidad?, pago_programado?, observaciones? (validado por AutorizarPrestamoRequest).
     */
    public function autorizar(SolicitudInterna $solicitud, array $datos, User $actor): Prestamo
    {
        if ($solicitud->tipo !== TipoSolicitudInterna::PrestamoInterno) {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud no es de préstamo.']);
        }

        $monto = (float) $datos['monto_autorizado'];
        $plazo = (int) $datos['plazo_autorizado'];
        $pagoProgramado = isset($datos['pago_programado']) ? (float) $datos['pago_programado'] : null;
        $observaciones = isset($datos['observaciones']) ? (string) $datos['observaciones'] : null;

        if ($monto <= 0 || $plazo < 1) {
            throw ValidationException::withMessages(['monto_autorizado' => 'Indica un monto y plazo autorizados válidos.']);
        }

        $this->solicitudes->aprobar($solicitud, $actor, $observaciones ?? 'Préstamo autorizado.', [
            'monto_autorizado' => $monto,
            'plazo_autorizado' => $plazo,
            'periodicidad' => isset($datos['periodicidad']) ? (string) $datos['periodicidad'] : 'quincenal',
            'pago_programado' => $pagoProgramado ?? round($monto / $plazo, 2),
            'observaciones' => $observaciones,
        ]);

        $prestamo = Prestamo::query()->where('solicitud_id', $solicitud->id)->firstOrFail();

        $this->auditoria->registrar('prestamo_autorizado', $prestamo, $actor, [
            'monto_solicitado' => $prestamo->monto_solicitado,
            'monto_autorizado' => $prestamo->monto_original,
            'plazo_autorizado' => $prestamo->plazo,
        ]);

        $this->generarDocumentos($prestamo, $actor);

        $prestamo->loadMissing('colaborador.user');
        $usuario = $prestamo->colaborador->user;

        if ($usuario !== null) {
            $this->notificador->notificar([$usuario], 'prestamo_autorizado', 'Tu préstamo fue autorizado', sprintf('Monto autorizado $%s a %d pagos.', number_format((float) $prestamo->monto_original, 2), $prestamo->plazo), $prestamo, 'ver_prestamo');
        }

        return $prestamo->refresh();
    }

    public function rechazar(SolicitudInterna $solicitud, User $actor, string $motivo): SolicitudInterna
    {
        if ($solicitud->tipo !== TipoSolicitudInterna::PrestamoInterno) {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud no es de préstamo.']);
        }

        $solicitud = $this->solicitudes->rechazar($solicitud, $actor, $motivo);
        $this->auditoria->registrar('prestamo_rechazado', $solicitud, $actor, ['motivo' => $motivo]);

        return $solicitud;
    }

    /**
     * Contrato de préstamo y pagaré con los datos AUTORIZADOS (snapshot en
     * el documento). Una plantilla faltante queda como pendiente, no como error.
     *
     * @return array{contrato: int|null, pagare: int|null, pendientes: list<string>}
     */
    public function generarDocumentos(Prestamo $prestamo, User $actor): array
    {
        $prestamo->loadMissing('colaborador');
        $variables = [
            'monto_prestamo' => sprintf('$%s', number_format((float) $prestamo->monto_original, 2)),
            'monto_solicitado_prestamo' => sprintf('$%s', number_format((float) ($prestamo->monto_solicitado ?? $prestamo->monto_original), 2)),
            'plazo_prestamo' => (string) $prestamo->plazo,
            'pago_prestamo' => sprintf('$%s', number_format((float) $prestamo->pago_programado, 2)),
            'saldo_prestamo' => sprintf('$%s', number_format((float) $prestamo->saldo, 2)),
            'periodicidad_prestamo' => $prestamo->periodicidad,
            'motivo_prestamo' => (string) $prestamo->motivo,
        ];

        $pendientes = [];

        foreach (['contrato_prestamo' => 'contrato_documento_id', 'pagare' => 'pagare_documento_id'] as $clave => $columna) {
            if ($prestamo->getAttribute($columna) !== null) {
                continue;
            }

            try {
                $documento = $this->motor->generar($prestamo->colaborador, $clave, $actor, $variables, $prestamo);
                $prestamo->update([$columna => $documento->id]);
            } catch (ValidationException $e) {
                $pendientes[] = $clave;
                $this->tareas->abrir(TipoTarea::PrestamoPendiente, $prestamo, [
                    'titulo' => sprintf('Documento de préstamo pendiente: %s', config("contratos.plantillas.{$clave}.nombre", $clave)),
                    'descripcion' => collect($e->errors())->flatten()->implode(' '),
                    'prioridad' => PrioridadTarea::Alta,
                    'colaborador' => $prestamo->colaborador,
                    'permiso' => 'documentos_laborales.generar',
                    'accion' => 'generar_documento',
                    'datos' => ['clave' => $clave],
                ]);
            }
        }

        if ($pendientes === []) {
            $this->tareas->resolver(TipoTarea::PrestamoPendiente, $prestamo, $actor);
        }

        $prestamo->refresh();

        return ['contrato' => $prestamo->contrato_documento_id, 'pagare' => $prestamo->pagare_documento_id, 'pendientes' => $pendientes];
    }

    /**
     * Resguardo: contrato y pagaré ya firmados (digital o físicamente) y
     * bajo custodia de RH.
     */
    public function resguardar(Prestamo $prestamo, User $actor): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $actor): Prestamo {
            $prestamo = Prestamo::query()->lockForUpdate()->findOrFail($prestamo->id);

            if ($prestamo->resguardado_en !== null) {
                throw ValidationException::withMessages(['prestamo' => 'El préstamo ya está resguardado.']);
            }

            foreach (['contrato' => $prestamo->contratoDocumento, 'pagaré' => $prestamo->pagareDocumento] as $nombre => $documento) {
                if (! $this->firmado($documento)) {
                    throw ValidationException::withMessages(['prestamo' => "El {$nombre} del préstamo debe estar generado y firmado antes del resguardo."]);
                }
            }

            $prestamo->update(['resguardado_en' => now(), 'resguardado_por' => $actor->id]);
            $this->auditoria->registrar('prestamo_resguardado', $prestamo, $actor);

            return $prestamo;
        });
    }

    /**
     * @param  array<string, mixed>  $filtros  estado?, colaborador_id?, per_page?
     * @return LengthAwarePaginator<int, Prestamo>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        // Todo lo que usa aArray() viene precargado: sin N+1 en listados.
        $query = Prestamo::query()->with([
            'colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id',
            'solicitud:id,folio',
            'contratoDocumento:id,estado_flujo',
            'pagareDocumento:id,estado_flujo',
        ]);

        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->withTrashed(), $usuario)->select('id'));
        }

        $estado = isset($filtros['estado']) ? (string) $filtros['estado'] : null;
        $colaboradorId = isset($filtros['colaborador_id']) ? (int) $filtros['colaborador_id'] : null;

        return $query
            ->when($estado, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->when($colaboradorId, fn (Builder $q, int $v) => $q->where('colaborador_id', $v))
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(Prestamo $prestamo, bool $detalle = false): array
    {
        $prestamo->loadMissing(['colaborador', 'solicitud', 'contratoDocumento', 'pagareDocumento']);
        $solicitud = $prestamo->solicitud;

        $datos = [
            'id' => $prestamo->id,
            'colaborador' => ['id' => $prestamo->colaborador->id, 'nombre' => $prestamo->colaborador->nombreCompleto(), 'numero_empleado' => $prestamo->colaborador->numero_empleado],
            'solicitud_id' => $prestamo->solicitud_id,
            'folio' => $solicitud?->folio,
            'monto_solicitado' => $prestamo->monto_solicitado,
            'plazo_solicitado' => $prestamo->plazo_solicitado,
            'monto_autorizado' => $prestamo->monto_original,
            'plazo_autorizado' => $prestamo->plazo,
            'periodicidad' => $prestamo->periodicidad,
            'pago_programado' => $prestamo->pago_programado,
            'saldo_informativo' => $prestamo->saldo,
            'motivo' => $prestamo->motivo,
            'fecha_solicitud' => $prestamo->fecha_solicitud?->toDateString(),
            'estado' => $prestamo->estado,
            'autorizado_en' => $prestamo->autorizado_en?->toIso8601String(),
            'observaciones' => $prestamo->observaciones,
            'contrato' => $prestamo->contratoDocumento !== null ? ['id' => $prestamo->contratoDocumento->id, 'estado' => $prestamo->contratoDocumento->estado_flujo?->value] : null,
            'pagare' => $prestamo->pagareDocumento !== null ? ['id' => $prestamo->pagareDocumento->id, 'estado' => $prestamo->pagareDocumento->estado_flujo?->value] : null,
            'resguardado_en' => $prestamo->resguardado_en?->toIso8601String(),
        ];

        if ($detalle && $solicitud !== null) {
            $datos['vistos_buenos'] = $solicitud->aprobaciones()->with('usuario:id,name,apellidos')->get()->map(fn ($a) => [
                'nivel' => $a->nivel,
                'decision' => $a->decision,
                'usuario' => $a->usuario !== null ? trim($a->usuario->name.' '.$a->usuario->apellidos) : null,
                'comentario' => $a->comentario,
                'fecha' => $a->created_at?->toIso8601String(),
            ])->all();
            $datos['requiere_visto_bueno_jefe'] = $this->aprobaciones->requiereVistoBuenoJefe($solicitud);
            $datos['movimientos'] = $prestamo->movimientos()->get()->map(fn ($m) => [
                'fecha' => $m->fecha->toDateString(),
                'tipo' => $m->tipo,
                'monto' => $m->monto,
                'saldo_nuevo' => $m->saldo_nuevo,
            ])->all();
        }

        return $datos;
    }

    private function firmado(?GeneratedDocument $documento): bool
    {
        $estado = $documento?->estado_flujo;

        return $estado instanceof EstadoFlujoDocumento && $estado->estaFirmado();
    }
}
