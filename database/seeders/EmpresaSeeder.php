<?php

namespace Database\Seeders;

use App\Models\Empresa;
use Illuminate\Database\Seeder;

class EmpresaSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = Empresa::firstOrCreate(
            ['nombre' => 'Mr. Lana'],
            ['razon_social' => 'Mr. Lana Servicios Financieros', 'activo' => true],
        );

        // Representante legal de los formatos de Jurídico 2026 («Se encuentra
        // representada por la señora Lesli Maribel Rodríguez Herrera…»): se
        // imprime en las declaraciones de los contratos. Solo si no está
        // capturado (RH lo edita en Administración → Empresas).
        if (trim((string) $empresa->representante_legal_nombre) === '') {
            $empresa->forceFill([
                'representante_legal_nombre' => 'Lesli Maribel Rodríguez Herrera',
                'representante_legal_cargo' => $empresa->representante_legal_cargo ?: 'Representante Legal',
            ])->save();
        }
    }
}
