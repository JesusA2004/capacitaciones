<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes de integración servidor-a-servidor (p. ej. el sistema Auditoría).
 *
 * NO son cuentas de `users`: una cuenta siempre pertenece a un colaborador
 * (docs/ROLES_Y_NAVEGACION.md) y un token de la app móvil (Sanctum,
 * personal_access_tokens) nunca sirve aquí, ni al revés. El token se guarda
 * sólo como SHA-256 (nunca en claro); `token_prefijo` es un identificador
 * visible para soporte, no un secreto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes_integracion', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('nombre');
            $table->string('token_prefijo', 20)->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->json('habilidades');
            $table->boolean('todas_las_empresas')->default(false);
            $table->json('empresas_permitidas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('emitido_en')->nullable();
            $table->timestamp('revocado_en')->nullable();
            $table->timestamp('ultimo_uso_en')->nullable();
            $table->string('ultimo_uso_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes_integracion');
    }
};
