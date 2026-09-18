<?php

namespace App\Services\MovimientosLaborales;

use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Enums\TipoMovimientoLaboral;
use App\Models\AltaDigital;
use App\Models\Colaborador;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\User;
use App\Models\Vacante;
use App\Services\MatrizComercial\MatrizComercialService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Única puerta de entrada para registrar histórico de movimientos laborales
 * (altas, bajas, promociones, cambios de puesto/sucursal/departamento/jefe/
 * empresa, coberturas temporales). Ningún controlador debe crear un
 * MovimientoLaboral directamente — ver docs/MOVIMIENTOS_LABORALES.md.
 *
 * El sujeto de un movimiento es siempre un Colaborador (puede o no tener
 * cuenta de acceso); $registradoPor es el actor que lo generó (siempre un
 * User con sesión).
 */
class MovimientoLaboralService
{
    public function __construct(
        private readonly VacanteAutoGenerationService $vacantesAutomaticas,
        private readonly MatrizComercialService $matriz,
    ) {}

    /**
     * Snapshot "antes" de un colaborador, tomado ANTES de aplicar cambios en
     * ExpedienteController::update()/UsuarioController::destroy() o al
     * cubrir una vacante. Se usa para diffear contra el estado ya guardado y
     * decidir qué tipos de movimiento registrar.
     *
     * @return array{empresa_id: int|null, sucursal_id: int|null, departamento_id: int|null, puesto_id: int|null, jefe_id: int|null, nivel_jerarquico: int|null, sueldo_mensual: string|null}
     */
    public function snapshot(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['sucursalPrincipal', 'puesto']);

        return [
            'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
            'sucursal_id' => $colaborador->sucursal_principal_id,
            'departamento_id' => $colaborador->departamento_id,
            'puesto_id' => $colaborador->puesto_id,
            'jefe_id' => $colaborador->jefe_id,
            'nivel_jerarquico' => $colaborador->puesto?->nivel_jerarquico,
            'sueldo_mensual' => $colaborador->sueldo_mensual,
        ];
    }

    public function registrarAlta(
        Colaborador $colaborador,
        User $registradoPor,
        ?AltaDigital $alta = null,
        ?int $vacanteId = null,
    ): MovimientoLaboral {
        $colaborador->loadMissing(['sucursalPrincipal']);

        $movimiento = MovimientoLaboral::create([
            'colaborador_id' => $colaborador->id,
            'user_id' => $colaborador->user?->id,
            'tipo_movimiento' => TipoMovimientoLaboral::Alta->value,
            'empresa_nueva_id' => $colaborador->sucursalPrincipal?->empresa_id,
            'sucursal_nueva_id' => $colaborador->sucursal_principal_id,
            'departamento_nuevo_id' => $colaborador->departamento_id,
            'puesto_nuevo_id' => $colaborador->puesto_id,
            'jefe_nuevo_id' => $colaborador->jefe?->user?->id,
            'jefe_nuevo_colaborador_id' => $colaborador->jefe_id,
            'vacante_id' => $vacanteId,
            'candidato_id' => $alta?->candidato_id,
            'alta_digital_id' => $alta?->id,
            'motivo' => 'Alta de colaborador',
            'fecha_movimiento' => $colaborador->fecha_ingreso ?? now(),
            'registrado_por' => $registradoPor->id,
        ]);

        // La plantilla actual acaba de subir con este alta: sincroniza la
        // vacante automática de (sucursal, puesto) para que sus plazas
        // bajen o se cierre sola si ya no hace falta (ver
        // App\Services\Vacantes\VacanteAutoGenerationService).
        if ($colaborador->sucursal_principal_id !== null && $colaborador->puesto_id !== null) {
            $this->vacantesAutomaticas->sincronizar($colaborador->sucursal_principal_id, $colaborador->puesto_id);
        }

        return $movimiento;
    }

    /**
     * Compara el snapshot "antes" (ver snapshot()) contra el estado actual
     * ya guardado de $colaborador y registra un movimiento por cada
     * dimensión que cambió. Detecta promoción automáticamente: si el puesto
     * cambió y el nuevo nivel jerárquico es numéricamente menor (más alto en
     * el organigrama), se registra como `promocion` en vez de
     * `cambio_puesto`.
     *
     * @param  array{empresa_id: int|null, sucursal_id: int|null, departamento_id: int|null, puesto_id: int|null, jefe_id: int|null, nivel_jerarquico: int|null, sueldo_mensual?: string|null}  $antes
     * @return array<int, MovimientoLaboral>
     */
    public function registrarCambioPuesto(
        Colaborador $colaborador,
        array $antes,
        User $registradoPor,
        ?string $motivo = null,
        ?int $vacanteId = null,
    ): array {
        $colaborador->loadMissing(['sucursalPrincipal', 'puesto']);

        $despues = $this->snapshot($colaborador);
        $movimientos = [];
        $base = [
            'colaborador_id' => $colaborador->id,
            'user_id' => $colaborador->user?->id,
        ];

        if ($antes['puesto_id'] !== $despues['puesto_id']) {
            $esPromocion = $antes['nivel_jerarquico'] !== null
                && $despues['nivel_jerarquico'] !== null
                && $despues['nivel_jerarquico'] < $antes['nivel_jerarquico'];

            $movimientos[] = MovimientoLaboral::create($base + [
                'tipo_movimiento' => $esPromocion ? TipoMovimientoLaboral::Promocion->value : TipoMovimientoLaboral::CambioPuesto->value,
                'puesto_anterior_id' => $antes['puesto_id'],
                'puesto_nuevo_id' => $despues['puesto_id'],
                'vacante_id' => $vacanteId,
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        if ($antes['sucursal_id'] !== $despues['sucursal_id']) {
            $movimientos[] = MovimientoLaboral::create($base + [
                'tipo_movimiento' => TipoMovimientoLaboral::CambioSucursal->value,
                'sucursal_anterior_id' => $antes['sucursal_id'],
                'sucursal_nueva_id' => $despues['sucursal_id'],
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        if ($antes['departamento_id'] !== $despues['departamento_id']) {
            $movimientos[] = MovimientoLaboral::create($base + [
                'tipo_movimiento' => TipoMovimientoLaboral::CambioDepartamento->value,
                'departamento_anterior_id' => $antes['departamento_id'],
                'departamento_nuevo_id' => $despues['departamento_id'],
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        if ($antes['jefe_id'] !== $despues['jefe_id']) {
            $jefeAnterior = $antes['jefe_id'] !== null ? Colaborador::find($antes['jefe_id']) : null;
            $jefeNuevo = $despues['jefe_id'] !== null ? Colaborador::find($despues['jefe_id']) : null;

            $movimientos[] = MovimientoLaboral::create($base + [
                'tipo_movimiento' => TipoMovimientoLaboral::CambioJefe->value,
                'jefe_anterior_id' => $jefeAnterior?->user?->id,
                'jefe_nuevo_id' => $jefeNuevo?->user?->id,
                'jefe_anterior_colaborador_id' => $antes['jefe_id'],
                'jefe_nuevo_colaborador_id' => $despues['jefe_id'],
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        if ($antes['empresa_id'] !== $despues['empresa_id']) {
            $movimientos[] = MovimientoLaboral::create($base + [
                'tipo_movimiento' => TipoMovimientoLaboral::CambioEmpresa->value,
                'empresa_anterior_id' => $antes['empresa_id'],
                'empresa_nueva_id' => $despues['empresa_id'],
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        // Un cambio de sueldo NUNCA debe registrarse (ni quedar mudo) como
        // "cambio de puesto": es un movimiento propio, aunque
        // movimientos_laborales no tenga columnas dedicadas para el monto
        // (se deja explícito en observaciones para el timeline del expediente).
        $sueldoAntes = $antes['sueldo_mensual'] ?? null;
        $sueldoDespues = $despues['sueldo_mensual'] ?? null;
        if ((string) $sueldoAntes !== (string) $sueldoDespues) {
            $movimientos[] = MovimientoLaboral::create($base + [
                'tipo_movimiento' => TipoMovimientoLaboral::CambioSueldo->value,
                'motivo' => $motivo,
                'observaciones' => sprintf('Sueldo mensual: $%s → $%s', $sueldoAntes ?? '0.00', $sueldoDespues ?? '0.00'),
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        return $movimientos;
    }

    /**
     * Registra la baja de un colaborador y, opcionalmente, genera la
     * vacante de reemplazo del puesto que deja.
     *
     * @return array{movimiento: MovimientoLaboral, vacante: Vacante|null}
     */
    public function registrarBaja(
        Colaborador $colaborador,
        User $registradoPor,
        ?string $motivo = null,
        bool $crearVacante = false,
    ): array {
        return DB::transaction(function () use ($colaborador, $registradoPor, $motivo, $crearVacante) {
            $colaborador->loadMissing(['sucursalPrincipal', 'puesto', 'departamento']);

            $this->matriz->cerrarAsignacionesDe($colaborador);

            $vacante = null;

            if ($crearVacante && $colaborador->puesto_id !== null) {
                $vacante = Vacante::create([
                    'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
                    'sucursal_id' => $colaborador->sucursal_principal_id,
                    'departamento_id' => $colaborador->departamento_id,
                    'puesto_id' => $colaborador->puesto_id,
                    'motivo' => MotivoVacante::BajaColaborador->value,
                    'estado' => EstadoVacante::Abierta->value,
                    'fecha_apertura' => now(),
                    'observaciones' => $motivo,
                    'creado_por' => $registradoPor->id,
                ]);
            }

            $movimiento = MovimientoLaboral::create([
                'colaborador_id' => $colaborador->id,
                'user_id' => $colaborador->user?->id,
                'tipo_movimiento' => TipoMovimientoLaboral::Baja->value,
                'empresa_anterior_id' => $colaborador->sucursalPrincipal?->empresa_id,
                'sucursal_anterior_id' => $colaborador->sucursal_principal_id,
                'departamento_anterior_id' => $colaborador->departamento_id,
                'puesto_anterior_id' => $colaborador->puesto_id,
                'jefe_anterior_id' => $colaborador->jefe?->user?->id,
                'jefe_anterior_colaborador_id' => $colaborador->jefe_id,
                'vacante_id' => $vacante?->id,
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);

            return ['movimiento' => $movimiento, 'vacante' => $vacante];
        });
    }

    public function registrarCoberturaTemporal(
        Colaborador $colaborador,
        Puesto $puesto,
        User $registradoPor,
        Carbon $inicio,
        ?Carbon $fin = null,
        ?string $observaciones = null,
        ?int $vacanteId = null,
    ): MovimientoLaboral {
        return MovimientoLaboral::create([
            'colaborador_id' => $colaborador->id,
            'user_id' => $colaborador->user?->id,
            'tipo_movimiento' => TipoMovimientoLaboral::CoberturaTemporal->value,
            'puesto_anterior_id' => $colaborador->puesto_id,
            'puesto_nuevo_id' => $puesto->id,
            'vacante_id' => $vacanteId,
            'observaciones' => $observaciones,
            'fecha_movimiento' => $inicio,
            'fecha_fin_cobertura' => $fin,
            'registrado_por' => $registradoPor->id,
        ]);
    }
}
