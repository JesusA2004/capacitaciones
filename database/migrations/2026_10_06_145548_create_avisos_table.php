<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('avisos', function (Blueprint $table): void {
            $table->id();
            $table->string('titulo');
            $table->text('mensaje');
            // 'todos' | 'colaborador' — ver App\Enums\AlcanceAviso.
            $table->string('alcance', 20);
            $table->foreignId('colaborador_objetivo_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            // Disco privado (nunca público), mismo patrón que fondos/documentos.
            $table->string('imagen_disk', 40)->nullable();
            $table->string('imagen_path')->nullable();
            $table->string('imagen_mime', 80)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_en')->nullable();
            $table->timestamps();

            $table->index(['alcance', 'colaborador_objetivo_id']);
        });

        // Quién ya vio un aviso: se crea solo al abrirlo (nunca se
        // pre-llena para "toda la empresa", que podría ser miles de filas
        // de golpe).
        Schema::create('aviso_lecturas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aviso_id')->constrained('avisos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('leido_en');

            $table->unique(['aviso_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aviso_lecturas');
        Schema::dropIfExists('avisos');
    }
};
