<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales de las que una cuenta con alcance amplio (RH, Sistemas,
 * Dirección Comercial, Regional…) prefiere NO recibir avisos (campana y
 * push). Solo silencia el aviso: lo pendiente sigue en «Mis pendientes» y lo
 * que le toca atender directamente (su propia persona, su equipo directo,
 * sus confirmaciones) le sigue llegando. Ver
 * App\Services\Notificaciones\SucursalesSilenciadasService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacion_sucursales_silenciadas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'sucursal_id'], 'notif_suc_silenciada_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacion_sucursales_silenciadas');
    }
};
