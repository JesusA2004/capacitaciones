<?php

namespace App\Services\MovimientosLaborales;

use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Enums\TipoMovimientoLaboral;
use App\Models\AltaDigital;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\User;
use App\Models\Vacante;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Única puerta de entrada para registrar histórico de movimientos laborales
 * (altas, bajas, promociones, cambios de puesto/sucursal/departamento/jefe/
 * empresa, coberturas temporales). Ningún controlador debe crear un
 * MovimientoLaboral directamente — ver docs/MOVIMIENTOS_LABORALES.md.
 */
class MovimientoLaboralService
{
    /**
     * Snapshot "antes" de un colaborador, tomado ANTES de aplicar cambios en
     * UsuarioController::update()/destroy() o al cubrir una vacante. Se usa
     * para diffear contra el estado ya guardado y decidir qué tipos de
     * movimiento registrar.
     *
     * @return array{empresa_id: int|null, sucursal_id: int|null, departamento_id: int|null, puesto_id: int|null, jefe_id: int|null, nivel_jerarquico: int|null}
     */
    public function snapshot(User $usuario): array
    {
        $usuario->loadMissing(['sucursalPrincipal', 'puesto']);

        return [
            'empresa_id' => $usuario->sucursalPrincipal?->empresa_id,
            'sucursal_id' => $usuario->sucursal_principal_id,
            'departamento_id' => $usuario->departamento_id,
            'puesto_id' => $usuario->puesto_id,
            'jefe_id' => $usuario->jefe_id,
            'nivel_jerarquico' => $usuario->puesto?->nivel_jerarquico,
        ];
    }

    public function registrarAlta(
        User $usuario,
        User $registradoPor,
        ?AltaDigital $alta = null,
        ?int $vacanteId = null,
    ): MovimientoLaboral {
        $usuario->loadMissing(['sucursalPrincipal']);

        return MovimientoLaboral::create([
            'user_id' => $usuario->id,
            'tipo_movimiento' => TipoMovimientoLaboral::Alta->value,
            'empresa_nueva_id' => $usuario->sucursalPrincipal?->empresa_id,
            'sucursal_nueva_id' => $usuario->sucursal_principal_id,
            'departamento_nuevo_id' => $usuario->departamento_id,
            'puesto_nuevo_id' => $usuario->puesto_id,
            'jefe_nuevo_id' => $usuario->jefe_id,
            'vacante_id' => $vacanteId,
            'candidato_id' => $alta?->candidato_id,
            'alta_digital_id' => $alta?->id,
            'motivo' => 'Alta de colaborador',
            'fecha_movimiento' => $usuario->fecha_ingreso ?? now(),
            'registrado_por' => $registradoPor->id,
        ]);
    }

    /**
     * Compara el snapshot "antes" (ver snapshot()) contra el estado actual
     * ya guardado de $usuario y registra un movimiento por cada dimensión
     * que cambió. Detecta promoción automáticamente: si el puesto cambió y
     * el nuevo nivel jerárquico es numéricamente menor (más alto en el
     * organigrama), se registra como `promocion` en vez de `cambio_puesto`.
     *
     * @param  array{empresa_id: int|null, sucursal_id: int|null, departamento_id: int|null, puesto_id: int|null, jefe_id: int|null, nivel_jerarquico: int|null}  $antes
     * @return array<int, MovimientoLaboral>
     */
    public function registrarCambioPuesto(
        User $usuario,
        array $antes,
        User $registradoPor,
        ?string $motivo = null,
        ?int $vacanteId = null,
    ): array {
        $usuario->loadMissing(['sucursalPrincipal', 'puesto']);

        $despues = $this->snapshot($usuario);
        $movimientos = [];

        if ($antes['puesto_id'] !== $despues['puesto_id']) {
            $esPromocion = $antes['nivel_jerarquico'] !== null
                && $despues['nivel_jerarquico'] !== null
                && $despues['nivel_jerarquico'] < $antes['nivel_jerarquico'];

            $movimientos[] = MovimientoLaboral::create([
                'user_id' => $usuario->id,
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
            $movimientos[] = MovimientoLaboral::create([
                'user_id' => $usuario->id,
                'tipo_movimiento' => TipoMovimientoLaboral::CambioSucursal->value,
                'sucursal_anterior_id' => $antes['sucursal_id'],
                'sucursal_nueva_id' => $despues['sucursal_id'],
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        if ($antes['departamento_id'] !== $despues['departamento_id']) {
            $movimientos[] = MovimientoLaboral::create([
                'user_id' => $usuario->id,
                'tipo_movimiento' => TipoMovimientoLaboral::CambioDepartamento->value,
                'departamento_anterior_id' => $antes['departamento_id'],
                'departamento_nuevo_id' => $despues['departamento_id'],
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        if ($antes['jefe_id'] !== $despues['jefe_id']) {
            $movimientos[] = MovimientoLaboral::create([
                'user_id' => $usuario->id,
                'tipo_movimiento' => TipoMovimientoLaboral::CambioJefe->value,
                'jefe_anterior_id' => $antes['jefe_id'],
                'jefe_nuevo_id' => $despues['jefe_id'],
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);
        }

        if ($antes['empresa_id'] !== $despues['empresa_id']) {
            $movimientos[] = MovimientoLaboral::create([
                'user_id' => $usuario->id,
                'tipo_movimiento' => TipoMovimientoLaboral::CambioEmpresa->value,
                'empresa_anterior_id' => $antes['empresa_id'],
                'empresa_nueva_id' => $despues['empresa_id'],
                'motivo' => $motivo,
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
        User $usuario,
        User $registradoPor,
        ?string $motivo = null,
        bool $crearVacante = false,
    ): array {
        return DB::transaction(function () use ($usuario, $registradoPor, $motivo, $crearVacante) {
            $usuario->loadMissing(['sucursalPrincipal', 'puesto', 'departamento']);

            $vacante = null;

            if ($crearVacante && $usuario->puesto_id !== null) {
                $vacante = Vacante::create([
                    'empresa_id' => $usuario->sucursalPrincipal?->empresa_id,
                    'sucursal_id' => $usuario->sucursal_principal_id,
                    'departamento_id' => $usuario->departamento_id,
                    'puesto_id' => $usuario->puesto_id,
                    'motivo' => MotivoVacante::BajaColaborador->value,
                    'estado' => EstadoVacante::Abierta->value,
                    'fecha_apertura' => now(),
                    'observaciones' => $motivo,
                    'creado_por' => $registradoPor->id,
                ]);
            }

            $movimiento = MovimientoLaboral::create([
                'user_id' => $usuario->id,
                'tipo_movimiento' => TipoMovimientoLaboral::Baja->value,
                'empresa_anterior_id' => $usuario->sucursalPrincipal?->empresa_id,
                'sucursal_anterior_id' => $usuario->sucursal_principal_id,
                'departamento_anterior_id' => $usuario->departamento_id,
                'puesto_anterior_id' => $usuario->puesto_id,
                'jefe_anterior_id' => $usuario->jefe_id,
                'vacante_id' => $vacante?->id,
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
                'registrado_por' => $registradoPor->id,
            ]);

            return ['movimiento' => $movimiento, 'vacante' => $vacante];
        });
    }

    public function registrarCoberturaTemporal(
        User $usuario,
        Puesto $puesto,
        User $registradoPor,
        Carbon $inicio,
        ?Carbon $fin = null,
        ?string $observaciones = null,
        ?int $vacanteId = null,
    ): MovimientoLaboral {
        return MovimientoLaboral::create([
            'user_id' => $usuario->id,
            'tipo_movimiento' => TipoMovimientoLaboral::CoberturaTemporal->value,
            'puesto_anterior_id' => $usuario->puesto_id,
            'puesto_nuevo_id' => $puesto->id,
            'vacante_id' => $vacanteId,
            'observaciones' => $observaciones,
            'fecha_movimiento' => $inicio,
            'fecha_fin_cobertura' => $fin,
            'registrado_por' => $registradoPor->id,
        ]);
    }
}
