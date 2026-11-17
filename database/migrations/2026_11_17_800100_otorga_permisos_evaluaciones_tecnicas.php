<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;

/**
 * Evaluaciones técnicas («Seguimiento N», docs/EVALUACIONES_TECNICAS.md):
 * permisos nuevos del módulo `evaluaciones_tecnicas`. Igual que en
 * RolesYPermisosSeeder: RH Admin configura, ve todo y aplica; los gerentes
 * ven y aplican en su alcance (región / sucursal). super_admin lo tiene por
 * el Gate::before global. Aditiva e idempotente; queda en la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rh = [
            'evaluaciones_tecnicas.ver', 'evaluaciones_tecnicas.ver_todos',
            'evaluaciones_tecnicas.aplicar', 'evaluaciones_tecnicas.configurar',
        ];

        app(AlineacionPermisosRolService::class)->ajustar(
            [
                'super_admin' => $rh,
                'rh_admin' => $rh,
                'gerente_regional' => ['evaluaciones_tecnicas.ver', 'evaluaciones_tecnicas.ver_region', 'evaluaciones_tecnicas.aplicar'],
                'gerente_sucursal' => ['evaluaciones_tecnicas.ver', 'evaluaciones_tecnicas.ver_sucursal', 'evaluaciones_tecnicas.aplicar'],
                'gerente' => ['evaluaciones_tecnicas.ver', 'evaluaciones_tecnicas.ver_sucursal', 'evaluaciones_tecnicas.aplicar'],
            ],
            [],
            'Evaluaciones técnicas por puesto (Seguimiento 15 / 30 / 60).',
        );
    }

    public function down(): void {}
};
