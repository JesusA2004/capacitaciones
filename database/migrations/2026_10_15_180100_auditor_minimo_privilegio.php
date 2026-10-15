<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Mínimo privilegio (2026-10-15): el rol `auditor` es de SOLO LECTURA de lo
 * que su función documentada requiere (docs/ROLES_PERMISOS_RH.md: empresas,
 * expedientes, documentos, vacaciones, solicitudes, reportes RH;
 * reclutamiento de consulta). Se le revocan recibos de nómina, préstamos y
 * actas (con sus permisos de alcance), y cualquier otro permiso fuera de su
 * base del seeder. Solo ese rol; idempotente; si no existe no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            return;
        }

        app(AlineacionPermisosRolService::class)->alinearAlBase(['auditor']);
    }

    public function down(): void
    {
        // Sin reversa: volver a darlos ampliaría el acceso del rol.
    }
};
