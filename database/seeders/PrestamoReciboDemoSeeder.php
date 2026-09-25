<?php

namespace Database\Seeders;

use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Nomina\PrestamoService;
use App\Services\Nomina\ReciboNominaService;
use Illuminate\Database\Seeder;

/**
 * Escenarios de demostración de la Parte B (colaborador_id como identidad
 * real, ver docs/SOLICITUDES_UNIFICADAS.md) que SolicitudesDemoSeeder no
 * cubre: un Colaborador SIN cuenta de acceso con saldo de vacaciones real,
 * un préstamo ya ACTIVO con al menos un movimiento en su ledger, y un
 * recibo de nómina histórico — todo generado con los services reales
 * (nunca insertando saldos/montos a mano), igual criterio que el resto de
 * los seeders de demostración.
 *
 * Corre después de UsuarioDemoSeeder (necesita sucursal/departamento/puesto
 * y un rh_admin ya creados) y de SolicitudesDemoSeeder (el préstamo de
 * colaborador4 ya nace en pendiente_entrega ahí — aquí se activa uno
 * aparte, sin tocar ese).
 */
class PrestamoReciboDemoSeeder extends Seeder
{
    public function run(): void
    {
        $rhAdmin = User::where('email', 'rh.admin@mrlana.test')->first();

        if ($rhAdmin === null) {
            return;
        }

        $this->sembrarColaboradorSinCuenta();
        $this->sembrarPrestamoActivoConMovimiento($rhAdmin);
    }

    /**
     * Colaborador sin User (nunca tuvo alta digital, o nunca la necesitó):
     * debe poder tener saldo de vacaciones real igual que cualquier otro,
     * sin depender de una cuenta de acceso — ver
     * App\Services\Vacaciones\VacacionesService::saldoColaborador().
     */
    private function sembrarColaboradorSinCuenta(): void
    {
        $sucursal = Sucursal::where('clave', 'IXT01')->first();
        $departamento = Departamento::where('nombre', 'Operaciones')->first();
        $puesto = Puesto::where('nombre', 'Gestor')->first();

        Colaborador::firstOrCreate(
            ['numero_empleado' => 'EMP-0090'],
            [
                'name' => 'Beatriz',
                'apellidos' => 'Salgado Ríos',
                'genero' => Genero::Femenino,
                'sucursal_principal_id' => $sucursal?->id,
                'departamento_id' => $departamento?->id,
                'puesto_id' => $puesto?->id,
                'fecha_ingreso' => now()->subYears(2)->subMonths(3),
                'estatus' => EstadoUsuario::Activo,
            ],
        );
    }

    /**
     * Préstamo ACTIVO (RH ya confirmó la entrega, ver
     * PrestamoService::activar()) con un abono ya registrado en su ledger
     * — para que "Préstamo activo" aparezca sugerido en Recibo de nómina
     * (ver ReciboNominaService::generar()) sin depender de que RH lo
     * confirme manualmente primero en un ambiente recién sembrado.
     */
    private function sembrarPrestamoActivoConMovimiento(User $rhAdmin): void
    {
        $colaborador = Colaborador::where('numero_empleado', 'EMP-0007')->first();

        if ($colaborador === null || $colaborador->prestamos()->exists()) {
            return;
        }

        if ($colaborador->sueldo_mensual === null) {
            $colaborador->update(['sueldo_mensual' => 9800]);
        }

        $prestamos = app(PrestamoService::class);

        $prestamo = Prestamo::create([
            'colaborador_id' => $colaborador->id,
            'solicitud_id' => null,
            'monto_original' => 6000,
            'saldo' => 6000,
            'plazo' => 6,
            'periodicidad' => 'quincenal',
            'pago_programado' => 1000,
            'estado' => 'pendiente_entrega',
        ]);

        $prestamo = $prestamos->activar($prestamo, [
            'fecha_otorgamiento' => now()->subMonths(2)->toDateString(),
            'fecha_primer_descuento' => now()->subMonths(2)->addDays(15)->toDateString(),
            'periodicidad' => 'quincenal',
            'pago_programado' => 1000,
        ]);

        $prestamos->registrarMovimiento($prestamo, 1000, 'nomina', $rhAdmin);

        $recibos = app(ReciboNominaService::class);
        $recibos->generar($colaborador, [
            'periodo_inicio' => now()->subMonths(1)->startOfMonth()->toDateString(),
            'periodo_fin' => now()->subMonths(1)->startOfMonth()->addDays(14)->toDateString(),
            'fecha_pago' => now()->subMonths(1)->startOfMonth()->addDays(15)->toDateString(),
            'sueldo_base' => round((float) $colaborador->sueldo_mensual / 2, 2),
            'percepciones' => [],
            'deducciones' => [],
        ], $rhAdmin);
    }
}
