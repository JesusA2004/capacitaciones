<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reemplaza a `sucursal_user` (sucursales adicionales autorizadas): es un
 * dato de persona/empleo, no de la cuenta de acceso. La tabla vieja
 * `sucursal_user` NO se borra (backfill/rollback seguro) pero deja de
 * escribirse desde el código — ver ColaboradoresBackfillDesdeUsersCommand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursal_colaborador', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['colaborador_id', 'sucursal_id'], 'sucursal_colaborador_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursal_colaborador');
    }
};
