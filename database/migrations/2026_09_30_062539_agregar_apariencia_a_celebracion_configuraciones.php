<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('celebracion_configuraciones', function (Blueprint $table): void {
            $table->boolean('mostrar_logo')->default(true)->after('fondo_path');
            // Fracción (0.0–1.0) de la altura de la tarjeta donde empieza el
            // bloque de texto principal. null = usa la posición automática
            // por defecto de cada tipo de tarjeta (ver BirthdayCardService /
            // TarjetaAniversarioService).
            $table->decimal('texto_posicion_y', 4, 3)->nullable()->after('mostrar_logo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('celebracion_configuraciones', function (Blueprint $table): void {
            $table->dropColumn(['mostrar_logo', 'texto_posicion_y']);
        });
    }
};
