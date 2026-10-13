<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada aviso entregado por App\Services\Notificaciones\NotificacionEventosService
 * (confirmación, pendiente, resultado). La `clave` (sha1 de destinatario +
 * evento + recurso + acción + ocurrencia) es única: una misma transición
 * produce UNA notificación aunque el flujo, un reintento o el reconciliador
 * la pidan otra vez. Incremental: no toca `notifications`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacion_entregas', function (Blueprint $table) {
            $table->id();
            $table->char('clave', 40)->unique('notif_entregas_clave_unique');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('categoria', 20);
            $table->string('evento', 80);
            $table->string('related_type', 80)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('accion', 60)->nullable();
            $table->uuid('notification_id')->nullable();
            $table->unsignedSmallInteger('push_dispositivos')->default(0);
            $table->string('origen', 20)->default('directo');
            $table->timestamps();

            $table->index(['related_type', 'related_id', 'user_id'], 'notif_entregas_recurso_index');
            $table->index(['created_at'], 'notif_entregas_creado_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacion_entregas');
    }
};
