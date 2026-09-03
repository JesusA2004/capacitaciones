<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_laborales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo_movimiento', 30);

            $table->foreignId('empresa_anterior_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('empresa_nueva_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_anterior_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('sucursal_nueva_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_anterior_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('departamento_nuevo_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_anterior_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('puesto_nuevo_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('jefe_anterior_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('jefe_nuevo_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('vacante_id')->nullable()->constrained('vacantes')->nullOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('alta_digital_id')->nullable()->constrained('altas_digitales')->nullOnDelete();
            $table->foreignId('documento_id')->nullable()->constrained('employee_documents')->nullOnDelete();

            $table->string('motivo')->nullable();
            $table->text('observaciones')->nullable();
            $table->date('fecha_movimiento');
            $table->date('fecha_fin_cobertura')->nullable();

            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('tipo_movimiento');
            $table->index('fecha_movimiento');
            $table->index(['user_id', 'fecha_movimiento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_laborales');
    }
};
