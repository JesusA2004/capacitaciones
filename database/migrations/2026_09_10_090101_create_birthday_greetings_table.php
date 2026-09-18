<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_greetings', function (Blueprint $table) {
            $table->id();
            // user_id se conserva por compatibilidad histórica, ya no se
            // escribe desde el código: colaborador_id es la fuente real.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('birthday_phrase_id')->nullable()->constrained('birthday_phrases')->nullOnDelete();
            $table->date('fecha');
            $table->string('nombre_mostrado');
            $table->text('frase');
            $table->string('card_path')->nullable();
            $table->timestamp('enviada_at')->nullable();
            $table->foreignId('enviada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('auto_generada')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Una sola felicitacion por colaborador/fecha: si ya se genero
            // hoy, refrescar la pagina o volver a correr el command no debe
            // cambiar la frase ni duplicar el registro (ver
            // App\Services\Cumpleanos\CumpleanosService).
            $table->unique(['colaborador_id', 'fecha'], 'birthday_greetings_colaborador_fecha_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_greetings');
    }
};
