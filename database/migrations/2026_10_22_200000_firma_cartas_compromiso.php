<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firma de la carta compromiso: quien entra la firma (y pone su huella) en
 * papel; el gerente o RH sube el escaneo firmado, que queda en el
 * expediente como documento (employee_documents). Pendiente de firma =
 * firmada_en null. Ver App\Services\Expedientes\CartaCompromisoFirmaService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartas_compromiso', function (Blueprint $table): void {
            $table->timestamp('firmada_en')->nullable()->after('sustituida_en');
            $table->foreignId('firma_registrada_por')->nullable()->after('firmada_en')->constrained('users')->nullOnDelete();
            $table->foreignId('firmada_document_id')->nullable()->after('firma_registrada_por')->constrained('employee_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cartas_compromiso', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('firmada_document_id');
            $table->dropConstrainedForeignId('firma_registrada_por');
            $table->dropColumn('firmada_en');
        });
    }
};
