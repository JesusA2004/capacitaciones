<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correo de la empresa (distinto de correo_personal y del "email" de
 * acceso al sistema, que ya no siempre es un correo real): solo lo tienen
 * puestos administrativos/gerenciales (Gerente, Subgerente, Coordinadora…),
 * no todos los colaboradores. Ya estaba previsto como variable de
 * documentos (VariableMappingService/PlaceholderResolver) pero sin columna
 * real — PlaceholderResolver tomaba el email de acceso como respaldo, lo
 * cual ya no es correcto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->string('correo_corporativo')->nullable()->after('correo_personal');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropColumn('correo_corporativo');
        });
    }
};
