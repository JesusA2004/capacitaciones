<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puestos', function (Blueprint $table): void {
            // Menor numero = mayor jerarquia (1 = puesto mas alto de su linea).
            $table->unsignedTinyInteger('nivel_jerarquico')->nullable()->after('descripcion');
            $table->foreignId('puesto_superior_id')->nullable()->after('nivel_jerarquico')
                ->constrained('puestos')->nullOnDelete();
            $table->foreignId('puesto_crecimiento_id')->nullable()->after('puesto_superior_id')
                ->constrained('puestos')->nullOnDelete();
            $table->string('tipo_puesto', 30)->nullable()->after('puesto_crecimiento_id');
            $table->string('esquema_comisiones')->nullable()->after('tipo_puesto');
            $table->boolean('requiere_ruta')->default(false)->after('esquema_comisiones');
            $table->text('responsabilidades')->nullable()->after('requiere_ruta');
            $table->text('requisitos')->nullable()->after('responsabilidades');
        });
    }

    public function down(): void
    {
        Schema::table('puestos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('puesto_superior_id');
            $table->dropConstrainedForeignId('puesto_crecimiento_id');
            $table->dropColumn([
                'nivel_jerarquico',
                'tipo_puesto',
                'esquema_comisiones',
                'requiere_ruta',
                'responsabilidades',
                'requisitos',
            ]);
        });
    }
};
