<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja «Pendientes de alta en sistemas»: cada aviso queda pendiente hasta
 * que Sistemas marca que ya dio de alta a la persona en las demás
 * plataformas (quién, cuándo y notas). Independiente del estado del correo.
 * Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('avisos_alta_sistemas', 'atendido_en')) {
            return;
        }

        Schema::table('avisos_alta_sistemas', function (Blueprint $table) {
            $table->timestamp('atendido_en')->nullable()->after('solicitado_por');
            $table->foreignId('atendido_por')->nullable()->after('atendido_en')->constrained('users')->nullOnDelete();
            $table->text('notas_atencion')->nullable()->after('atendido_por');

            $table->index('atendido_en', 'avisos_alta_sistemas_atendido_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('avisos_alta_sistemas', 'atendido_en')) {
            return;
        }

        Schema::table('avisos_alta_sistemas', function (Blueprint $table) {
            $table->dropIndex('avisos_alta_sistemas_atendido_idx');
            $table->dropConstrainedForeignId('atendido_por');
            $table->dropColumn(['atendido_en', 'notas_atencion']);
        });
    }
};
