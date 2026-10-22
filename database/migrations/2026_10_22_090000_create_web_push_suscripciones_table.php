<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suscripciones Web Push (navegador de escritorio/Android) de cada
     * usuario: el equivalente web de `mobile_devices`. Con una suscripción
     * activa el aviso llega como notificación del sistema (centro de
     * notificaciones de Windows, macOS, Android) aunque la pestaña de PEOPLE
     * esté cerrada. Ver App\Services\WebPush\WebPushNotifier y
     * docs/PUSH_NOTIFICATIONS.md.
     *
     * El endpoint lo da el servicio push del navegador (FCM, Mozilla, WNS…)
     * y puede pasar de 500 caracteres: se guarda en `text` y se indexa por
     * su SHA-256 (`endpoint_hash`), único.
     */
    public function up(): void
    {
        Schema::create('web_push_suscripciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('clave_publica', 255);
            $table->string('token_autenticacion', 255);
            $table->string('codificacion', 20)->default('aes128gcm');
            $table->string('navegador', 120)->nullable();
            $table->timestamp('ultimo_envio_en')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_suscripciones');
    }
};
