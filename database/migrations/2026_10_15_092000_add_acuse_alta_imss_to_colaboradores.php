<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acuse de alta ante el IMSS que RH sube al concluir el alta. Es un
 * indicador (verde = ya está, naranja = falta), NO un pendiente de la
 * bandeja. El archivo vive en la carpeta del expediente (nunca se borra al
 * reemplazarlo: se guarda uno nuevo y se apunta a él). Incremental.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            if (! Schema::hasColumn('colaboradores', 'alta_imss_path')) {
                $table->string('alta_imss_path', 500)->nullable()->after('fecha_alta_imss');
                $table->string('alta_imss_nombre', 255)->nullable()->after('alta_imss_path');
                $table->timestamp('alta_imss_subido_en')->nullable()->after('alta_imss_nombre');
                $table->foreignId('alta_imss_subido_por')->nullable()->after('alta_imss_subido_en')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            if (Schema::hasColumn('colaboradores', 'alta_imss_path')) {
                $table->dropConstrainedForeignId('alta_imss_subido_por');
                $table->dropColumn(['alta_imss_path', 'alta_imss_nombre', 'alta_imss_subido_en']);
            }
        });
    }
};
