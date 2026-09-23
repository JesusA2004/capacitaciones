<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Muro de cumpleaños (docs/CUMPLEANOS.md, "Muro de felicitaciones"): RH abre
 * el muro de una felicitación y cualquier colaborador activo puede dejar un
 * mensaje y/o una foto para el cumpleañero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birthday_greetings', function (Blueprint $table) {
            $table->timestamp('muro_abierto_at')->nullable();
            $table->foreignId('muro_abierto_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('muro_cerrado_at')->nullable();
        });

        Schema::create('birthday_wall_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('birthday_greeting_id')->constrained('birthday_greetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('mensaje')->nullable();
            // Ruta en el disco de cumpleaños; nunca se expone al cliente.
            $table->string('foto_path')->nullable();
            $table->string('foto_mime', 50)->nullable();
            $table->timestamps();

            $table->index(['birthday_greeting_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_wall_messages');

        Schema::table('birthday_greetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('muro_abierto_por_id');
            $table->dropColumn(['muro_abierto_at', 'muro_cerrado_at']);
        });
    }
};
