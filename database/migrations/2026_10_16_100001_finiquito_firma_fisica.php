<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firma física del finiquito sin escaneo (nueva regla de negocio): los
 * documentos impresos y firmados en PAPEL ya no se vuelven a escanear ni
 * subir. En vez de eso, RH presiona «Marcar firmado físicamente» y el
 * sistema registra quién y cuándo. La columna legacy documento_firmado_path
 * se conserva intacta: los finiquitos antiguos con escaneo siguen legibles.
 *
 * Ver App\Services\Finiquitos\FiniquitoService::marcarFirmadoFisicamente().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            if (! Schema::hasColumn('finiquito_calculos', 'firmado_fisicamente_en')) {
                $table->timestamp('firmado_fisicamente_en')->nullable()->after('documento_firmado_path');
            }

            if (! Schema::hasColumn('finiquito_calculos', 'firmado_fisicamente_por')) {
                $table->foreignId('firmado_fisicamente_por')->nullable()->after('firmado_fisicamente_en')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            if (Schema::hasColumn('finiquito_calculos', 'firmado_fisicamente_por')) {
                $table->dropConstrainedForeignId('firmado_fisicamente_por');
            }

            if (Schema::hasColumn('finiquito_calculos', 'firmado_fisicamente_en')) {
                $table->dropColumn('firmado_fisicamente_en');
            }
        });
    }
};
