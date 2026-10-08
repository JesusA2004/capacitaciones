<?php

namespace App\Services\Colaboradores;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoUsuario;
use App\Enums\PrioridadTarea;
use App\Enums\TipoContratacion;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\OnboardingProceso;
use App\Models\User;
use App\Services\Asignaciones\AsignacionService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Organigrama\JefeDirectoService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
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
 * Etapa 2 (contratación y expediente digital) de un colaborador — alta
 * manual de RH o candidato autorizado (App\Services\Reclutamiento\ContratacionCandidatoService):
 *
 *   colaborador + estructura + sueldo + fecha de ingreso + contrato
 *   (vencimiento del periodo de prueba según el puesto)
 *   → checklist documental del catálogo (document_types obligatorios)
 *   → EXPEDIENTE COMPLETO = todos los obligatorios APROBADOS por RH
 *   → contratos del paquete configurado (sin plantilla = bloqueo explícito)
 *   → firma física / digital según cada plantilla
 *   → Etapa 3: onboarding (App\Services\Onboarding\OnboardingService)
 *   → activación (estatus Activo) al completar el onboarding.
 *
 * El estado (EstadoAltaColaborador) se recalcula siempre a partir de los
 * datos reales; nunca se captura a mano. Todo lo que escribe en BD al dar
 * de alta pasa en una sola transacción.
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
        private readonly AsignacionService $asignaciones,
        private readonly IdentidadColaboradorService $identidad,
        private readonly JefeDirectoService $jefes,
        private readonly AvisoAltaBajaService $avisoAltaBaja,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por AltaColaboradorRequest.
     */
    public function registrar(array $datos, User $actor, ?callable $dentroDeTransaccion = null): Colaborador
    {
        $this->identidad->validarNoDuplicado($datos);

        $tipo = TipoContratacion::from($datos['tipo_contratacion']);
        $inicio = Carbon::parse($datos['fecha_ingreso'])->startOfDay();
        $fin = isset($datos['fecha_fin_contrato']) ? Carbon::parse($datos['fecha_fin_contrato'])->startOfDay() : null;

        if ($fin === null && $tipo->tieneVencimiento()) {
            $fin = $this->contratos->fechaFinPeriodoPrueba(isset($datos['puesto_id']) ? (int) $datos['puesto_id'] : null, $inicio);
        }

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

            // Cuenta con o sin correo: usuario = primer nombre + primer apellido
            // (lo asigna User). RH le entrega acceso con «Generar credenciales».
            if ($datos['crear_acceso'] ?? true) {
                $usuario = User::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'name' => $colaborador->name,
                    'apellidos' => $colaborador->apellidos,
                    'email' => filled($datos['email'] ?? null) ? $datos['email'] : null,
                    'password' => Hash::make(Str::random(40)),
                ]);
                $usuario->assignRole('colaborador');
                $colaborador->setRelation('user', $usuario);

                // Capacitación (oculta tras el feature flag, ver
                // docs/CAPACITACION_PROXIMAMENTE.md): un colaborador nuevo
                // entra inscrito en lo que RH dejó vigente para su perfil.
                $this->asignaciones->aplicarVigentesA($usuario);
            }

            $this->movimientos->registrarAlta($colaborador, $actor, null, isset($datos['vacante_id']) ? (int) $datos['vacante_id'] : null);

            if ($dentroDeTransaccion !== null) {
                $dentroDeTransaccion($colaborador);
            }

            return ['colaborador' => $colaborador, 'contrato' => $contrato, 'usuario' => $usuario];
        });

        $colaborador = $resultado['colaborador'];

        // Jefe directo = organigrama (nunca capturado): se resuelve ya para
        // que tareas y avisos de esta alta lleguen a quien corresponde. Un
        // fallo aquí no deshace el alta (la sincronización diaria lo repara).
        try {
            $this->jefes->sincronizar($actor);
            $colaborador->refresh();
        } catch (Throwable $e) {
            Log::warning('AltaColaboradorService: no se pudo resolver el jefe directo desde el organigrama.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }

        $this->auditoria->registrar('colaborador_alta', $colaborador, $actor, [
            'colaborador_id' => $colaborador->id,
            'numero_empleado' => $colaborador->numero_empleado,
            'sucursal_id' => $colaborador->sucursal_principal_id,
            'puesto_id' => $colaborador->puesto_id,
            'tipo_contratacion' => $tipo->value,
            'sueldo_mensual' => $colaborador->sueldo_mensual,
            'candidato_id' => $colaborador->candidato_id,
        ]);

        // Después del commit: tareas y correo. Los contratos NO se generan
        // aquí: se generan cuando el expediente queda completo (todos los
        // obligatorios aprobados), ver recalcularEstado().
        $this->abrirTareasExpediente($colaborador);

        // Solo quien dio correo recibe el enlace; sin correo, RH usa «Generar credenciales».
        if ($resultado['usuario'] !== null && $resultado['usuario']->email !== null) {
            try {
                Password::broker()->sendResetLink(['email' => $resultado['usuario']->email]);
            } catch (Throwable $e) {
                Log::warning('AltaColaboradorService: no se pudo enviar el correo de acceso.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
            }
        }

        $this->recalcularEstado($colaborador, $actor);

        return $colaborador->refresh();
    }

    /**
     * Recalcula el estado a partir del expediente, los contratos y el
     * onboarding, y dispara lo que corresponde al nuevo estado:
     *  - expediente completo → genera los contratos que falten (si hay actor);
     *  - contratos firmados → inicia el onboarding y cierra la Etapa 1 del candidato.
     * Nunca retrocede un alta "activo" ni una "baja".
     */
    public function recalcularEstado(Colaborador $colaborador, ?User $actor = null): EstadoAltaColaborador
    {
        $actual = $colaborador->estado_alta;

        if ($actual === EstadoAltaColaborador::Baja || $actual === EstadoAltaColaborador::Activo) {
            return $actual;
        }

        $nuevo = $this->calcularEstado($colaborador);
        $contrato = $this->contratos->vigente($colaborador);

        if ($nuevo === EstadoAltaColaborador::PendienteContrato && $contrato !== null && $actor !== null) {
            if (config('documentos_maestros.alta_generacion_automatica')) {
                $this->contratos->prepararFaltantes($contrato, $actor);
                $this->notificarContratosListos($colaborador, $contrato);
                $nuevo = $this->calcularEstado($colaborador->refresh());
            } elseif ($actual !== EstadoAltaColaborador::PendienteContrato) {
                $this->avisarPaqueteDisponible($colaborador, $contrato);
            }
        }

        if ($nuevo !== $actual) {
            $colaborador->update(['estado_alta' => $nuevo]);
            $this->auditoria->registrar('alta_estado', $colaborador, $actor, [
                'colaborador_id' => $colaborador->id,
                'estado_anterior' => $actual?->value,
                'estado_nuevo' => $nuevo->value,
            ]);
        }

        if ($nuevo === EstadoAltaColaborador::EnOnboarding && $contrato !== null) {
            app(OnboardingService::class)->iniciar($colaborador, $contrato, $actor);
            app(ContratacionCandidatoService::class)->marcarContratado($colaborador, $actor);
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

        if (! in_array($nuevo, [EstadoAltaColaborador::PendienteDocumentos, EstadoAltaColaborador::DocumentacionEnRevision], true)) {
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

        if ($contrato === null || ! $this->contratos->paqueteGenerado($contrato)) {
            return EstadoAltaColaborador::PendienteContrato;
        }

        if (! $this->contratos->paqueteFirmado($contrato)) {
            return EstadoAltaColaborador::PendienteFirma;
        }

        if (! (bool) config('ciclo_laboral.onboarding.obligatorio', true)) {
            return EstadoAltaColaborador::PendienteActivacion;
        }

        $onboarding = OnboardingProceso::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('contrato_laboral_id', $contrato->id)
            ->latest('id')
            ->first();

        return $onboarding?->estado === EstadoOnboarding::Completado
            ? EstadoAltaColaborador::PendienteActivacion
            : EstadoAltaColaborador::EnOnboarding;
    }

    /**
     * Activación final (fin de la Etapa 3): expediente aprobado, contratos
     * firmados y onboarding completado. Deja al colaborador Activo (inicia
     * operación en campo) y sincroniza vacantes/plantilla.
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
                    'colaborador' => "No se puede activar todavía: está en «{$estado->etiqueta()}». Se requiere expediente aprobado, contratos firmados y onboarding completado.",
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
        $this->auditoria->registrar('colaborador_activado', $colaborador, $actor, ['colaborador_id' => $colaborador->id]);

        $usuario = $colaborador->user;

        if ($usuario !== null) {
            $this->notificador->notificar([$usuario], 'alta_activada', '¡Bienvenido a MR. LANA!', '¡Listo! Ya tienes acceso completo a tu portal.', $colaborador, null, 'media');
        }

        // ALTA → RH + Sistemas (cuenta, accesos y equipo).
        $this->avisoAltaBaja->alta($colaborador);

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

        $contractuales = $contrato === null ? [] : $this->contratos->documentosDelContrato($contrato)
            ->map(fn (GeneratedDocument $d) => [
                'id' => $d->id,
                'clave' => $d->clave_plantilla,
                'titulo' => $d->titulo,
                'estado' => $d->estado_flujo?->value,
                'estado_etiqueta' => $d->estado_flujo?->etiqueta(),
                'firmado' => $this->contratos->documentoFirmadoSegunFlujo($d),
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

    /**
     * Expediente completo: en la ficha del colaborador aparece "Generar
     * paquete de contratación" (no se manda a Formatos/Plantillas).
     */
    private function avisarPaqueteDisponible(Colaborador $colaborador, ContratoLaboral $contrato): void
    {
        try {
            $this->tareas->abrir(TipoTarea::ContratoPendiente, $contrato, [
                'titulo' => sprintf('Generar paquete de contratación: %s', $colaborador->nombreCompleto()),
                'descripcion' => 'El expediente quedó completo. Desde la ficha del colaborador genera el paquete de contratación de su puesto.',
                'prioridad' => PrioridadTarea::Alta,
                'colaborador' => $colaborador,
                'permiso' => ContratoLaboralService::PERMISO_GENERAR,
                'accion' => 'generar_paquete_contratacion',
            ]);

            $this->notificador->notificarEvento(
                'expediente_completo',
                $colaborador,
                [],
                'Expediente completo',
                sprintf('El expediente de %s está completo: genera su paquete de contratación desde su ficha.', $colaborador->nombreCompleto()),
                $colaborador,
                'ver_documentos_colaborador',
                'alta',
            );
        } catch (Throwable $e) {
            Log::warning('AltaColaboradorService: no se pudo avisar que el paquete está disponible.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }
    }

    private function notificarContratosListos(Colaborador $colaborador, ContratoLaboral $contrato): void
    {
        $listos = $this->contratos->documentosDelContrato($contrato)->filter(fn (GeneratedDocument $d) => $d->requiere_impresion || $d->requiere_firma_fisica);

        if ($listos->isEmpty()) {
            return;
        }

        $this->notificador->notificarEvento(
            'contratos_listos',
            $colaborador,
            [],
            'Contratos listos para imprimir',
            sprintf('Los contratos de %s están listos: imprime, recaba firma y huella.', $colaborador->nombreCompleto()),
            $contrato,
            'imprimir_contratos',
            'alta',
        );
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
     * Siguiente número de empleado con el formato ya usado en el proyecto
     * (EMP-0001). El índice único de la columna protege contra carreras.
     */
    private function siguienteNumeroEmpleado(): string
    {
        return app(NumeroEmpleadoService::class)->siguiente();
    }
}
