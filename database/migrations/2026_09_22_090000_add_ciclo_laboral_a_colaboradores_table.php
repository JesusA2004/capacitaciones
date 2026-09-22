<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo laboral completo del colaborador (docs/backend-rh-completion.md):
 * gerente (además del jefe inmediato), modalidad de contratación, estado
 * del alta, trazabilidad al candidato de origen, fecha de baja y cierre de
 * expediente. Todas las columnas son nullable: migración aditiva segura
 * sobre datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->foreignId('gerente_id')->nullable()->after('jefe_id')->constrained('colaboradores')->nullOnDelete();
            $table->string('tipo_contratacion', 30)->nullable()->after('periodo_prueba_fin');
            $table->string('estado_alta', 30)->nullable()->after('tipo_contratacion')->index();
            $table->foreignId('candidato_id')->nullable()->after('estado_alta')->constrained('candidatos')->nullOnDelete();
            $table->foreignId('alta_registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activado_en')->nullable();
            $table->date('fecha_baja')->nullable();
            $table->timestamp('expediente_cerrado_en')->nullable();
            $table->foreignId('expediente_cerrado_por')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('candidatos', function (Blueprint $table): void {
            $table->foreignId('colaborador_id')->nullable()->after('vacante_id')->constrained('colaboradores')->nullOnDelete();
            $table->timestamp('contratado_en')->nullable();
        });

        Schema::table('vacantes', function (Blueprint $table): void {
            $table->foreignId('candidato_contratado_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('colaborador_contratado_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->date('fecha_cierre')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vacantes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('candidato_contratado_id');
            $table->dropConstrainedForeignId('colaborador_contratado_id');
            $table->dropColumn('fecha_cierre');
        });

        Schema::table('candidatos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('colaborador_id');
            $table->dropColumn('contratado_en');
        });

        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gerente_id');
            $table->dropConstrainedForeignId('candidato_id');
            $table->dropConstrainedForeignId('alta_registrada_por');
            $table->dropConstrainedForeignId('expediente_cerrado_por');
            $table->dropIndex(['estado_alta']);
            $table->dropColumn(['tipo_contratacion', 'estado_alta', 'activado_en', 'fecha_baja', 'expediente_cerrado_en']);
        });
    }
};
