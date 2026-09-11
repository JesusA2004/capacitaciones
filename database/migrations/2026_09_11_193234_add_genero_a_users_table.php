<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KPI de género del dashboard RH (hombres/mujeres) necesita este dato — no
 * existía en el modelo de datos. Nullable: nunca se infiere ni se inventa,
 * el colaborador o RH lo captura explícitamente; "sin especificar" es una
 * respuesta válida, no un valor por defecto forzado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('genero', 20)->nullable()->after('apellidos');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('genero');
        });
    }
};
