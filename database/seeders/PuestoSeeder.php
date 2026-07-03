<?php

namespace Database\Seeders;

use App\Models\Departamento;
use App\Models\Puesto;
use Illuminate\Database\Seeder;

class PuestoSeeder extends Seeder
{
    public function run(): void
    {
        $puestosPorDepartamento = [
            'Recursos Humanos' => ['Generalista de RH', 'Coordinador de Capacitación'],
            'Operaciones' => ['Gerente de Sucursal', 'Supervisor de Operaciones'],
            'Ventas' => ['Ejecutivo de Ventas', 'Coordinador de Ventas'],
            'Sistemas' => ['Analista de Sistemas', 'Soporte Técnico'],
        ];

        foreach ($puestosPorDepartamento as $departamentoNombre => $puestos) {
            $departamento = Departamento::where('nombre', $departamentoNombre)->first();

            foreach ($puestos as $puesto) {
                Puesto::firstOrCreate(
                    ['nombre' => $puesto],
                    ['departamento_id' => $departamento?->id, 'activo' => true],
                );
            }
        }
    }
}
