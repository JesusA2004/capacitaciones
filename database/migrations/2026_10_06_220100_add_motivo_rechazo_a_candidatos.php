<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table): void {
            // Motivo canónico del catálogo administrable (CLAUDE.md §10). El
            // texto libre ya existente (motivo_salida) se conserva como el
            // comentario/detalle de RH; este catálogo nunca se borra, solo
            // se desactiva, así que la referencia histórica sigue siendo
            // válida aunque el motivo ya no se use para nuevos rechazos.
            $table->foreignId('motivo_rechazo_id')->nullable()->after('motivo_salida')->constrained('motivos_rechazo_candidato')->nullOnDelete();
            // null = no aplica todavía (no ha salido del proceso).
            $table->boolean('recontratable')->nullable()->after('motivo_rechazo_id');
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('motivo_rechazo_id');
            $table->dropColumn('recontratable');
        });
    }
};
