<?php

namespace App\Services\Evaluacion;

use App\Enums\EstadoIntentoCuestionario;
use App\Enums\EstadoMultimedia;
use App\Enums\OrigenRecursoMultimedia;
use App\Enums\TipoPregunta;
use App\Enums\TipoRecursoMultimedia;
use App\Enums\VisibilidadRecursoMultimedia;
use App\Models\Cuestionario;
use App\Models\IntentoCuestionario;
use App\Models\Pregunta;
use App\Models\RecursoMultimedia;
use App\Models\RespuestaCuestionario;
use App\Models\User;
use App\Notifications\CuestionarioCalificadoNotification;
use App\Services\Capacitacion\ProgresoService;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Motor de intentos de cuestionario: inicio (respetando intentos_maximos),
 * calificacion automatica de las preguntas de opcion/verdadero-falso al
 * enviar, y calificacion manual de las demas (permiso respuestas.calificar).
 * Un intento solo pasa a "calificado" cuando todas sus preguntas tienen una
 * respuesta calificada (automatica o manual); solo entonces se decide si el
 * usuario aprobo y, si aprobo, se completa la leccion via ProgresoService.
 *
 * El orden de preguntas/opciones y el puntaje se fijan una sola vez al
 * iniciar el intento (no se recalculan en cada carga de pagina) y el tiempo
 * limite se valida siempre contra la hora del servidor, nunca contra el
 * reloj del navegador. Ver docs/AUDITORIA_CUMPLIMIENTO.md seccion 10.
 */
class IntentoCuestionarioService
{
    public function __construct(
        private readonly ProgresoService $progresoService,
        private readonly MediaStorageService $storage,
    ) {}

    /**
     * @throws \RuntimeException si ya se alcanzo el numero maximo de intentos
     */
    public function iniciarIntento(User $usuario, Cuestionario $cuestionario): IntentoCuestionario
    {
        $enProgreso = IntentoCuestionario::query()
            ->where('cuestionario_id', $cuestionario->id)
            ->where('user_id', $usuario->id)
            ->where('estado', EstadoIntentoCuestionario::EnProgreso->value)
            ->first();

        if ($enProgreso) {
            return $this->expirarSiVencido($enProgreso);
        }

        $intentosRealizados = IntentoCuestionario::query()
            ->where('cuestionario_id', $cuestionario->id)
            ->where('user_id', $usuario->id)
            ->count();

        if ($cuestionario->intentos_maximos !== null && $intentosRealizados >= $cuestionario->intentos_maximos) {
            throw new \RuntimeException('Ya alcanzaste el número máximo de intentos permitidos para este cuestionario.');
        }

        $preguntas = $cuestionario->preguntas()->with('opciones')->get();
        $idsPreguntas = $preguntas->pluck('id');

        $ahora = now();

        return IntentoCuestionario::create([
            'cuestionario_id' => $cuestionario->id,
            'user_id' => $usuario->id,
            'numero_intento' => $intentosRealizados + 1,
            'estado' => EstadoIntentoCuestionario::EnProgreso->value,
            'iniciado_en' => $ahora,
            'fecha_limite' => $cuestionario->tiempo_limite_minutos !== null
                ? $ahora->clone()->addMinutes($cuestionario->tiempo_limite_minutos)->addSeconds($cuestionario->tolerancia_segundos)
                : null,
            'orden_preguntas' => ($cuestionario->aleatorizar_preguntas ? $idsPreguntas->shuffle() : $idsPreguntas)->values()->all(),
            'orden_opciones' => $this->calcularOrdenOpciones($cuestionario, $preguntas),
            'puntaje_configurado' => $preguntas->mapWithKeys(
                fn (Pregunta $pregunta) => [$pregunta->id => $pregunta->pivot->puntos ?? $pregunta->puntos],
            )->all(),
        ]);
    }

    /**
     * Cierra un intento vencido sin que el colaborador tenga que enviarlo:
     * se llama tanto al mostrar la pantalla del intento como antes de
     * procesar un envio, para que un intento nunca quede "en_progreso" mas
     * alla de su fecha_limite sin importar si el navegador llego a avisar.
     */
    public function expirarSiVencido(IntentoCuestionario $intento): IntentoCuestionario
    {
        if ($intento->estado === EstadoIntentoCuestionario::EnProgreso && $intento->haExpirado()) {
            $intento->update([
                'estado' => EstadoIntentoCuestionario::Expirado->value,
                'enviado_en' => $intento->fecha_limite,
            ]);
        }

        return $intento->fresh() ?? $intento;
    }

    /**
     * @param  array<int, array{pregunta_id: int, opcion_pregunta_id?: int|null, opciones_seleccionadas?: array<int, int>|null, respuesta_texto?: string|null, valor_numerico?: int|null, archivo?: UploadedFile|null}>  $respuestas
     *
     * @throws \RuntimeException si el intento ya fue enviado o expiró
     */
    public function enviarIntento(IntentoCuestionario $intento, array $respuestas): IntentoCuestionario
    {
        $intento = $this->expirarSiVencido($intento);

        if ($intento->estado === EstadoIntentoCuestionario::Expirado) {
            throw new \RuntimeException('El tiempo límite para este intento ya expiró; no se registraron respuestas.');
        }

        if ($intento->estado !== EstadoIntentoCuestionario::EnProgreso) {
            throw new \RuntimeException('Este intento ya fue enviado.');
        }

        $preguntas = $intento->cuestionario->preguntas()->with('opciones')->get()->keyBy('id');

        DB::transaction(function () use ($intento, $respuestas, $preguntas) {
            foreach ($respuestas as $respuesta) {
                $pregunta = $preguntas->get($respuesta['pregunta_id']);

                if ($pregunta) {
                    $this->guardarRespuesta($intento, $pregunta, $respuesta);
                }
            }

            $intento->update(['estado' => EstadoIntentoCuestionario::Enviado->value, 'enviado_en' => now()]);
        });

        $this->intentarFinalizar($intento->fresh());

        return $intento->fresh(['respuestas']);
    }

    public function calificarRespuestaManual(RespuestaCuestionario $respuesta, bool $esCorrecta, ?int $puntosObtenidos = null): void
    {
        $respuesta->loadMissing(['pregunta', 'intento']);

        $puntosPregunta = $respuesta->intento->puntaje_configurado[$respuesta->pregunta_id] ?? $respuesta->pregunta->puntos;

        $respuesta->update([
            'es_correcta' => $esCorrecta,
            'puntos_obtenidos' => $puntosObtenidos ?? ($esCorrecta ? $puntosPregunta : 0),
        ]);

        $this->intentarFinalizar($respuesta->intento);
    }

    /**
     * @param  array{opcion_pregunta_id?: int|null, opciones_seleccionadas?: array<int, int>|null, respuesta_texto?: string|null, valor_numerico?: int|null, archivo?: UploadedFile|null}  $datos
     */
    private function guardarRespuesta(IntentoCuestionario $intento, Pregunta $pregunta, array $datos): RespuestaCuestionario
    {
        $valores = [
            'opcion_pregunta_id' => $datos['opcion_pregunta_id'] ?? null,
            'opciones_seleccionadas' => $datos['opciones_seleccionadas'] ?? null,
            'respuesta_texto' => $datos['respuesta_texto'] ?? null,
            'valor_numerico' => $pregunta->tipo === TipoPregunta::Escala ? ($datos['valor_numerico'] ?? null) : null,
        ];

        if ($pregunta->tipo === TipoPregunta::CargaArchivo && ($datos['archivo'] ?? null) instanceof UploadedFile) {
            $this->validarArchivoPregunta($pregunta, $datos['archivo']);
            $valores['recurso_multimedia_id'] = $this->guardarArchivoRespuesta($intento, $pregunta, $datos['archivo']);
        }

        $puntosPregunta = $intento->puntaje_configurado[$pregunta->id] ?? $pregunta->puntos;

        if ($pregunta->tipo->seCalificaAutomaticamente()) {
            [$esCorrecta, $puntos] = $this->calificarAutomaticamente($pregunta, $valores, $puntosPregunta);
            $valores['es_correcta'] = $esCorrecta;
            $valores['puntos_obtenidos'] = $puntos;
        }

        return RespuestaCuestionario::updateOrCreate(
            ['intento_cuestionario_id' => $intento->id, 'pregunta_id' => $pregunta->id],
            $valores,
        );
    }

    private function validarArchivoPregunta(Pregunta $pregunta, UploadedFile $archivo): void
    {
        $extension = strtolower((string) $archivo->getClientOriginalExtension());
        $permitidas = $pregunta->extensiones_permitidas;

        if ($permitidas !== null && $permitidas !== [] && ! in_array($extension, array_map('strtolower', $permitidas), true)) {
            throw ValidationException::withMessages([
                'respuestas' => 'El archivo de la pregunta "'.$pregunta->enunciado.'" debe tener una de estas extensiones: '.implode(', ', $permitidas).'.',
            ]);
        }

        $limiteBytes = ($pregunta->tamano_maximo_mb ?? 20) * 1024 * 1024;

        if ($archivo->getSize() > $limiteBytes) {
            throw ValidationException::withMessages([
                'respuestas' => 'El archivo de la pregunta "'.$pregunta->enunciado.'" excede el tamaño máximo permitido.',
            ]);
        }
    }

    private function guardarArchivoRespuesta(IntentoCuestionario $intento, Pregunta $pregunta, UploadedFile $archivo): int
    {
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->storage->rutaDocumento($nombreInterno);
        $this->storage->guardar($archivo, $ruta);

        $recurso = RecursoMultimedia::create([
            'tipo' => TipoRecursoMultimedia::Documento->value,
            'nombre_original' => $archivo->getClientOriginalName(),
            'nombre_interno' => $nombreInterno,
            'disco' => config('media.disk'),
            'ruta_original' => $ruta,
            'mime_type' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
            'estado' => EstadoMultimedia::Disponible->value,
            'subido_por' => $intento->user_id,
            'origen' => OrigenRecursoMultimedia::Cuestionario->value,
            'visibilidad' => VisibilidadRecursoMultimedia::Restringida->value,
            'propietario_id' => $intento->user_id,
            'acceso_restringido' => true,
        ]);

        return $recurso->id;
    }

    /**
     * @param  array{opcion_pregunta_id?: int|null, opciones_seleccionadas?: array<int, int>|null, respuesta_texto?: string|null, valor_numerico?: int|null}  $valores
     * @return array{0: bool, 1: int}
     */
    private function calificarAutomaticamente(Pregunta $pregunta, array $valores, int $puntosPregunta): array
    {
        $correctas = $pregunta->opciones->where('es_correcta', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();

        $esCorrecta = match ($pregunta->tipo) {
            TipoPregunta::OpcionUnica, TipoPregunta::VerdaderoFalso => $correctas->contains((int) ($valores['opcion_pregunta_id'] ?? 0)),
            TipoPregunta::OpcionMultiple => collect($valores['opciones_seleccionadas'] ?? [])
                ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all() === $correctas->all(),
            default => false,
        };

        return [$esCorrecta, $esCorrecta ? $puntosPregunta : 0];
    }

    /**
     * @param  Collection<int, Pregunta>  $preguntas
     * @return array<int, array<int, int>>|null
     */
    private function calcularOrdenOpciones(Cuestionario $cuestionario, Collection $preguntas): ?array
    {
        if (! $cuestionario->aleatorizar_opciones) {
            return null;
        }

        $orden = [];

        foreach ($preguntas as $pregunta) {
            if ($pregunta->tipo->usaOpciones()) {
                $orden[$pregunta->id] = $pregunta->opciones->pluck('id')->shuffle()->values()->all();
            }
        }

        return $orden;
    }

    private function intentarFinalizar(IntentoCuestionario $intento): void
    {
        if ($intento->estado === EstadoIntentoCuestionario::Calificado) {
            return;
        }

        $intento->loadMissing(['respuestas', 'cuestionario.preguntas', 'cuestionario.leccion', 'usuario']);

        $totalPreguntas = $intento->cuestionario->preguntas->count();
        $respuestasCalificadas = $intento->respuestas->whereNotNull('es_correcta');

        if ($respuestasCalificadas->count() < $totalPreguntas) {
            return;
        }

        $puntosObtenidos = (int) $respuestasCalificadas->sum('puntos_obtenidos');
        $puntosPosibles = $intento->puntaje_configurado !== null
            ? (int) array_sum($intento->puntaje_configurado)
            : (int) $intento->cuestionario->preguntas->sum(fn (Pregunta $pregunta) => $pregunta->pivot->puntos ?? $pregunta->puntos);

        $calificacion = $puntosPosibles > 0 ? (int) round(($puntosObtenidos / $puntosPosibles) * 100) : 0;
        $aprobado = $calificacion >= $intento->cuestionario->calificacion_minima;

        $intento->update([
            'estado' => EstadoIntentoCuestionario::Calificado->value,
            'calificado_en' => now(),
            'calificacion' => $calificacion,
            'aprobado' => $aprobado,
        ]);

        if ($aprobado) {
            try {
                $this->progresoService->completarLeccion($intento->usuario, $intento->cuestionario->leccion);
            } catch (\RuntimeException) {
                // La leccion se bloqueo por requisitos entre el inicio del intento
                // y su calificacion (caso raro); el intento se conserva como aprobado.
            }
        }

        $intento->usuario->notify(new CuestionarioCalificadoNotification($intento));
    }
}
