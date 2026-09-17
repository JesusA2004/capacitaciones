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
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('telefono_corporativo')->nullable()->after('telefono');
            // Sueldo mensual real del colaborador (no el presupuestado de una
            // vacante): alimenta el recibo de nómina simple y el costo real
            // por colaborador en los KPI de Candidatos/Vacantes.
            $table->decimal('sueldo_mensual', 10, 2)->nullable()->after('telefono_corporativo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn(['telefono_corporativo', 'sueldo_mensual']);
        });
    }
};
