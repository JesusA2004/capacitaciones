<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generated_documents.solicitud_id se creó sin FK (ver
 * 2026_09_02_100001_create_generated_documents_table.php) porque
 * solicitudes_internas no existía todavía. Ahora que existe, se agrega la
 * constraint real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->foreign('solicitud_id')->references('id')->on('solicitudes_internas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropForeign(['solicitud_id']);
        });
    }
};
