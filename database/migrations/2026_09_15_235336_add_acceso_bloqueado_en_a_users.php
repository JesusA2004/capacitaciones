<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columna aditiva, no destructiva: separa "revocar acceso al sistema" (esta
 * columna) de "baja laboral" (estatus=Inactivo + deleted_at, ver
 * App\Services\Solicitudes\BajaColaboradorService y
 * App\Http\Controllers\Administracion\UsuarioController::destroy()). Antes
 * ambas cosas compartían el mismo estatus, así que revocar acceso a un
 * colaborador que sigue activo laboralmente terminaba afectando headcount y
 * vacantes (App\Services\Headcount\HeadcountService solo cuenta
 * estatus=Activo). Con esta columna, un usuario puede tener el acceso
 * bloqueado y seguir contando en la plantilla activa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('acceso_bloqueado_en')->nullable()->after('estatus');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('acceso_bloqueado_en');
        });
    }
};
