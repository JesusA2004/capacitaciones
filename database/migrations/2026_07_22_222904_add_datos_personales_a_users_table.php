<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('fecha_nacimiento')->nullable()->after('preferencias_notificaciones');
            $table->string('curp', 18)->nullable()->after('fecha_nacimiento');
            $table->string('rfc', 13)->nullable()->after('curp');
            $table->string('nss', 11)->nullable()->after('rfc');
            $table->string('domicilio')->nullable()->after('nss');
            $table->string('correo_personal')->nullable()->after('domicilio');
            $table->string('contacto_emergencia_nombre')->nullable()->after('correo_personal');
            $table->string('contacto_emergencia_telefono')->nullable()->after('contacto_emergencia_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_nacimiento', 'curp', 'rfc', 'nss', 'domicilio',
                'correo_personal', 'contacto_emergencia_nombre', 'contacto_emergencia_telefono',
            ]);
        });
    }
};
