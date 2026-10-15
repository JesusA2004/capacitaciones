<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsivas de equipo (docs/responsiva/ResponsivaLaptopPanta.docx): el
 * gerente o RH describe en PEOPLE lo que entrega (laptop, casco, celular…),
 * se imprime con Chrome (Browsershot), se firma en papel y, si se quiere,
 * se sube el escaneo firmado. Los datos del receptor, del otorgante y de la
 * empresa se congelan en `snapshot` al generarla: reimprimirla meses después
 * produce exactamente el mismo documento aunque la persona cambie de puesto.
 * Ver App\Services\Responsivas\ResponsivaService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsivas', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 20)->nullable()->unique();
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->string('tipo', 30);
            $table->string('lugar', 160);
            $table->date('fecha_entrega');
            $table->foreignId('entregado_por')->constrained('users');
            $table->text('observaciones')->nullable();
            $table->json('snapshot');
            $table->string('estado', 20)->default('generada');
            $table->string('pdf_path', 500)->nullable();
            $table->string('firmada_path', 500)->nullable();
            $table->timestamp('firmada_subida_en')->nullable();
            $table->foreignId('firmada_subida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->date('devuelta_en')->nullable();
            $table->text('observaciones_devolucion')->nullable();
            $table->foreignId('devolucion_registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelada_en')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_cancelacion')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'estado'], 'responsivas_colab_estado_idx');
        });

        Schema::create('responsiva_activos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('responsiva_id')->constrained('responsivas')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(1);
            $table->string('descripcion', 160);
            $table->string('marca', 120)->nullable();
            $table->string('modelo', 120)->nullable();
            $table->string('numero_serie', 120)->nullable();
            $table->string('color', 60)->nullable();
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->string('estado', 20);
            // Una característica por renglón («Procesador: Intel Core i5»).
            $table->text('caracteristicas')->nullable();
            $table->string('accesorios', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsiva_activos');
        Schema::dropIfExists('responsivas');
    }
};
