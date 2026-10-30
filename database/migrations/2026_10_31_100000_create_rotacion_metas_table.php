<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 1 · Rotación (PDF pág. 17, R5): metas mensuales por segmento, editables
 * por RH con vigencia e historial. Aditiva: siembra los valores propuestos por RH
 * una sola vez, con vigencia inicial para que todo mes pasado tenga meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rotacion_metas')) {
            Schema::create('rotacion_metas', function (Blueprint $table): void {
                $table->id();
                $table->string('segmento', 20);
                $table->decimal('verde_hasta', 5, 2);
                $table->decimal('ambar_hasta', 5, 2);
                $table->date('vigente_desde');
                $table->string('motivo', 500)->nullable();
                $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['segmento', 'vigente_desde'], 'rotacion_metas_segmento_vigencia_idx');
            });
        }

        foreach (['sucursales' => [4, 6], 'corporativo' => [1.7, 2.5], 'global' => [3, 4.5]] as $segmento => [$verde, $ambar]) {
            if (! DB::table('rotacion_metas')->where('segmento', $segmento)->exists()) {
                DB::table('rotacion_metas')->insert([
                    'segmento' => $segmento,
                    'verde_hasta' => $verde,
                    'ambar_hasta' => $ambar,
                    'vigente_desde' => '2000-01-01',
                    'motivo' => 'Valores iniciales de la propuesta de RH (Etapa 1).',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rotacion_metas');
    }
};
