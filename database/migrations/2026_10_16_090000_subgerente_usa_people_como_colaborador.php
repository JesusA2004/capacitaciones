<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión del negocio (2026-10-15, aplicada el 2026-10-16): el subgerente
 * deja de apoyar/cubrir operativamente al gerente y usa PEOPLE como
 * COLABORADOR, exactamente igual que las coordinadoras.
 *
 * `people:sincronizar-permisos` es deliberadamente no destructivo, así que
 * quitar los permisos del seeder no basta en producción: esta migración deja
 * a `subgerente` con EXACTAMENTE el catálogo de `colaborador`
 * (RolesYPermisosSeeder::PERMISOS_ROL_COLABORADOR) y revoca
 * expedientes/documentos/solicitudes de RH, bandeja móvil RH, usuarios,
 * reportes, cierres, etc. Solo ese rol; idempotente; si el rol no existe no
 * hace nada. Gemela de 2026_10_15_180000_coordinadoras_usan_people_como_colaboradoras.
 * Ver docs/ROLES_Y_NAVEGACION.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            return;
        }

        app(AlineacionPermisosRolService::class)->alinearAlBase(['subgerente']);
    }

    public function down(): void
    {
        // Sin reversa: devolverle los permisos operativos contradice la
        // decisión del negocio.
    }
};
