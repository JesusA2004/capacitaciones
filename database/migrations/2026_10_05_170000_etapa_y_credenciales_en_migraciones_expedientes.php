<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración inicial: etapa visible en la pantalla de carga y la lista de
 * credenciales generadas (archivo CIFRADO en storage local; nunca en BD
 * en claro). Ver docs/MIGRACION_INICIAL_EXPEDIENTES.md, «Cuentas de acceso».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migraciones_expedientes', function (Blueprint $table): void {
            $table->string('etapa', 60)->nullable();
            $table->string('credenciales_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('migraciones_expedientes', fn (Blueprint $table) => $table->dropColumn(['etapa', 'credenciales_path']));
    }
};
