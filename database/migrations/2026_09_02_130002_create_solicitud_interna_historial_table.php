<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_interna_historial', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_interna_id')->constrained('solicitudes_internas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 30);
            $table->text('comentario')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('solicitud_interna_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_interna_historial');
    }
};
