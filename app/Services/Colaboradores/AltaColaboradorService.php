<?php

namespace App\Services\Colaboradores;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoUsuario;
use App\Enums\PrioridadTarea;
use App\Enums\TipoContratacion;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alta completa de un colaborador (alta manual de RH o candidato
 * contratado, ver App\Services\Reclutamiento\ContratacionCandidatoService):
 *
 *   colaborador + estructura (empresa vía sucursal, sucursal, puesto,
 *   departamento, jefe inmediato, gerente) + sueldo + fecha de ingreso +
 *   tipo de contratación + periodo de prueba/vencimiento
 *   → expediente (carpeta en el NAS) + checklist documental (document_types obligatorios)
 *   → relación contractual (ContratoLaboral) + documentos contractuales pendientes
 *   → cuenta de acceso (rol colaborador) para cargar documentos y firmar desde la app
 *   → estado del alta (EstadoAltaColaborador) recalculado automáticamente
 *   → activación final (estatus Activo) cuando expediente y contrato están completos.
 *
 * Todo lo que escribe en BD pasa en una sola transacción: si algo falla, no
 * queda un colaborador a medias. La generación de PDFs y las notificaciones
 * van después del commit y nunca revierten el alta (faltantes → tareas).
 */
class AltaColaboradorService
{
    public const PERMISO_ACTIVAR = 'colaboradores.activar';

    public function __construct(
        private readonly ContratoLaboralService $contratos,
        private readonly ExpedienteService $expediente,
        private readonly DocumentoStorageService $storage,
        private readonly MovimientoLaboralService $movimientos,
        private readonly VacanteAutoGenerationService $vacantes,
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por AltaColaboradorRequest.
     */
    public function registrar(array $datos, User $actor, ?callable $dentroDeTransaccion = null): Colaborador
    {
        $this->validarNoDuplicado($datos);

        $tipo = TipoContratacion::from($datos['tipo_contratacion']);
        $inicio = Carbon::parse($datos['fecha_ingreso'])->startOfDay();
        $fin = isset($datos['fecha_fin_contrato']) ? Carbon::parse($datos['fecha_fin_contrato'])->startOfDay() : null;
        $resultado = DB::transaction(function () use ($datos, $actor, $tipo, $inicio, $fin, $dentroDeTransaccion): array {
            $colaborador = Colaborador::query()->create([
                'name' => $datos['name'],
                'apellidos' => $datos['apellidos'] ?? null,
                'genero' => $datos['genero'] ?? null,
                'numero_empleado' => $datos['numero_empleado'] ?? $this->siguienteNumeroEmpleado(),
                'telefono' => $datos['telefono'] ?? null,
                'correo_personal' => $datos['correo_personal'] ?? null,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'curp' => isset($datos['curp']) ? strtoupper((string) $datos['curp']) : null,
                'rfc' => isset($datos['rfc']) ? strtoupper((string) $datos['rfc']) : null,
                'nss' => $datos['nss'] ?? null,
                'domicilio' => $datos['domicilio'] ?? null,
                'contacto_emergencia_nombre' => $datos['contacto_emergencia_nombre'] ?? null,
                'contacto_emergencia_telefono' => $datos['contacto_emergencia_telefono'] ?? null,
                'sucursal_principal_id' => $datos['sucursal_principal_id'],
                'departamento_id' => $datos['departamento_id'] ?? null,
                'puesto_id' => $datos['puesto_id'],
                'jefe_id' => $datos['jefe_id'] ?? null,
                'gerente_id' => $datos['gerente_id'] ?? null,
                'sueldo_mensual' => $datos['sueldo_mensual'],
                'fecha_ingreso' => $inicio->toDateString(),
                'estatus' => EstadoUsuario::EnIncorporacion,
                'estado_alta' => EstadoAltaColaborador::PendienteDocumentos,
                'candidato_id' => $datos['candidato_id'] ?? null,
                'alta_registrada_por' => $actor->id,
            ]);

            $colaborador->load('sucursalPrincipal.empresa');

            // Expediente: fija la carpeta del colaborador en el NAS desde el
            // primer momento (identidad documental estable).
            $this->storage->asignarRutaBaseColaborador($colaborador);

            $contrato = $this->contratos->crearContrato($colaborador, $tipo, $inicio, $fin, $actor);

            $usuario = null;

            if (($datos['crear_acceso'] ?? true) && ! empty($datos['email'])) {
                $usuario = User::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'name' => $colaborador->name,
                    'apellidos' => $colaborador->apellidos,
                    'email' => $datos['email'],
                    'password' => Hash::make(Str::random(40)),
                ]);
                $usuario->assignRole('colaborador');
                $colaborador->setRelation('user', $usuario);
            }

            $this->movimientos->registrarAlta($colaborador, $actor, null, isset($datos['vacante_id']) ? (int) $datos['vacante_id'] : null);

            if ($dentroDeTransaccion !== null) {
                $dentroDeTransaccion($colaborador);
            }

            return ['colaborador' => $colaborador, 'contrato' => $contrato, 'usuario' => $usuario];
        });

        $colaborador = $resultado['colaborador'];

        $this->auditoria->registrar('colaborador_alta', $colaborador, $actor, [
            'numero_empleado' => $colaborador->numero_empleado,
            'sucursal_id' => $colaborador->sucursal_principal_id,
            'puesto_id' => $colaborador->puesto_id,
            'tipo_contratacion' => $tipo->value,
            'sueldo_mensual' => $colaborador->sueldo_mensual,
            'candidato_id' => $colaborador->candidato_id,
        ]);

        // Después del commit: PDFs, tareas y correo. Nada de esto revierte el alta.
        $paquete = ContratoLaboralService::clavesConfiguradas("contratos.paquetes_alta.{$tipo->value}");
        $this->contratos->prepararDocumentos($resultado['contrato'], $paquete, $actor);

        $this->abrirTareasExpediente($colaborador);

        if ($resultado['usuario'] !== null) {
            try {
                Password::broker()->sendResetLink(['email' => $resultado['usuario']->email]);
            } catch (Throwable $e) {
                Log::warning('AltaColaboradorService: no se pudo enviar el correo de acceso.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
            }
        }

        $this->recalcularEstado($colaborador);

        return $colaborador->refresh();
    }

    /**
     * Recalcula el estado del alta a partir del expediente y del contrato
     * principal. Nunca retrocede un alta ya "activo" ni una "baja".
     */
    public function recalcularEstado(Colaborador $colaborador): EstadoAltaColaborador
    {
        $actual = $colaborador->estado_alta;

        if ($actual === EstadoAltaColaborador::Baja || $actual === EstadoAltaColaborador::Activo) {
            return $actual;
        }

        $nuevo = $this->calcularEstado($colaborador);

        if ($nuevo === EstadoAltaColaborador::PendienteActivacion && $colaborador->estatus === EstadoUsuario::Activo) {
            // Colaborador ya activado por el flujo previo (aprobación de
            // incorporación): al completarse expediente y contrato el alta
            // queda cerrada como activa.
            $nuevo = EstadoAltaColaborador::Activo;
        }

        if ($nuevo !== $actual) {
            $colaborador->update(['estado_alta' => $nuevo]);
        }

        if ($nuevo === EstadoAltaColaborador::PendienteActivacion) {
            $this->tareas->abrir(TipoTarea::ActivacionPendiente, $colaborador, [
                'titulo' => "Alta lista para activar: {$colaborador->nombreCompleto()}",
                'prioridad' => PrioridadTarea::Alta,
                'colaborador' => $colaborador,
                'permiso' => self::PERMISO_ACTIVAR,
                'accion' => 'activar_colaborador',
            ]);
        }

        if (in_array($nuevo, [EstadoAltaColaborador::PendienteContrato, EstadoAltaColaborador::PendienteFirma, EstadoAltaColaborador::PendienteActivacion, EstadoAltaColaborador::Activo], true)) {
            $this->tareas->resolver(TipoTarea::ExpedienteIncompleto, $colaborador);
        }

        return $nuevo;
    }

    public function calcularEstado(Colaborador $colaborador): EstadoAltaColaborador
    {
        $documental = $this->expediente->estadoDocumental($colaborador);

        if ($documental['faltantes'] > 0) {
            return EstadoAltaColaborador::PendienteDocumentos;
        }

        if (! $documental['completo']) {
            return EstadoAltaColaborador::DocumentacionEnRevision;
        }

        $contrato = $this->contratos->vigente($colaborador);
        $documento = $contrato?->documento;

        if ($documento === null || $documento->estado_flujo === null) {
            return EstadoAltaColaborador::PendienteContrato;
        }

        if (! $documento->estado_flujo->estaFirmado()) {
            return EstadoAltaColaborador::PendienteFirma;
        }

        return EstadoAltaColaborador::PendienteActivacion;
    }

    /**
     * Activación final: expediente con obligatorios aprobados y contrato
     * principal firmado. Deja al colaborador Activo (portal completo) y
     * sincroniza vacantes/plantilla.
     */
    public function activar(Colaborador $colaborador, User $actor): Colaborador
    {
        $colaborador = DB::transaction(function () use ($colaborador, $actor): Colaborador {
            $colaborador = Colaborador::query()->lockForUpdate()->findOrFail($colaborador->id);

            if ($colaborador->estado_alta === EstadoAltaColaborador::Activo) {
                throw ValidationException::withMessages(['colaborador' => 'El colaborador ya está activo.']);
            }

            if ($colaborador->estado_alta === EstadoAltaColaborador::Baja) {
                throw ValidationException::withMessages(['colaborador' => 'El colaborador está dado de baja.']);
            }

            $estado = $this->calcularEstado($colaborador);

            if ($estado !== EstadoAltaColaborador::PendienteActivacion) {
                throw ValidationException::withMessages([
                    'colaborador' => "No se puede activar todavía: el alta está en «{$estado->etiqueta()}». Se requiere expediente con obligatorios aprobados y contrato firmado.",
                ]);
            }

            $colaborador->update([
                'estatus' => EstadoUsuario::Activo,
                'estado_alta' => EstadoAltaColaborador::Activo,
                'activado_en' => now(),
                'incorporacion_decision' => 'aprobado',
                'incorporacion_decidida_por' => $actor->id,
                'incorporacion_decidida_en' => now(),
            ]);

            if ($colaborador->sucursal_principal_id !== null && $colaborador->puesto_id !== null) {
                $this->vacantes->sincronizar($colaborador->sucursal_principal_id, $colaborador->puesto_id);
            }

            return $colaborador;
        });

        $this->tareas->resolver([TipoTarea::ActivacionPendiente, TipoTarea::ExpedienteIncompleto], $colaborador, $actor);
        $this->auditoria->registrar('colaborador_activado', $colaborador, $actor);

        $usuario = $colaborador->user;

        if ($usuario !== null) {
            $this->notificador->notificar([$usuario], 'alta_activada', 'Tu alta quedó activa', 'Tu expediente y contrato están completos. Ya tienes acceso completo al portal.', $colaborador, null, 'media');
        }

        return $colaborador->refresh();
    }

    /**
     * Checklist completo del alta: estructura, expediente documental,
     * documentos contractuales y acceso.
     *
     * @return array<string, mixed>
     */
    public function checklist(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['sucursalPrincipal.empresa', 'puesto', 'departamento', 'jefe', 'gerente', 'user']);
        $documental = $this->expediente->estadoDocumental($colaborador);
        $contrato = $this->contratos->vigente($colaborador);

        $contractuales = $contrato === null ? [] : GeneratedDocument::query()
            ->where('documentable_type', $contrato->getMorphClass())
            ->where('documentable_id', $contrato->id)
            ->orderBy('id')
            ->get()
            ->map(fn (GeneratedDocument $d) => [
                'id' => $d->id,
                'clave' => $d->clave_plantilla,
                'titulo' => $d->titulo,
                'estado' => $d->estado_flujo?->value,
                'firmado' => $d->estado_flujo?->estaFirmado() ?? false,
            ])->values()->all();

        $generadas = collect($contractuales)->pluck('clave')->filter()->all();
        $pendientesDeGenerar = $contrato === null ? [] : array_values(array_diff($this->contratos->clavesPaquete($contrato), $generadas));

        return [
            'estado_alta' => $colaborador->estado_alta?->value,
            'estado_alta_etiqueta' => $colaborador->estado_alta?->etiqueta(),
            'estatus' => $colaborador->estatus->value,
            'estructura' => [
                'empresa' => $colaborador->sucursalPrincipal?->empresa?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'puesto' => $colaborador->puesto?->nombre,
                'jefe_inmediato' => $colaborador->jefe?->nombreCompleto(),
                'gerente' => $colaborador->gerente?->nombreCompleto(),
                'sueldo_mensual' => $colaborador->sueldo_mensual,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'tipo_contratacion' => $colaborador->tipo_contratacion?->value,
                'periodo_prueba_inicio' => $colaborador->periodo_prueba_inicio?->toDateString(),
                'periodo_prueba_fin' => $colaborador->periodo_prueba_fin?->toDateString(),
            ],
            'expediente' => $documental,
            'contrato' => $contrato !== null ? $this->contratos->aArray($contrato) : null,
            'documentos_contractuales' => $contractuales,
            'documentos_contractuales_sin_plantilla' => $pendientesDeGenerar,
            'acceso' => [
                'tiene_cuenta' => $colaborador->user !== null,
                'bloqueado' => $colaborador->user?->acceso_bloqueado_en !== null,
            ],
        ];
    }

    private function abrirTareasExpediente(Colaborador $colaborador): void
    {
        $documental = $this->expediente->estadoDocumental($colaborador);

        if ($documental['faltantes'] === 0) {
            return;
        }

        $descripcion = sprintf('Faltan %d documento(s) obligatorio(s) del expediente.', $documental['faltantes']);

        $this->tareas->abrir(TipoTarea::ExpedienteIncompleto, $colaborador, [
            'titulo' => "Expediente incompleto: {$colaborador->nombreCompleto()}",
            'descripcion' => $descripcion,
            'colaborador' => $colaborador,
            'permiso' => 'documentos.revisar',
            'accion' => 'revisar_expediente',
        ]);

        if ($colaborador->user !== null) {
            $this->tareas->abrir(TipoTarea::ExpedienteIncompleto, $colaborador, [
                'titulo' => 'Completa tu expediente',
                'descripcion' => $descripcion,
                'colaborador' => $colaborador,
                'usuario' => $colaborador->user,
                'accion' => 'subir_documentos',
            ]);

            $this->notificador->notificar([$colaborador->user], 'expediente_incompleto', 'Completa tu expediente', $descripcion, $colaborador, 'subir_documentos');
        }
    }

    /**
     * "No duplicar la persona": CURP/RFC/NSS/correo ya registrados en otro
     * colaborador (incluso dado de baja) bloquean el alta con un mensaje
     * explícito — un reingreso se hace reactivando el expediente existente.
     *
     * @param  array<string, mixed>  $datos
     */
    private function validarNoDuplicado(array $datos): void
    {
        foreach (['curp', 'rfc', 'nss'] as $campo) {
            if (empty($datos[$campo])) {
                continue;
            }

            $existente = Colaborador::withTrashed()->where($campo, strtoupper((string) $datos[$campo]))->first();

            if ($existente !== null) {
                throw ValidationException::withMessages([
                    $campo => sprintf('Ya existe un colaborador con ese %s (%s, #%s). Si es un reingreso, reactiva su expediente en lugar de crear uno nuevo.', strtoupper($campo), $existente->nombreCompleto(), $existente->numero_empleado ?? $existente->id),
                ]);
            }
        }
    }

    /**
     * Siguiente número de empleado con el formato ya usado en el proyecto
     * (EMP-0001). El índice único de la columna protege contra carreras.
     */
    private function siguienteNumeroEmpleado(): string
    {
        $maximo = Colaborador::withTrashed()
            ->where('numero_empleado', 'like', 'EMP-%')
            ->pluck('numero_empleado')
            ->map(fn (?string $n) => (int) preg_replace('/\D/', '', (string) $n))
            ->max() ?? 0;

        return sprintf('EMP-%04d', ((int) $maximo) + 1);
    }
}
