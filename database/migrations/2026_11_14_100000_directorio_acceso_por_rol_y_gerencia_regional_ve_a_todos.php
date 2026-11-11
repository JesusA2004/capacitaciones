<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Directorio (decisión de RH, 2026-11-11):
 *  - `directorio.ver` es el acceso al módulo (Roles y permisos). Lo reciben
 *    todos los roles que hoy entran, MENOS los de config/directorio.php →
 *    roles_sin_acceso (gestores = rol colaborador). El personal de
 *    Corporativo entra siempre, tenga el rol que tenga.
 *  - De Subgerente Regional para arriba ven a toda la empresa con su
 *    contacto: el rol gerente_regional pasa de «Región» a «Todos».
 *
 * Aditiva e idempotente; lo que un administrador cambie después desde
 * «Roles y permisos» se respeta. Queda en la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sinAcceso = array_values(array_filter((array) config('directorio.roles_sin_acceso', []), 'is_string'));
        $otorgar = [];

        foreach (Role::query()->where('guard_name', 'web')->pluck('name') as $rol) {
            if (! in_array((string) $rol, $sinAcceso, true)) {
                $otorgar[(string) $rol] = ['directorio.ver'];
            }
        }

        if (isset($otorgar['gerente_regional'])) {
            $otorgar['gerente_regional'] = ['directorio.ver', 'directorio.ver_todos', 'directorio.contactos.ver_todos'];
        }

        if ($otorgar !== []) {
            app(AlineacionPermisosRolService::class)->ajustar($otorgar, [], 'Directorio: acceso por rol (gestores sin acceso) y gerencia regional ve a toda la empresa.');
        }
    }

    /** Sin reversa: quitar el acceso dejaría a todos fuera del Directorio. */
    public function down(): void {}
};
