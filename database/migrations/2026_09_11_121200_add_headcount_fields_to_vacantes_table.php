<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca las vacantes generadas automaticamente por HeadcountService (ver
 * docs/HEADCOUNT_Y_VACANTES.md) para poder sincronizarlas (cerrarlas solas
 * cuando la plantilla actual alcanza a la autorizada) sin tocar las
 * vacantes creadas manualmente por RH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacantes', function (Blueprint $table): void {
            $table->boolean('generada_automaticamente')->default(false)->after('observaciones');
            $table->foreignId('headcount_target_id')->nullable()->after('generada_automaticamente')
                ->constrained('headcount_targets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vacantes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('headcount_target_id');
            $table->dropColumn('generada_automaticamente');
        });
    }
};
