<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recepción documental RH: CATÁLOGO CONFIGURABLE de «lo que recibo»
 * (docs/RECEPCION_DOCUMENTAL_RH.md → «Catálogo de lo que se recibe»).
 *
 * Una entrada por regla/evento de origen (altas, bajas, nómina…): nombre
 * visible, documentos que genera cada evento (subconjunto de los que la
 * regla sabe producir), encendida/apagada, orden y, opcionalmente, solo
 * ciertas sucursales o empresas. Es la fuente de verdad de la
 * materialización mensual; config/recepcion_documental.php queda como
 * valores por defecto con que se siembra (CatalogoReglasRecepcion::sembrar).
 *
 * Solo crea la tabla nueva: no toca cortes ni renglones existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recepcion_documental_catalogo')) {
            return;
        }

        Schema::create('recepcion_documental_catalogo', function (Blueprint $table) {
            $table->id();
            $table->string('regla', 30);
            $table->string('nombre', 160);
            $table->string('descripcion', 500)->nullable();
            // Claves de documento que se esperan por evento (lista JSON).
            $table->json('documentos')->nullable();
            $table->boolean('activa')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            // null = todas las sucursales / empresas.
            $table->json('sucursal_ids')->nullable();
            $table->json('empresa_ids')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('regla', 'rec_doc_catalogo_regla_unico');
            $table->index(['activa', 'orden'], 'rec_doc_catalogo_activa_orden_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recepcion_documental_catalogo');
    }
};
