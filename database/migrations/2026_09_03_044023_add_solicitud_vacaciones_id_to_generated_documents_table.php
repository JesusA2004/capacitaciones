<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite asociar un GeneratedDocument a una SolicitudVacaciones, igual que
 * ya se hace con solicitud_id -> solicitudes_internas, para que "Generar
 * formato" también funcione desde una solicitud de vacaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->foreignId('solicitud_vacaciones_id')->nullable()->after('solicitud_id')
                ->constrained('solicitudes_vacaciones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('solicitud_vacaciones_id');
        });
    }
};
