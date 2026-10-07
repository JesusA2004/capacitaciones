<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivos_rechazo_candidato', function (Blueprint $table): void {
            $table->id();
            $table->string('clave')->unique();
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            // Sugerencia de RH al elegir este motivo; RH siempre puede
            // cambiar la marca "recontratable" caso por caso.
            $table->boolean('no_recontratable_por_defecto')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivos_rechazo_candidato');
    }
};
