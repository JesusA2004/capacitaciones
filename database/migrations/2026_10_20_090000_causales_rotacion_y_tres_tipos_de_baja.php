<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Solo tres tipos de baja (renuncia, rescisión, fin de contrato) + causales
 * de rotación como subcatálogo administrable (Bajas y finiquitos).
 *
 *  1. Tabla causales_rotacion con el catálogo inicial
 *     (config/causales_rotacion.php `iniciales`; sin pisar ediciones).
 *  2. causal_rotacion_id + tipo_baja_original en cierres_laborales y
 *     solicitudes_internas.
 *  3. Cada baja existente se reclasifica a tipo + causal según
 *     config/causales_rotacion.php `legado`. NADA se pierde: el valor
 *     anterior queda en tipo_baja_original (y el motivo libre intacto). Un
 *     valor que no está en el mapeo NO se toca y se reporta en el log.
 *  4. La configuración «causas que puede solicitar un jefe» guardada con
 *     valores anteriores se traduce a los tres tipos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('causales_rotacion')) {
            Schema::create('causales_rotacion', function (Blueprint $table): void {
                $table->id();
                $table->string('tipo', 30);
                $table->string('clave', 60)->nullable()->unique();
                $table->string('nombre', 150);
                $table->string('descripcion', 500)->nullable();
                $table->boolean('excluye_indemnizacion')->default(false);
                $table->boolean('activo')->default(true);
                $table->unsignedInteger('orden')->default(0);
                $table->timestamps();

                $table->unique(['tipo', 'nombre'], 'causales_rotacion_tipo_nombre_unique');
                $table->index(['tipo', 'activo', 'orden'], 'causales_rotacion_tipo_activo_orden_index');
            });
        }

        foreach (['cierres_laborales', 'solicitudes_internas'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'causal_rotacion_id')) {
                Schema::table($tabla, function (Blueprint $table) use ($tabla): void {
                    $table->foreignId('causal_rotacion_id')->nullable()->after('tipo_baja')
                        ->constrained('causales_rotacion', 'id', sprintf('%s_causal_rotacion_fk', $tabla))->restrictOnDelete();
                    $table->string('tipo_baja_original', 30)->nullable()->after('causal_rotacion_id');
                });
            }
        }

        $ids = $this->sembrarCatalogo();
        $legado = (array) config('causales_rotacion.legado', []);
        $vigentes = ['renuncia', 'rescision', 'fin_contrato'];

        foreach (['cierres_laborales', 'solicitudes_internas'] as $tabla) {
            $valores = DB::table($tabla)->whereNotNull('tipo_baja')->whereNull('tipo_baja_original')->distinct()->pluck('tipo_baja');

            foreach ($valores as $valor) {
                $valor = (string) $valor;
                $destino = $legado[$valor] ?? null;

                if (! is_array($destino)) {
                    $n = DB::table($tabla)->where('tipo_baja', $valor)->whereNull('tipo_baja_original')->count();
                    Log::warning('Migración causales de rotación: tipo de baja sin mapeo; se deja sin tocar.', ['tabla' => $tabla, 'valor' => $valor, 'filas' => $n]);

                    continue;
                }

                $causal = is_string($destino['causal'] ?? null) ? ($ids[$destino['causal']] ?? null) : null;

                if ($causal === null) {
                    $n = DB::table($tabla)->where('tipo_baja', $valor)->whereNull('tipo_baja_original')->count();
                    Log::warning('Migración causales de rotación: baja reclasificada SIN causal (RH debe revisarla).', ['tabla' => $tabla, 'valor' => $valor, 'tipo' => $destino['tipo'], 'filas' => $n]);
                }

                DB::table($tabla)->where('tipo_baja', $valor)->whereNull('tipo_baja_original')->update([
                    'tipo_baja_original' => $valor,
                    'tipo_baja' => in_array($destino['tipo'], $vigentes, true) ? $destino['tipo'] : $valor,
                    'causal_rotacion_id' => $causal,
                ]);
            }
        }

        $this->traducirConfiguracion($legado, $vigentes);
    }

    public function down(): void
    {
        foreach (['cierres_laborales', 'solicitudes_internas'] as $tabla) {
            if (Schema::hasColumn($tabla, 'tipo_baja_original')) {
                DB::table($tabla)->whereNotNull('tipo_baja_original')->update(['tipo_baja' => DB::raw('tipo_baja_original')]);

                Schema::table($tabla, function (Blueprint $table) use ($tabla): void {
                    $table->dropForeign(sprintf('%s_causal_rotacion_fk', $tabla));
                    $table->dropColumn(['causal_rotacion_id', 'tipo_baja_original']);
                });
            }
        }

        Schema::dropIfExists('causales_rotacion');
    }

    /**
     * Inserta las causales iniciales que falten (por clave) y regresa
     * clave => id. Nunca modifica una causal existente.
     *
     * @return array<string, int>
     */
    private function sembrarCatalogo(): array
    {
        $orden = 0;

        foreach ((array) config('causales_rotacion.iniciales', []) as $tipo => $causales) {
            foreach ((array) $causales as $clave => $datos) {
                $orden += 10;

                if (DB::table('causales_rotacion')->where('clave', $clave)->exists()) {
                    continue;
                }

                DB::table('causales_rotacion')->insert([
                    'tipo' => (string) $tipo,
                    'clave' => (string) $clave,
                    'nombre' => (string) $datos['nombre'],
                    'excluye_indemnizacion' => (bool) ($datos['excluye_indemnizacion'] ?? false),
                    'activo' => true,
                    'orden' => $orden,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return DB::table('causales_rotacion')->whereNotNull('clave')->pluck('id', 'clave')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $legado
     * @param  list<string>  $vigentes
     */
    private function traducirConfiguracion(array $legado, array $vigentes): void
    {
        if (! Schema::hasTable('configuraciones_sistema')) {
            return;
        }

        $fila = DB::table('configuraciones_sistema')->where('clave', 'rh.causas_cierre_solicitables')->first();

        if ($fila === null || ! is_string($fila->valor)) {
            return;
        }

        $valores = json_decode($fila->valor, true);

        if (! is_array($valores)) {
            return;
        }

        $nuevos = [];

        foreach ($valores as $valor) {
            $tipo = in_array($valor, $vigentes, true) ? $valor : ($legado[$valor]['tipo'] ?? null);

            if (is_string($tipo) && ! in_array($tipo, $nuevos, true)) {
                $nuevos[] = $tipo;
            }
        }

        DB::table('configuraciones_sistema')->where('id', $fila->id)->update(['valor' => json_encode($nuevos === [] ? $vigentes : $nuevos), 'updated_at' => now()]);
        // Misma clave de caché que ConfiguracionSistemaService::CACHE.
        Cache::forget('configuracion_sistema.valores');
    }
};
