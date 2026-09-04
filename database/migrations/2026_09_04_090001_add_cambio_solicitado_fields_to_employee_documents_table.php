<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soporte para el flujo "solicitar cambio de archivo ya subido" de la
     * app movil: el colaborador no puede reemplazar un documento en
     * revision/aprobado directamente, debe solicitarlo (status
     * cambio_solicitado) y RH debe autorizarlo (status cambio_autorizado,
     * columnas de abajo) antes de que DocumentoStorageService::subirVersion
     * acepte una nueva version.
     */
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table): void {
            $table->timestamp('change_requested_at')->nullable()->after('rejection_reason');
            $table->foreignId('change_authorized_by')->nullable()->after('change_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('change_authorized_at')->nullable()->after('change_authorized_by');
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('change_authorized_by');
            $table->dropColumn(['change_requested_at', 'change_authorized_at']);
        });
    }
};
