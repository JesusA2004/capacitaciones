<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de recibos de nómina INFORMATIVOS (ver
 * App\Services\Nomina\ReciboNominaService) — nunca un CFDI timbrado ante el
 * SAT. Cada fila es un recibo ya generado (persistido con su snapshot de
 * percepciones/deducciones y, si el PDF se guardó con éxito, la referencia
 * al archivo) para que RH pueda volver a descargarlo sin regenerar montos
 * distintos si el sueldo del colaborador cambió después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recibos_nomina', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->date('periodo_inicio');
            $table->date('periodo_fin');
            $table->date('fecha_pago');
            $table->decimal('sueldo_base', 10, 2);
            $table->json('percepciones');
            $table->json('deducciones');
            $table->decimal('total_percepciones', 10, 2);
            $table->decimal('total_deducciones', 10, 2);
            $table->decimal('neto', 10, 2);
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pdf_disk')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'periodo_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recibos_nomina');
    }
};
