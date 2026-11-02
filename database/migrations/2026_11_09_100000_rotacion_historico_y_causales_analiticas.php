<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rotación de personal (docs/ROTACION.md): histórico individual y causales
 * homologadas para análisis. No borra ni cambia ningún registro existente:
 *
 *  - causales_analiticas: catálogo de categorías de análisis (con su
 *    clasificación voluntaria / involuntaria / sin clasificar), editable.
 *  - causales_rotacion.causal_analitica_id: mapeo EXPLÍCITO de cada causal
 *    del catálogo administrativo a una categoría de análisis. La forma de
 *    salida jurídica de la causal no cambia.
 *  - bajas.causal_analitica_id: corrección de la categoría de UNA baja (con
 *    bitácora); la causal administrativa no se toca.
 *  - bajas_historicas: bajas individuales anteriores al módulo, importadas.
 *  - rotacion_correcciones: bitácora de correcciones (antes, después,
 *    comentario, quién y cuándo).
 */
return new class extends Migration
{
    /**
     * Categorías de análisis. Las once primeras son las del histórico de RH;
     * «Fallecimiento, incapacidad o jubilación» cubre las otras terminaciones
     * del catálogo (OT-01..03).
     *
     * @var list<array{clave: string, nombre: string, clasificacion: string, es_estructural: bool, descripcion: string|null}>
     */
    private const CATEGORIAS = [
        ['clave' => 'oferta_laboral', 'nombre' => 'Mejor oferta laboral o salarial', 'clasificacion' => 'voluntaria', 'es_estructural' => false, 'descripcion' => null],
        ['clave' => 'bajo_desempeno', 'nombre' => 'Bajo desempeño o resultados', 'clasificacion' => 'involuntaria', 'es_estructural' => false, 'descripcion' => null],
        ['clave' => 'abandono_faltas', 'nombre' => 'Abandono de empleo o faltas', 'clasificacion' => 'voluntaria', 'es_estructural' => false, 'descripcion' => 'Voluntaria solo para la estadística histórica de RH. En el flujo de Bajas sigue siendo una rescisión: esta clasificación no es una conclusión jurídica.'],
        ['clave' => 'personales_familiares', 'nombre' => 'Motivos personales o familiares', 'clasificacion' => 'voluntaria', 'es_estructural' => false, 'descripcion' => null],
        ['clave' => 'probidad_conducta', 'nombre' => 'Falta de probidad o conducta', 'clasificacion' => 'involuntaria', 'es_estructural' => false, 'descripcion' => null],
        ['clave' => 'sin_causal', 'nombre' => 'Sin causal registrada', 'clasificacion' => 'sin_clasificar', 'es_estructural' => false, 'descripcion' => 'Bajas que todavía no tienen motivo. Se corrigen desde Rotación → Histórico.'],
        ['clave' => 'renuncia_voluntaria', 'nombre' => 'Renuncia voluntaria', 'clasificacion' => 'voluntaria', 'es_estructural' => false, 'descripcion' => null],
        ['clave' => 'estructural', 'nombre' => 'Reestructura, cierre o fin de contrato', 'clasificacion' => 'involuntaria', 'es_estructural' => true, 'descripcion' => 'Se separa en indicadores complementarios; nunca se excluye del total.'],
        ['clave' => 'no_le_gusto', 'nombre' => 'No le gustó el trabajo', 'clasificacion' => 'voluntaria', 'es_estructural' => false, 'descripcion' => null],
        ['clave' => 'salud', 'nombre' => 'Motivos de salud', 'clasificacion' => 'voluntaria', 'es_estructural' => false, 'descripcion' => null],
        ['clave' => 'desfases_cartera', 'nombre' => 'Desfases en cartera', 'clasificacion' => 'involuntaria', 'es_estructural' => false, 'descripcion' => null],
        ['clave' => 'otras_terminaciones', 'nombre' => 'Fallecimiento, incapacidad o jubilación', 'clasificacion' => 'involuntaria', 'es_estructural' => false, 'descripcion' => 'Terminaciones ajenas a la decisión de las partes (OT-01 a OT-03).'],
    ];

    /**
     * Mapeo inicial del catálogo administrativo (config/bajas.php). Editable
     * después desde Rotación → Causales, con comentario y bitácora.
     *
     * @var array<string, string>
     */
    private const MAPEO = [
        'RV-01' => 'oferta_laboral',
        'RV-02' => 'no_le_gusto',
        'RV-03' => 'no_le_gusto',
        'RV-04' => 'no_le_gusto',
        'RV-05' => 'personales_familiares',
        'RV-06' => 'renuncia_voluntaria',
        'NR-01' => 'bajo_desempeno',
        'NR-02' => 'bajo_desempeno',
        'NR-03' => 'probidad_conducta',
        'NR-04' => 'estructural',
        'NR-05' => 'estructural',
        'NR-06' => 'estructural',
        'RE-01' => 'abandono_faltas',
        'RE-02' => 'desfases_cartera',
        'RE-03' => 'probidad_conducta',
        'RE-04' => 'probidad_conducta',
        'RE-05' => 'probidad_conducta',
        'OT-01' => 'otras_terminaciones',
        'OT-02' => 'otras_terminaciones',
        'OT-03' => 'otras_terminaciones',
    ];

    public function up(): void
    {
        Schema::create('causales_analiticas', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('nombre', 160);
            $table->string('clasificacion', 20);
            $table->boolean('es_estructural')->default(false);
            $table->string('descripcion', 500)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $ahora = now();

        foreach (self::CATEGORIAS as $i => $categoria) {
            DB::table('causales_analiticas')->insert([...$categoria, 'orden' => $i + 1, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        Schema::table('causales_rotacion', function (Blueprint $table) {
            $table->foreignId('causal_analitica_id')->nullable()->after('forma_salida')->constrained('causales_analiticas')->nullOnDelete();
        });

        $ids = DB::table('causales_analiticas')->pluck('id', 'clave');

        foreach (self::MAPEO as $clave => $categoria) {
            DB::table('causales_rotacion')->where('clave', $clave)->whereNull('causal_analitica_id')->update(['causal_analitica_id' => $ids[$categoria]]);
        }

        Schema::table('bajas', function (Blueprint $table) {
            $table->foreignId('causal_analitica_id')->nullable()->after('causal_rotacion_id')->constrained('causales_analiticas')->nullOnDelete();
            $table->index(['fecha_baja', 'sucursal_id'], 'bajas_fecha_baja_sucursal_idx');
        });

        Schema::create('bajas_historicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_excel_id')->nullable()->constrained('importaciones_excel')->nullOnDelete();
            $table->string('llave', 64)->unique();
            $table->string('nombre', 200);
            $table->string('numero_empleado', 40)->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('baja_id')->nullable()->constrained('bajas')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->date('fecha_ingreso')->nullable();
            $table->date('fecha_baja');
            $table->string('motivo_original', 500)->nullable();
            $table->foreignId('causal_analitica_id')->nullable()->constrained('causales_analiticas')->nullOnDelete();
            $table->string('estado', 20)->default('valida');
            $table->string('nota', 500)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fecha_baja', 'sucursal_id'], 'bajas_hist_fecha_sucursal_idx');
            $table->index(['estado', 'fecha_baja'], 'bajas_hist_estado_fecha_idx');
        });

        Schema::create('rotacion_correcciones', function (Blueprint $table) {
            $table->id();
            $table->string('sujeto_tipo', 30);
            $table->unsignedBigInteger('sujeto_id');
            $table->string('campo', 60);
            $table->string('antes', 500)->nullable();
            $table->string('despues', 500)->nullable();
            $table->string('comentario', 1000);
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['sujeto_tipo', 'sujeto_id'], 'rotacion_corr_sujeto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotacion_correcciones');
        Schema::dropIfExists('bajas_historicas');

        Schema::table('bajas', function (Blueprint $table) {
            $table->dropIndex('bajas_fecha_baja_sucursal_idx');
            $table->dropConstrainedForeignId('causal_analitica_id');
        });

        Schema::table('causales_rotacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('causal_analitica_id');
        });

        Schema::dropIfExists('causales_analiticas');
    }
};
