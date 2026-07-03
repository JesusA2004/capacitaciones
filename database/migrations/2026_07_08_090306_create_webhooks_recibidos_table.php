<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Todo webhook entrante (hoy solo Zoom) se registra aquí antes de
 * procesarse, con un índice único que garantiza que el mismo evento nunca
 * se procese dos veces (idempotencia real, no solo "buena fe" del
 * proveedor que no reenvía). Ver docs/AUDITORIA_CUMPLIMIENTO.md sección 3 y
 * App\Http\Controllers\Reuniones\ZoomWebhookController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks_recibidos', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 20);
            // ID del evento que asigna el proveedor (Zoom: payload.object.uuid + event, o un UUID propio si no lo trae).
            $table->string('identificador_evento');
            $table->string('tipo', 60);
            $table->string('hash_payload', 64);
            // Payload ya filtrado (sin encabezados de autenticación ni secretos).
            $table->json('payload_normalizado')->nullable();
            $table->boolean('firma_valida')->default(false);
            // recibido | procesando | procesado | descartado | error
            $table->string('estado', 20)->default('recibido');
            $table->timestamp('procesado_en')->nullable();
            $table->unsignedInteger('intentos')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['proveedor', 'identificador_evento'], 'webhooks_recibidos_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks_recibidos');
    }
};
