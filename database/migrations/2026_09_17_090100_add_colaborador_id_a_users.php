<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `User` deja de ser dueño de los datos de persona/empleo (ver
 * create_colaboradores_table): esta columna es el único enlace entre la
 * cuenta de acceso y su colaborador. Nullable y única — un Colaborador puede
 * no tener cuenta, y una cuenta nunca comparte colaborador con otra.
 *
 * Las columnas de persona/empleo que hoy siguen en `users` NO se borran en
 * esta migración (regla del repo: nunca destruir datos de producción a
 * ciegas) — quedan sin usarse desde el código hasta una limpieza posterior,
 * una vez que Colaborador sea la fuente real en todos los flujos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->unique()
                ->after('id')
                ->constrained('colaboradores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
        });
    }
};
