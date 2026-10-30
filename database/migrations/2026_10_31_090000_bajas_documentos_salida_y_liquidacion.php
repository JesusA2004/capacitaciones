<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 1 · Bajas (Pantalla 4): datos del aviso de rescisión (hechos, testigos,
 * entrega), clasificación de los adjuntos de la baja (evidencia, firmado de
 * salida), fundamento legal de la causal y tipo del finiquito (finiquito /
 * liquidación) fijado al calcular.
 *
 * Aditiva e idempotente: no modifica registros existentes salvo llenar
 * fundamento_legal donde el propio catálogo ya citaba la fracción del art. 47
 * (config/bajas.php). Los finiquitos anteriores quedan con tipo_documento NULL
 * y el tipo se deriva al leerlos: no se reescribe historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('baja_avisos_rescision')) {
            Schema::create('baja_avisos_rescision', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('baja_id')->unique()->constrained('bajas')->cascadeOnDelete();
                $table->string('fundamento', 255);
                $table->text('que_ocurrio');
                $table->dateTime('hechos_en');
                $table->string('lugar', 255);
                $table->string('constataron', 500);
                $table->text('relato');
                $table->json('testigos');
                $table->date('fecha_aviso');
                $table->string('metodo_entrega', 20);
                $table->date('fecha_entrega');
                $table->boolean('plazo_validado')->default(false);
                $table->foreignId('plazo_validado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('plazo_validado_en')->nullable();
                $table->foreignId('capturado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('baja_documentos') && ! Schema::hasColumn('baja_documentos', 'clasificacion')) {
            Schema::table('baja_documentos', function (Blueprint $table): void {
                $table->string('clasificacion', 40)->nullable()->after('documento_requerido_id');
            });
        }

        if (Schema::hasTable('finiquito_calculos') && ! Schema::hasColumn('finiquito_calculos', 'tipo_documento')) {
            Schema::table('finiquito_calculos', function (Blueprint $table): void {
                $table->string('tipo_documento', 20)->nullable()->after('estado');
            });
        }

        if (Schema::hasTable('causales_rotacion') && ! Schema::hasColumn('causales_rotacion', 'fundamento_legal')) {
            Schema::table('causales_rotacion', function (Blueprint $table): void {
                $table->string('fundamento_legal', 255)->nullable()->after('alerta');
            });
        }

        if (Schema::hasTable('causales_rotacion')) {
            foreach (['RE-01' => 'Art. 47, fracción X, LFT', 'RE-03' => 'Art. 47, fracción II, LFT', 'RE-04' => 'Art. 47, fracción XIII, LFT'] as $clave => $fundamento) {
                DB::table('causales_rotacion')->where('clave', $clave)->whereNull('fundamento_legal')->update(['fundamento_legal' => $fundamento]);
            }
        }
    }

    public function down(): void
    {
        // El aviso de rescisión contiene hechos capturados por RH: si ya hay
        // renglones, se conserva la tabla (restaurar desde respaldo si hace falta).
        if (Schema::hasTable('baja_avisos_rescision') && DB::table('baja_avisos_rescision')->doesntExist()) {
            Schema::drop('baja_avisos_rescision');
        }

        if (Schema::hasTable('baja_documentos') && Schema::hasColumn('baja_documentos', 'clasificacion')) {
            Schema::table('baja_documentos', fn (Blueprint $table) => $table->dropColumn('clasificacion'));
        }

        if (Schema::hasTable('finiquito_calculos') && Schema::hasColumn('finiquito_calculos', 'tipo_documento')) {
            Schema::table('finiquito_calculos', fn (Blueprint $table) => $table->dropColumn('tipo_documento'));
        }

        if (Schema::hasTable('causales_rotacion') && Schema::hasColumn('causales_rotacion', 'fundamento_legal')) {
            Schema::table('causales_rotacion', fn (Blueprint $table) => $table->dropColumn('fundamento_legal'));
        }
    }
};
