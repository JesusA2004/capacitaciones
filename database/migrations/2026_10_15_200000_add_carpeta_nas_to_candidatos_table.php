<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carpeta del mini expediente del candidato en el NAS (Synology), con su
 * nombre: `candidatos/{Nombre Apellidos} - {id}`. Se fija al guardar el
 * primer archivo y no se recalcula si después se corrige el nombre, para
 * que el CV, psicométricos, socioeconómico y referencias nunca queden
 * partidos en dos carpetas. Independiente del expediente del colaborador
 * (`expedientes/...`), que se arma aparte al contratarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->string('carpeta_nas', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropColumn('carpeta_nas');
        });
    }
};
