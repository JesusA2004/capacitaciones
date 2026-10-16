<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Mínimo privilegio (hallazgo de auditoría 2026-10-16): el rol `sistemas`
 * tenía LECTURA GLOBAL PERMANENTE del CONTENIDO sensible de RH (expediente
 * con PII, documentos laborales, contratos, actas, recibos de nómina,
 * finiquitos, formatos oficiales y el detalle/archivo de documentos en la
 * app). Soporte técnico debe ver ESTADO/METADATA (conteos, completitud,
 * listas, auditoría, cuentas), NUNCA el contenido.
 *
 * `people:sincronizar-permisos` es deliberadamente ADITIVO (nunca quita
 * permisos para proteger lo que un administrador agregó desde «Roles y
 * permisos»), así que quitar las líneas del seeder no basta en producción:
 * esta migración deja a `sistemas` con EXACTAMENTE su base del seeder
 * (RolesYPermisosSeeder::permisosBasePorRol()) y revoca los permisos de
 * contenido históricos (expedientes.ver, documentos.ver, documentos_laborales.ver,
 * contratos.ver, actas.ver, nomina.recibos.ver, finiquitos.ver,
 * formatos_oficiales.ver, rh.expedientes.detalle, rh.expedientes.documentos.ver,
 * rh.documentos.detalle y sus permisos de alcance). Solo ese rol; idempotente;
 * si el rol no existe no hace nada. Gemela de
 * 2026_10_15_180100_auditor_minimo_privilegio y
 * 2026_10_16_090000_subgerente_usa_people_como_colaborador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            return;
        }

        app(AlineacionPermisosRolService::class)->alinearAlBase(['sistemas']);
    }

    public function down(): void
    {
        // Sin reversa a propósito: devolverle la lectura global del contenido
        // sensible de RH reabriría el hallazgo de auditoría.
    }
};
