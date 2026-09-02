<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('estatus_imss', 20)->default('pendiente_imss')->after('estatus');
            $table->date('fecha_alta_imss')->nullable()->after('estatus_imss');
            $table->date('periodo_prueba_inicio')->nullable()->after('fecha_alta_imss');
            $table->date('periodo_prueba_fin')->nullable()->after('periodo_prueba_inicio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['estatus_imss', 'fecha_alta_imss', 'periodo_prueba_inicio', 'periodo_prueba_fin']);
        });
    }
};
