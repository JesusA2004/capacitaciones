<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Material de apoyo de Ayuda: un permiso por video/infografía
 * (`ayuda.material.{slug}`, config/permisos_modulos.php → material_ayuda)
 * para que «Roles y permisos» decida qué ve cada rol. Hoy todos ven todo,
 * así que se otorgan a TODOS los roles existentes (también los
 * personalizados). Aditiva e idempotente; no revoca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permisos = array_map('strval', array_keys((array) config('permisos_modulos.modulos.material_ayuda.acciones', [])));

        if ($permisos === []) {
            return;
        }

        $otorgar = [];

        foreach (Role::query()->where('guard_name', 'web')->pluck('name') as $rol) {
            $otorgar[(string) $rol] = $permisos;
        }

        app(AlineacionPermisosRolService::class)->ajustar(
            $otorgar,
            [],
            'Material de apoyo de Ayuda: un permiso por video/infografía, de inicio para todos los roles.',
        );
    }

    public function down(): void {}
};
