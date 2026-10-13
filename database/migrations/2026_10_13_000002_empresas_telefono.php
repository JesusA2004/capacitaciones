<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teléfono de contacto de la empresa (Configuración → Empresa).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('empresas', 'telefono')) {
            Schema::table('empresas', function (Blueprint $table): void {
                $table->string('telefono', 30)->nullable()->after('representante_legal_cargo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('empresas', 'telefono')) {
            Schema::table('empresas', function (Blueprint $table): void {
                $table->dropColumn('telefono');
            });
        }
    }
};
