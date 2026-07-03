<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de salud de cada integración externa (Google Meet, Zoom): no
 * guarda credenciales (esas viven en .env/el archivo de la cuenta de
 * servicio, nunca en la base de datos), solo el resultado de la última
 * verificación, para que el panel administrativo pueda mostrar "conectado"/
 * "con errores" sin tener que llamar a la API en cada carga de página.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conexiones_integracion', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 20)->unique();
            // activa | inactiva | error
            $table->string('estado', 20)->default('inactiva');
            $table->timestamp('verificado_en')->nullable();
            $table->text('ultimo_error')->nullable();
            // Metadatos no sensibles (p. ej. el correo del usuario impersonado,
            // el account_id de Zoom) — nunca tokens ni llaves privadas.
            $table->json('metadatos')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conexiones_integracion');
    }
};
