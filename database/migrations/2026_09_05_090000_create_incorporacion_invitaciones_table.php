<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitaciones de incorporacion por QR temporal: un colaborador no puede
     * registrarse libremente desde la app, solo si RH genero antes una de
     * estas invitaciones (ver App\Services\Incorporacion\IncorporacionInvitacionService).
     * Solo se guarda el HASH del token (token_hash); el token plano nunca
     * toca la base de datos, solo vive en el QR/liga que ve RH al crearlo.
     */
    public function up(): void
    {
        Schema::create('incorporacion_invitaciones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('codigo_legible', 12)->nullable();

            $table->string('email')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('nombre_prellenado')->nullable();

            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('usado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('regenerated_from_id')->nullable()->constrained('incorporacion_invitaciones')->nullOnDelete();

            $table->unsignedInteger('max_usos')->default(1);
            $table->unsignedInteger('usos_count')->default(0);
            $table->string('estado', 20)->default('activo');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('estado');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incorporacion_invitaciones');
    }
};
