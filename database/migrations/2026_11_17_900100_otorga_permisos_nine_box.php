<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;

/**
 * Nine Box (Seguimiento, docs/NINE_BOX.md): permisos nuevos del módulo
 * «nine_box» en bases existentes, igual que en RolesYPermisosSeeder:
 * acceso (nine_box.ver), alcance (ver_todo / ver_region / ver_sucursal /
 * ver_equipo) y acciones (calibrar, configurar). super_admin además pasa
 * por el Gate::before global. Aditiva e idempotente; queda en la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(AlineacionPermisosRolService::class)->ajustar(
            [
                'super_admin' => ['nine_box.ver', 'nine_box.ver_todo', 'nine_box.ver_region', 'nine_box.ver_sucursal', 'nine_box.ver_equipo', 'nine_box.calibrar', 'nine_box.configurar'],
                'rh_admin' => ['nine_box.ver', 'nine_box.ver_todo', 'nine_box.calibrar', 'nine_box.configurar'],
                'direccion' => ['nine_box.ver', 'nine_box.ver_todo'],
                'director_comercial' => ['nine_box.ver', 'nine_box.ver_todo'],
                'gerente_regional' => ['nine_box.ver', 'nine_box.ver_region', 'nine_box.calibrar'],
                'gerente_sucursal' => ['nine_box.ver', 'nine_box.ver_sucursal', 'nine_box.calibrar'],
                'jefe_directo' => ['nine_box.ver', 'nine_box.ver_equipo', 'nine_box.calibrar'],
            ],
            [],
            'Nine Box: matriz desempeño × potencial en Seguimiento, calibración y configuración.',
        );
    }

    public function down(): void {}
};
