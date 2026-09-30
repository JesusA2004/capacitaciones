<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un solo marcador movía los tres bloques de texto juntos (título,
     * nombre, frase/mensaje) — RH necesita moverlos por separado, así que
     * `texto_posicion_y` se divide en tres columnas independientes. Sin
     * datos reales que migrar todavía: el valor que hubiera capturado RH
     * pasa a ser el nuevo `texto_titulo_y` y el resto queda en automático
     * (null) hasta que RH los ajuste.
     */
    public function up(): void
    {
        Schema::table('celebracion_configuraciones', function (Blueprint $table): void {
            $table->renameColumn('texto_posicion_y', 'texto_titulo_y');
        });

        Schema::table('celebracion_configuraciones', function (Blueprint $table): void {
            $table->decimal('texto_nombre_y', 4, 3)->nullable()->after('texto_titulo_y');
            $table->decimal('texto_frase_y', 4, 3)->nullable()->after('texto_nombre_y');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('celebracion_configuraciones', function (Blueprint $table): void {
            $table->dropColumn(['texto_nombre_y', 'texto_frase_y']);
        });

        Schema::table('celebracion_configuraciones', function (Blueprint $table): void {
            $table->renameColumn('texto_titulo_y', 'texto_posicion_y');
        });
    }
};
