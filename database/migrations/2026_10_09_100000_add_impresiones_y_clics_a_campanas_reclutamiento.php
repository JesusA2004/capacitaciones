<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance de la publicación de una campaña (Meta Ads, bolsa de trabajo…):
 * impresiones y clics, solo si RH los captura del reporte del proveedor.
 * Vacíos = no capturados (nunca se inventan ni se tratan como cero).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campanas_reclutamiento', function (Blueprint $table): void {
            $table->unsignedInteger('impresiones')->nullable()->after('url');
            $table->unsignedInteger('clics')->nullable()->after('impresiones');
        });
    }

    public function down(): void
    {
        Schema::table('campanas_reclutamiento', function (Blueprint $table): void {
            $table->dropColumn(['impresiones', 'clics']);
        });
    }
};
