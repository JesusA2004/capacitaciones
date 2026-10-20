<?php

namespace App\Services\Expedientes;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Compensacion\CompensacionService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Organigrama\JefeDirectoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ÚNICO escritor de los datos personales/de contacto y laborales del
 * expediente de un colaborador. Lo usan la pestaña «Datos» del expediente
 * (Rh\ExpedienteController) y la edición rápida del Directorio
 * (DirectorioEdicionService): la misma regla para ambas pantallas.
 *
 * La autorización (permiso, alcance y «nadie edita lo suyo») la resuelve
 * quien llama (Form Request), nunca este service.
 */
class DatosExpedienteService
{
    /** Campos laborales estructurales; el sueldo va aparte por CompensacionService. */
    public const CAMPOS_LABORALES = ['sucursal_principal_id', 'departamento_id', 'puesto_id'];

    public function __construct(
        private readonly MovimientoLaboralService $movimientos,
        private readonly JefeDirectoService $jefes,
        private readonly CompensacionService $compensacion,
    ) {}

    /**
     * Datos personales y de contacto ya validados (solo las llaves
     * presentes se escriben).
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarPersonales(Colaborador $colaborador, array $datos): void
    {
        if ($datos !== []) {
            $colaborador->update($datos);
        }
    }

    /**
     * Cambia sucursal (y con ella la empresa), departamento, puesto y,
     * opcionalmente, sueldo mensual — sin pasar por una vacante. Mismo
     * patrón snapshot → update → registrarCambioPuesto() que
     * VacanteController::cubrir(), aquí sin vacante.
     *
     * El sueldo solo se toca si la llave `sueldo_mensual` viene en $datos
     * (el formulario del expediente siempre la manda; el directorio no la
     * edita). Devuelve true si no se pudo asignar el sueldo por defecto
     * porque falta SALARIO_MINIMO_DIARIO (aviso para la pantalla).
     *
     * @param  array<string, mixed>  $datos  sucursal_principal_id?, departamento_id?, puesto_id?, sueldo_mensual?, motivo?
     */
    public function actualizarLaborales(Colaborador $colaborador, array $datos, User $actor): bool
    {
        $antes = $this->movimientos->snapshot($colaborador);
        $estructurales = array_intersect_key($datos, array_flip(self::CAMPOS_LABORALES));
        $motivo = is_string($datos['motivo'] ?? null) && $datos['motivo'] !== '' ? $datos['motivo'] : null;

        // Un campo de sueldo vacío NUNCA borra un sueldo que ya existía (el
        // middleware ConvertEmptyStringsToNull convierte "" en null). Solo se
        // completa con el salario mínimo cuando el colaborador NUNCA tuvo un
        // sueldo capturado. Si falta SALARIO_MINIMO_DIARIO no se inventa un
        // monto (config/nomina.php): se avisa y se guarda sin sueldo.
        $nuevoSueldoMensual = null;
        $avisoSueldoSinConfigurar = false;

        if (array_key_exists('sueldo_mensual', $datos)) {
            $sueldoCapturado = $datos['sueldo_mensual'];

            if (! empty($sueldoCapturado) && is_numeric($sueldoCapturado)) {
                $nuevoSueldoMensual = (float) $sueldoCapturado;
            } elseif ($colaborador->sueldo_mensual === null) {
                $salarioMinimoMensual = config('nomina.salario_minimo_mensual');

                if ($salarioMinimoMensual !== null) {
                    $nuevoSueldoMensual = (float) $salarioMinimoMensual;
                } else {
                    $avisoSueldoSinConfigurar = true;
                    Log::warning('DatosExpedienteService::actualizarLaborales(): SALARIO_MINIMO_DIARIO no está definida en el entorno — no se pudo asignar un sueldo por defecto.', [
                        'colaborador_id' => $colaborador->id,
                    ]);
                }
            }
        }

        DB::transaction(function () use ($colaborador, $estructurales, $antes, $actor, $motivo, $nuevoSueldoMensual): void {
            $this->aplicarEstructurales([['colaborador' => $colaborador, 'antes' => $antes, 'cambios' => $estructurales]], $actor, $motivo);

            // Sueldo canónico: CompensacionService sincroniza mensual + diario
            // + ledger. Solo si de verdad cambia el diario.
            if ($nuevoSueldoMensual !== null && $nuevoSueldoMensual > 0) {
                $diarioNuevo = $this->compensacion->diarioDesdeMensual($nuevoSueldoMensual);
                $diarioActual = $colaborador->salario_diario !== null ? (float) $colaborador->salario_diario : null;

                if ($diarioActual === null || abs($diarioActual - $diarioNuevo) > 0.001) {
                    $this->compensacion->actualizarSalario($colaborador, $diarioNuevo, null, $actor, [
                        'fuente' => 'datos_laborales',
                        'motivo' => $motivo ?? 'Actualización de sueldo desde Datos laborales.',
                    ]);
                }
            }
        });

        return $avisoSueldoSinConfigurar;
    }

    /**
     * Cambia el departamento de varios colaboradores con la MISMA regla que
     * la pestaña «Datos laborales» del expediente (snapshot → update →
     * jefe derivado del organigrama → movimiento laboral por persona), pero
     * recalculando el organigrama una sola vez para todo el lote. Lo usa
     * «Departamentos → mover personas» (MovimientoDepartamentoService).
     *
     * La autorización y el filtrado (alcance, «nadie se mueve a sí mismo»,
     * idempotencia) los resuelve quien llama.
     *
     * @param  iterable<Colaborador>  $colaboradores
     */
    public function cambiarDepartamentoEnLote(iterable $colaboradores, int $departamentoId, User $actor, ?string $motivo): void
    {
        $lote = [];

        foreach ($colaboradores as $colaborador) {
            $lote[] = [
                'colaborador' => $colaborador,
                'antes' => $this->movimientos->snapshot($colaborador),
                'cambios' => ['departamento_id' => $departamentoId],
            ];
        }

        if ($lote === []) {
            return;
        }

        DB::transaction(fn () => $this->aplicarEstructurales($lote, $actor, $motivo));
    }

    /**
     * Núcleo compartido de los cambios estructurales (sucursal, departamento,
     * puesto): escribe, recalcula el jefe derivado del organigrama UNA vez y
     * registra los movimientos laborales de cada persona contra su snapshot.
     * Debe llamarse dentro de una transacción.
     *
     * @param  list<array{colaborador: Colaborador, antes: array{empresa_id: int|null, sucursal_id: int|null, departamento_id: int|null, puesto_id: int|null, jefe_id: int|null, nivel_jerarquico: int|null, sueldo_mensual: string|null}, cambios: array<string, mixed>}>  $lote
     */
    private function aplicarEstructurales(array $lote, User $actor, ?string $motivo): void
    {
        foreach ($lote as $item) {
            $item['colaborador']->update($item['cambios']);
        }

        // El jefe sale del organigrama: se recalcula YA para que el
        // movimiento laboral registre el cambio de jefe.
        $this->jefes->sincronizar($actor);

        // El snapshot no cambió el sueldo: aquí solo salen los movimientos
        // estructurales (puesto/sucursal/depto/jefe).
        foreach ($lote as $item) {
            $this->movimientos->registrarCambioPuesto(
                $item['colaborador']->fresh() ?? $item['colaborador'],
                $item['antes'],
                $actor,
                $motivo,
            );
        }
    }
}
