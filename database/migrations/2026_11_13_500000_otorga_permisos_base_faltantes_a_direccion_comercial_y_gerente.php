<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;

/**
 * Drift BD ↔ seeder (auditoría de roles, 2026-11-04): people:sincronizar-permisos
 * solo reparte permisos NUEVOS, así que lo que el seeder agregó a un rol con un
 * permiso que ya existía nunca llegó a producción.
 *
 *  - Dirección Comercial (a560bf08): aprueba la entrevista de Subgerentes y
 *    Gerentes y ve sus citas. Sin esto, nadie aprueba la entrevista de un
 *    candidato a puesto regional.
 *  - Gerente (d92a26a2): levanta, ve y genera las actas de su gente.
 *
 * Aditiva e idempotente: solo otorga esos permisos al rol si existe; no toca
 * nada más del rol. Queda en la bitácora (permisos_rol_ajustados).
 */
return new class extends Migration
{
    private const OTORGAR = [
        'director_comercial' => ['candidatos.aprobar_entrevista', 'agenda.ver', 'agenda.ver_todos', 'agenda.registrar_resultado', 'agenda.ver_datos_candidato'],
        'gerente' => ['actas.ver', 'actas.crear', 'actas.descargar', 'actas.ver_sucursal'],
    ];

    public function up(): void
    {
        app(AlineacionPermisosRolService::class)->ajustar(self::OTORGAR, [], 'Permisos base del seeder que faltaban en la base (drift).');
    }

    /** Sin reversa: quitarlos dejaría otra vez a nadie aprobando entrevistas. */
    public function down(): void {}
};
