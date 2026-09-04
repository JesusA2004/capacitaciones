<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decision final de RH sobre la incorporacion de un colaborador
     * (distinta de la revision documento por documento, que ya vive en
     * employee_documents.status). Mientras no haya decision, el estado de
     * incorporacion se calcula en vivo a partir de los documentos
     * requeridos (ver App\Services\Incorporacion\IncorporacionService):
     * solo se persiste aqui el resultado final de RH, que es lo unico que
     * no se puede derivar (activa o no al usuario).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('incorporacion_decision', 20)->nullable()->after('estatus_imss');
            $table->foreignId('incorporacion_decidida_por')->nullable()->after('incorporacion_decision')->constrained('users')->nullOnDelete();
            $table->timestamp('incorporacion_decidida_en')->nullable()->after('incorporacion_decidida_por');
            $table->text('incorporacion_motivo_rechazo')->nullable()->after('incorporacion_decidida_en');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('incorporacion_decidida_por');
            $table->dropColumn(['incorporacion_decision', 'incorporacion_decidida_en', 'incorporacion_motivo_rechazo']);
        });
    }
};
