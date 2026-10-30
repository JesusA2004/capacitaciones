<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de bajas administrable y corrección de documentos de salida:
 *  - causal_grupos: subcatálogos que RH captura dentro de cada forma de salida
 *    (p. ej. «Pensión» o «Enfermedad» en Otras terminaciones). No se siembra nada.
 *  - causales_rotacion: subcatálogo opcional y documento que genera la causal.
 *  - baja_documento_ediciones: cada corrección del contenido de un documento,
 *    con valores anteriores, motivo y usuario. Solo agrega: nunca toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('causal_grupos')) {
            Schema::create('causal_grupos', function (Blueprint $table): void {
                $table->id();
                $table->string('forma_salida', 2);
                $table->string('nombre', 80);
                $table->string('descripcion', 300)->nullable();
                $table->unsignedInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->unique(['forma_salida', 'nombre'], 'causal_grupos_forma_nombre_unique');
            });
        }

        Schema::table('causales_rotacion', function (Blueprint $table): void {
            if (! Schema::hasColumn('causales_rotacion', 'causal_grupo_id')) {
                $table->foreignId('causal_grupo_id')->nullable()->after('forma_salida')->constrained('causal_grupos')->nullOnDelete();
            }

            if (! Schema::hasColumn('causales_rotacion', 'documento_salida')) {
                $table->string('documento_salida', 40)->nullable()->after('implica_indemnizacion');
            }
        });

        if (! Schema::hasTable('baja_documento_ediciones')) {
            Schema::create('baja_documento_ediciones', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('baja_id')->constrained('bajas')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('clave', 40);
                $table->json('campos');
                $table->json('campos_anteriores')->nullable();
                $table->string('motivo', 500);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
                $table->timestamps();
                $table->index(['baja_id', 'clave'], 'baja_doc_ediciones_baja_clave_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('baja_documento_ediciones');

        // Con MyISAM la llave foránea nunca se crea: solo se suelta si existe.
        $tieneLlave = collect(Schema::getForeignKeys('causales_rotacion'))
            ->contains(fn (array $llave): bool => $llave['columns'] === ['causal_grupo_id']);

        Schema::table('causales_rotacion', function (Blueprint $table) use ($tieneLlave): void {
            if ($tieneLlave) {
                $table->dropForeign(['causal_grupo_id']);
            }

            if (Schema::hasColumn('causales_rotacion', 'causal_grupo_id')) {
                $table->dropColumn('causal_grupo_id');
            }

            if (Schema::hasColumn('causales_rotacion', 'documento_salida')) {
                $table->dropColumn('documento_salida');
            }
        });

        Schema::dropIfExists('causal_grupos');
    }
};
