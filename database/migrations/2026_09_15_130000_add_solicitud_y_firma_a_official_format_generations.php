<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vincula una generación de formato oficial a la solicitud interna que
     * la disparó (App\Services\Solicitudes\SolicitudFormatoOficialService) y
     * agrega el ciclo de firma: generado -> firmado, con el PDF firmado
     * subido por RH (ver docs/FORMATOS_OFICIALES.md).
     */
    public function up(): void
    {
        Schema::table('official_format_generations', function (Blueprint $table): void {
            $table->foreignId('solicitud_interna_id')->nullable()->after('official_format_id')
                ->constrained('solicitudes_internas')->nullOnDelete();
            $table->string('status')->default('generado')->after('data_snapshot');
            $table->string('signed_disk')->nullable()->after('status');
            $table->string('signed_path')->nullable()->after('signed_disk');
            $table->string('signed_name')->nullable()->after('signed_path');
            $table->foreignId('signed_uploaded_by')->nullable()->after('signed_name')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('signed_uploaded_at')->nullable()->after('signed_uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('official_format_generations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('solicitud_interna_id');
            $table->dropConstrainedForeignId('signed_uploaded_by');
            $table->dropColumn(['status', 'signed_disk', 'signed_path', 'signed_name', 'signed_uploaded_at']);
        });
    }
};
