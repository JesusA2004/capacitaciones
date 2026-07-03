<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apellidos')->nullable()->after('name');
            $table->string('numero_empleado')->nullable()->unique()->after('apellidos');
            $table->string('telefono')->nullable()->after('email');
            $table->string('foto_path')->nullable()->after('telefono');

            $table->foreignId('sucursal_principal_id')->nullable()->after('foto_path')->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->after('sucursal_principal_id')->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->after('departamento_id')->constrained('puestos')->nullOnDelete();
            $table->foreignId('jefe_id')->nullable()->after('puesto_id')->constrained('users')->nullOnDelete();

            $table->date('fecha_ingreso')->nullable()->after('jefe_id');
            $table->string('estatus')->default('activo')->after('fecha_ingreso');
            $table->timestamp('ultimo_acceso')->nullable()->after('estatus');
            $table->string('zona_horaria')->default('America/Mexico_City')->after('ultimo_acceso');
            $table->json('preferencias_notificaciones')->nullable()->after('zona_horaria');

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jefe_id');
            $table->dropConstrainedForeignId('puesto_id');
            $table->dropConstrainedForeignId('departamento_id');
            $table->dropConstrainedForeignId('sucursal_principal_id');

            $table->dropSoftDeletes();
            $table->dropColumn([
                'apellidos',
                'numero_empleado',
                'telefono',
                'foto_path',
                'fecha_ingreso',
                'estatus',
                'ultimo_acceso',
                'zona_horaria',
                'preferencias_notificaciones',
            ]);
        });
    }
};
