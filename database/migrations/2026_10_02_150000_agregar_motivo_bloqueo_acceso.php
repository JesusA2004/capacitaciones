<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baja de CUENTA (acceso) ≠ baja de COLABORADOR (laboral):
 * - users: por qué y quién bloqueó el acceso (la cuenta y su historial nunca
 *   se borran).
 * - cierres_laborales: cuándo el trámite de baja suspendió el acceso, para
 *   devolverlo solo si ese mismo trámite se rechaza o cancela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('acceso_bloqueado_motivo', 255)->nullable()->after('acceso_bloqueado_en');
            $table->foreignId('acceso_bloqueado_por')->nullable()->after('acceso_bloqueado_motivo')->constrained('users')->nullOnDelete();
        });

        Schema::table('cierres_laborales', function (Blueprint $table): void {
            $table->timestamp('acceso_suspendido_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cierres_laborales', function (Blueprint $table): void {
            $table->dropColumn('acceso_suspendido_en');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acceso_bloqueado_por');
            $table->dropColumn('acceso_bloqueado_motivo');
        });
    }
};
