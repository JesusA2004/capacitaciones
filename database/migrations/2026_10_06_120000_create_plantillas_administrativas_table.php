<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versiones del DISEÑO de los documentos administrativos HTML (recibo de
 * nómina, finiquito, comprobante, constancia) que RH edita en Documentos
 * maestros. Cada versión es inmutable una vez activada: un PDF generado
 * guarda en su snapshot la versión, el diseño, el motor y los recursos
 * usados, así que cambiar el diseño hoy no altera documentos históricos.
 * Incremental: no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_administrativas', function (Blueprint $table) {
            $table->id();
            $table->string('familia', 40);
            $table->unsignedInteger('version');
            // borrador | activa | archivada
            $table->string('estado', 20)->default('borrador');
            $table->string('motor', 20)->nullable();
            $table->json('diseno');
            $table->char('hash', 64);
            $table->string('notas', 500)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activado_en')->nullable();
            $table->timestamps();

            $table->unique(['familia', 'version'], 'plantillas_admin_familia_version_unique');
            $table->index(['familia', 'estado'], 'plantillas_admin_familia_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_administrativas');
    }
};
