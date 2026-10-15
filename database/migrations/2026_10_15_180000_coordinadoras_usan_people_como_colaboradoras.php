<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión del negocio (2026-10-15): la Coordinadora (de sucursal y
 * regional) usa PEOPLE como COLABORADORA, no como operadora de RH.
 *
 * `people:sincronizar-permisos` es deliberadamente no destructivo, así que
 * quitar los permisos del seeder no basta en producción: esta migración
 * deja a `coordinadora` y `coordinadora_regional` con EXACTAMENTE el
 * catálogo de `colaborador` (RolesYPermisosSeeder::PERMISOS_ROL_COLABORADOR)
 * y revoca expedientes/documentos/solicitudes de RH, bandeja móvil RH,
 * usuarios, reportes, cierres, etc. Solo esos dos roles; idempotente; si el
 * rol no existe no hace nada. Ver docs/ROLES_Y_NAVEGACION.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            return;
        }

        app(AlineacionPermisosRolService::class)->alinearAlBase(['coordinadora', 'coordinadora_regional']);
    }

    public function down(): void
    {
        // Sin reversa: devolverles los permisos operativos contradice la
        // decisión del negocio.
    }
};
