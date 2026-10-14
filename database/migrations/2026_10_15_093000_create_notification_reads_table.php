<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lectura genérica por usuario de fuentes del feed unificado de
 * notificaciones (App\Services\Notificaciones\NotificationFeedService) que
 * NO tienen ya su propia tabla de lectura — hoy, celebraciones globales
 * (BirthdayGreeting). Los avisos de RH siguen usando `aviso_lecturas`
 * (App\Models\AvisoLectura) y las notificaciones nativas de Laravel su
 * propio `read_at` en `notifications`: esta tabla nunca las duplica, solo
 * cubre lo que falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['source_type', 'source_id', 'user_id'], 'notification_reads_source_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
