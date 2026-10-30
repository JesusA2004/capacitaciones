<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propuesta de mejoras Etapa 1 (Pantalla 4, catálogo de causales):
 *
 *  C2 — Marca «Implica indemnización»: nueva columna booleana en
 *       causales_rotacion; se activa en NR-01, NR-03, NR-05 y NR-06. (El
 *       cableado de la marca al cálculo del finiquito se hace aparte; aquí
 *       solo queda el dato del catálogo, regla configurable + advertencia.)
 *
 *  C3 — Grupo OT «Otras terminaciones» (forma de salida nueva) con las causales
 *       OT-01 Fallecimiento, OT-02 Incapacidad permanente total y OT-03
 *       Jubilación o pensión. Generan una constancia según el caso (se adjunta),
 *       no carta/convenio/aviso, y no se fuerzan a liquidación.
 *
 * Aditiva e idempotente; segura sobre datos existentes (no migrate:fresh).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('causales_rotacion') && ! Schema::hasColumn('causales_rotacion', 'implica_indemnizacion')) {
            Schema::table('causales_rotacion', function (Blueprint $table): void {
                $table->boolean('implica_indemnizacion')->default(false)->after('excluye_indemnizacion');
            });
        }

        // C2 — backfill de la marca en las causales de no renovación indicadas.
        if (Schema::hasTable('causales_rotacion')) {
            DB::table('causales_rotacion')
                ->whereIn('clave', ['NR-01', 'NR-03', 'NR-05', 'NR-06'])
                ->update(['implica_indemnizacion' => true]);
        }

        $ahora = now();

        // C3 — forma de salida OT en el catálogo (idempotente).
        if (Schema::hasTable('formas_salida') && ! DB::table('formas_salida')->where('clave', 'OT')->exists()) {
            DB::table('formas_salida')->insert([
                'clave' => 'OT',
                'nombre' => 'Otras terminaciones',
                'descripcion' => 'La relación termina por una causa ajena a la decisión de las partes (fallecimiento, incapacidad permanente total, jubilación o pensión).',
                'orden' => 40,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // C3 — causales OT (idempotente por clave). Constancia según el caso;
        // sin indemnización forzada; comentario obligatorio por ser sensibles.
        if (Schema::hasTable('causales_rotacion')) {
            $ot = [
                'OT-01' => ['nombre' => 'Fallecimiento', 'descripcion' => 'El colaborador fallece. El finiquito se entrega a los beneficiarios conforme a la ley; adjunta el acta de defunción.'],
                'OT-02' => ['nombre' => 'Incapacidad permanente total', 'descripcion' => 'El IMSS dictamina incapacidad permanente total que impide continuar laborando (ST-3). Adjunta el dictamen.'],
                'OT-03' => ['nombre' => 'Jubilación o pensión', 'descripcion' => 'El colaborador se jubila o accede a una pensión. Adjunta la resolución de pensión del IMSS.'],
            ];

            $orden = (int) DB::table('causales_rotacion')->where('forma_salida', 'OT')->max('orden');

            foreach ($ot as $clave => $datos) {
                if (DB::table('causales_rotacion')->where('clave', $clave)->exists()) {
                    continue;
                }

                $orden += 10;
                DB::table('causales_rotacion')->insert([
                    'tipo' => 'otra_terminacion',
                    'forma_salida' => 'OT',
                    'clave' => $clave,
                    'nombre' => $datos['nombre'],
                    'descripcion' => $datos['descripcion'],
                    'requiere_juridico' => false,
                    'comentario_obligatorio' => true,
                    'alerta' => null,
                    'excluye_indemnizacion' => false,
                    'implica_indemnizacion' => false,
                    'activo' => true,
                    'legado' => false,
                    'orden' => $orden,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }

        // C3 — documentos requeridos de la forma OT (constancia adjunta + finiquito).
        if (Schema::hasTable('documentos_requeridos_baja')) {
            $docs = [
                'OT.constancia' => ['nombre' => 'Constancia o documento que acredita la causa (acta de defunción, dictamen ST-3 o resolución de pensión)', 'sistema' => null],
                'OT.finiquito' => ['nombre' => 'Finiquito firmado', 'sistema' => 'finiquito'],
            ];

            $orden = (int) DB::table('documentos_requeridos_baja')->where('forma_salida', 'OT')->max('orden');

            foreach ($docs as $clave => $datos) {
                if (DB::table('documentos_requeridos_baja')->where('clave', $clave)->exists()) {
                    continue;
                }

                $orden += 10;
                DB::table('documentos_requeridos_baja')->insert([
                    'clave' => $clave,
                    'forma_salida' => 'OT',
                    'causal_rotacion_id' => null,
                    'nombre' => $datos['nombre'],
                    'obligatorio_para_cerrar' => true,
                    'documento_sistema' => $datos['sistema'],
                    'orden' => $orden,
                    'activo' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Datos del catálogo: las causales/forma OT nunca se borran (RN-15, se
        // desactivan); la columna se conserva para no perder la marca histórica.
        // Operación de un solo sentido a nivel de datos.
        if (Schema::hasTable('causales_rotacion') && Schema::hasColumn('causales_rotacion', 'implica_indemnizacion')) {
            Schema::table('causales_rotacion', function (Blueprint $table): void {
                $table->dropColumn('implica_indemnizacion');
            });
        }
    }
};
