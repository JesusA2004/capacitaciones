<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad persistente de almacenamiento del expediente en el NAS: la
 * carpeta base ("expedientes/{empresa}/{sucursal}/{numero - nombre}") se
 * calcula UNA sola vez (al subir el primer documento/foto) y se guarda aquí
 * — nunca se recalcula en subidas posteriores. Sin esto, un cambio de
 * sucursal, de nombre, o la asignación tardía de numero_empleado partiría el
 * expediente de un colaborador en dos carpetas distintas del NAS (ver
 * App\Services\Expedientes\DocumentoStorageService::asignarRutaBaseColaborador).
 *
 * Pertenece conceptualmente al colaborador, no a la cuenta de acceso — vive
 * temporalmente en `users` porque la separación Usuario/Colaborador
 * (docs/ROLES_Y_NAVEGACION.md) sigue pendiente; cuando exista `Colaborador`
 * como modelo propio, esta columna debe migrar ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('expediente_storage_path')->nullable()->after('foto_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('expediente_storage_path');
        });
    }
};
