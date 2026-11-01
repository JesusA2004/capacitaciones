<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklist de avisos por rol: sólo se guardan las excepciones a los valores de
 * config/notificaciones_catalogo.php, y un historial de cada cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacion_evento_rol', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('evento', 80);
            $table->boolean('activo');
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'evento'], 'uq_notif_evento_rol');
        });

        Schema::create('notificacion_evento_rol_historial', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('evento', 80);
            $table->boolean('antes');
            $table->boolean('despues');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['role_id', 'created_at'], 'ix_notif_rol_hist_rol_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacion_evento_rol_historial');
        Schema::dropIfExists('notificacion_evento_rol');
    }
};
