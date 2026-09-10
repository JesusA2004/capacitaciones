<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dispositivos moviles registrados por cada usuario para envio de push
     * (Expo). Un mismo `push_token` nunca se duplica: updateOrCreate() por
     * token en App\Services\MobilePush\PushTokenService reasigna el token al
     * usuario que vuelve a iniciar sesion en ese dispositivo (p. ej. tras
     * cerrar sesion y entrar con otra cuenta en el mismo telefono).
     */
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('push_token')->unique();
            $table->string('platform', 20);
            $table->string('device_name')->nullable();
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
    }
};
