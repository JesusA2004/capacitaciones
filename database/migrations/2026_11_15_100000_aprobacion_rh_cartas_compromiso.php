<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carta compromiso pedida por la propia persona desde «Mi expediente» (web)
 * o la app: queda `pendiente_aprobacion` hasta que alguien con
 * `cartas_compromiso.aprobar` la aprueba (entonces corre el plazo y se
 * guarda el PDF) o la rechaza con motivo. Las cartas del registro en la app
 * y las que genera RH / gerencia siguen naciendo vigentes. Ver
 * App\Services\Expedientes\SolicitudCartaCompromisoService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartas_compromiso', function (Blueprint $table): void {
            $table->boolean('solicitada_por_colaborador')->default(false)->after('generada_por');
            $table->timestamp('aprobada_en')->nullable()->after('solicitada_por_colaborador');
            $table->foreignId('aprobada_por')->nullable()->after('aprobada_en')->constrained('users')->nullOnDelete();
            $table->timestamp('rechazada_en')->nullable()->after('aprobada_por');
            $table->foreignId('rechazada_por')->nullable()->after('rechazada_en')->constrained('users')->nullOnDelete();
            $table->string('motivo_rechazo', 500)->nullable()->after('rechazada_por');
        });
    }

    public function down(): void
    {
        Schema::table('cartas_compromiso', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rechazada_por');
            $table->dropConstrainedForeignId('aprobada_por');
            $table->dropColumn(['solicitada_por_colaborador', 'aprobada_en', 'rechazada_en', 'motivo_rechazo']);
        });
    }
};
