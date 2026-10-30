<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 1 · Reportes de RH.
 *
 * - reportes_guardados: «Mis reportes». Guarda solo la definición (fuente,
 *   columnas, filtros, orden y diseño), nunca datos personales: cada ejecución
 *   vuelve a leer datos actuales y revalida permisos y alcance.
 * - altas_historicas: altas confirmadas por RH de periodos anteriores al
 *   sistema. No toca colaboradores.fecha_ingreso ni se infiere de las bajas.
 *
 * Solo crea tablas: no modifica registros existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reportes_guardados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->string('fuente', 30);
            $table->json('definicion');
            $table->timestamps();

            $table->unique(['user_id', 'nombre']);
        });

        Schema::create('altas_historicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_excel_id')->nullable()->constrained('importaciones_excel')->nullOnDelete();
            $table->string('nombre', 200);
            $table->string('llave', 64);
            $table->string('numero_empleado', 40)->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->date('fecha_alta');
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('llave');
            $table->index('fecha_alta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('altas_historicas');
        Schema::dropIfExists('reportes_guardados');
    }
};
