<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clasificación de «por qué ya no continuó» + preguntas de filtro
 * eliminatorias al registrar un candidato (p. ej. «¿Maneja moto?» para
 * Gestor). Ver App\Services\Reclutamiento\PreguntasFiltroCandidatoService.
 */
return new class extends Migration
{
    /**
     * Categoría de cada motivo base (MotivosRechazoCandidatoSeeder). Los
     * motivos que RH haya creado a mano quedan en «empresa» hasta que los
     * reclasifique desde Configuración → Parámetros de RH.
     *
     * @var array<string, string>
     */
    private const CATEGORIAS = [
        'no_cumple_perfil' => 'empresa',
        'entrevista_no_favorable' => 'empresa',
        'psicometricos' => 'empresa',
        'socioeconomico' => 'empresa',
        'referencias' => 'empresa',
        'vacante_cubierta' => 'empresa',
        'antecedente_laboral' => 'empresa',
        'conducta' => 'empresa',
        'informacion_falsa' => 'empresa',
        'otro' => 'empresa',
        'no_respondio' => 'sin_contacto',
        'desistio' => 'candidato',
    ];

    public function up(): void
    {
        Schema::table('motivos_rechazo_candidato', function (Blueprint $table): void {
            $table->string('categoria', 30)->default('empresa')->after('nombre');
        });

        foreach (self::CATEGORIAS as $clave => $categoria) {
            DB::table('motivos_rechazo_candidato')->where('clave', $clave)->update(['categoria' => $categoria]);
        }

        Schema::create('preguntas_filtro_candidato', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('pregunta', 200);
            // IDs de puestos a los que aplica; null/vacío = todos los puestos.
            $table->json('puestos')->nullable();
            // Respuesta que el puesto exige: true = «Sí», false = «No».
            $table->boolean('respuesta_requerida')->default(true);
            // Si no cumple: el candidato se registra, pero queda «No viable».
            $table->boolean('eliminatoria')->default(true);
            $table->foreignId('motivo_rechazo_id')->nullable()->constrained('motivos_rechazo_candidato')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('candidato_respuestas_filtro', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->foreignId('pregunta_filtro_candidato_id')->nullable()->constrained('preguntas_filtro_candidato')->nullOnDelete();
            // Copia del texto: el historial no cambia si RH edita la pregunta.
            $table->string('pregunta', 200);
            $table->boolean('respuesta');
            $table->boolean('cumple');
            $table->boolean('eliminatoria');
            $table->timestamps();

            $table->unique(['candidato_id', 'pregunta_filtro_candidato_id'], 'cand_resp_filtro_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidato_respuestas_filtro');
        Schema::dropIfExists('preguntas_filtro_candidato');

        Schema::table('motivos_rechazo_candidato', function (Blueprint $table): void {
            $table->dropColumn('categoria');
        });
    }
};
