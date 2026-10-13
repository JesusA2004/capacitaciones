<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Descriptivos de puesto OFICIALES (PDF de Dirección/RH). El PDF se guarda
| intacto (SHA-256, nunca se edita ni se regenera) y su contenido leído de
| la capa de texto queda estructurado en `datos` (JSON tipado, ver
| App\Models\DescriptivoPuesto). Un descriptivo puede aplicar a varios
| puestos (p. ej. Gerente Regional Q1 y Q3); un PDF distinto del mismo
| descriptivo es una NUEVA versión (la anterior se conserva).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('descriptivos_puesto', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 120)->index();
            $table->string('nombre_puesto', 190);
            $table->unsignedInteger('version')->default(1);
            $table->string('archivo_nombre', 255);
            $table->string('disk', 40);
            $table->string('path', 255);
            $table->char('sha256', 64)->unique();
            $table->unsignedInteger('paginas')->default(0);
            $table->json('datos');
            $table->json('advertencias')->nullable();
            $table->foreignId('importado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('descriptivo_puesto_puestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('descriptivo_puesto_id')->constrained('descriptivos_puesto')->cascadeOnDelete();
            $table->foreignId('puesto_id')->constrained('puestos')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['descriptivo_puesto_id', 'puesto_id'], 'descriptivo_puesto_unico');
        });

        Schema::table('perfiles_puesto', function (Blueprint $table) {
            // documento = precargado del descriptivo oficial (borrador);
            // rh = RH lo ajustó (nunca se sobrescribe al importar).
            $table->string('origen', 20)->default('rh')->after('puesto_id');
            $table->foreignId('descriptivo_puesto_id')->nullable()->after('origen')->constrained('descriptivos_puesto')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('perfiles_puesto', function (Blueprint $table) {
            $table->dropConstrainedForeignId('descriptivo_puesto_id');
            $table->dropColumn('origen');
        });
        Schema::dropIfExists('descriptivo_puesto_puestos');
        Schema::dropIfExists('descriptivos_puesto');
    }
};
