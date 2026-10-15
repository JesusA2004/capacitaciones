<?php

use App\Enums\TipoTarea;
use App\Models\Candidato;
use App\Services\Tareas\TareaService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
| Cierre 2026-10-15 del reclutamiento:
|
| 1. La preautorización del gerente deja de existir. Los candidatos que
|    estaban en «preseleccion_gerente» pasan a «autorizacion_rh_pendiente»
|    SIN perder historial: su preautorización pendiente queda «omitida» con
|    el motivo, se abre la etapa de RH y se deja nota en su timeline. Las
|    preautorizaciones ya decididas se conservan tal cual (histórico).
|
| 2. Estudio socioeconómico: cargar evidencia NO es decidir. `resultado`
|    pasa a ser nulo hasta que el responsable lo revisa (viable/no viable),
|    con quién y cuándo revisó.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidato_socioeconomicos', function (Blueprint $table): void {
            $table->string('resultado', 20)->nullable()->change();
            $table->foreignId('revisado_por')->nullable()->after('registrado_por')->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable()->after('revisado_por');
            $table->text('revision_observaciones')->nullable()->after('revisado_en');
        });

        // Estudios ya registrados con resultado: esa decisión fue la revisión.
        DB::table('candidato_socioeconomicos')
            ->whereNotNull('resultado')
            ->whereNull('revisado_en')
            ->update(['revisado_en' => DB::raw('created_at'), 'revisado_por' => DB::raw('registrado_por')]);

        $this->migrarPreselecciones();
    }

    public function down(): void
    {
        Schema::table('candidato_socioeconomicos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revisado_por');
            $table->dropColumn(['revisado_en', 'revision_observaciones']);
        });
    }

    private function migrarPreselecciones(): void
    {
        $tipoCandidato = (new Candidato)->getMorphClass();
        $ahora = now();
        $nota = 'La preautorización del gerente se retiró del proceso (2026-10-15): el candidato pasa directo a la autorización de RH.';

        $ids = DB::table('candidatos')->where('estado', 'preseleccion_gerente')->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $tipoCandidato, $ahora, $nota): void {
                $pendientes = DB::table('aprobaciones')
                    ->where('aprobable_type', $tipoCandidato)
                    ->where('aprobable_id', $id)
                    ->where('proceso', 'seleccion_candidato')
                    ->where('etapa', 'preautorizacion')
                    ->where('estado', 'pendiente')
                    ->get();

                foreach ($pendientes as $pre) {
                    DB::table('aprobaciones')->where('id', $pre->id)->update([
                        'estado' => 'omitido',
                        'comentario' => $nota,
                        'decidido_en' => $ahora,
                        'updated_at' => $ahora,
                    ]);

                    $yaHayRh = DB::table('aprobaciones')
                        ->where('aprobable_type', $tipoCandidato)
                        ->where('aprobable_id', $id)
                        ->where('proceso', 'seleccion_candidato')
                        ->where('etapa', 'autorizacion_rh')
                        ->where('ronda', $pre->ronda)
                        ->exists();

                    if (! $yaHayRh) {
                        DB::table('aprobaciones')->insert([
                            'aprobable_type' => $tipoCandidato,
                            'aprobable_id' => $id,
                            'proceso' => 'seleccion_candidato',
                            'etapa' => 'autorizacion_rh',
                            'ronda' => $pre->ronda,
                            'colaborador_id' => $pre->colaborador_id,
                            'candidato_id' => $pre->candidato_id,
                            'solicitante_user_id' => $pre->solicitante_user_id,
                            'capacidad_requerida' => 'ciclo.autorizar_rh',
                            'estado' => 'pendiente',
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                    }
                }

                DB::table('candidatos')->where('id', $id)->update(['estado' => 'autorizacion_rh_pendiente', 'updated_at' => $ahora]);

                DB::table('seguimientos_candidato')->insert([
                    'candidato_id' => $id,
                    'tipo' => 'cambio_estado',
                    'nota' => $nota,
                    'estado_anterior' => 'preseleccion_gerente',
                    'estado_nuevo' => 'autorizacion_rh_pendiente',
                    'fecha' => $ahora,
                    'registrado_por' => null,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            });

            // Bandeja: cierra el pendiente de preautorización y abre el de
            // RH. Nunca bloquea la migración (los datos ya quedaron bien).
            try {
                $candidato = Candidato::query()->whereKey($id)->first();

                if ($candidato !== null) {
                    $tareas = app(TareaService::class);
                    $tareas->resolver(TipoTarea::CandidatoPreautorizacion, $candidato);
                    $tareas->abrir(TipoTarea::CandidatoAutorizacionRh, $candidato, [
                        'titulo' => sprintf('%s: %s', TipoTarea::CandidatoAutorizacionRh->etiqueta(), $candidato->nombreCompleto()),
                        'candidato' => $candidato,
                        'sucursal_id' => $candidato->sucursal_id,
                        'permiso' => 'ciclo.autorizar_rh',
                        'accion' => 'autorizar_rh',
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('Migración preautorización: no se pudo actualizar la bandeja.', ['candidato_id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }
};
