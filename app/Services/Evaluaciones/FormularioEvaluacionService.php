<?php

namespace App\Services\Evaluaciones;

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\ColorAcentoFormulario;
use App\Enums\EstadoFormularioEvaluacion;
use App\Enums\EstiloEncabezadoFormulario;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\FormularioEvaluacion;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fuente ÚNICA de los formularios de evaluación (inducción FO-RH-EI y
 * periodo de prueba FO-RH-EPP-001). Los servicios de evaluación le piden la
 * definición en vez de leer config():
 *
 *   - Una evaluación con formulario_evaluacion_id usa SIEMPRE esa versión
 *     (calificación, revisión, PDF): publicar otra versión nunca la cambia.
 *   - Una evaluación sin versión que todavía no se contesta toma la versión
 *     publicada vigente (y la fija al contestarse).
 *   - Una evaluación sin versión ya contestada, o una base sin ninguna fila,
 *     usa el formato original de config/ (idéntico a la versión 1 sembrada).
 *
 * Versionado: a lo más un borrador y un publicado por clave; publicar
 * archiva el anterior. Una versión publicada es inmutable.
 *
 * Las respuestas correctas y las rúbricas viven en `definicion`: NUNCA salen
 * al colaborador (contenidoPublicoInduccion() las filtra).
 */
class FormularioEvaluacionService
{
    public const PERMISO_ADMINISTRAR = 'formularios_evaluacion.administrar';

    /** La escala del periodo de prueba es fija 1-4 (la app y el formato Word la usan). */
    private const ESCALA_PRUEBA_MIN = 1;

    private const ESCALA_PRUEBA_MAX = 4;

    /** La opinión de la inducción es fija 1-5 (la app y el PDF la usan). */
    private const ESCALA_OPINION_MIN = 1;

    private const ESCALA_OPINION_MAX = 5;

    private const MAX_PREGUNTAS = 60;

    private const MAX_LETRAS = 10;

    public function __construct(private readonly AuditoriaService $auditoria) {}

    // ---------------------------------------------------------------------
    // Resolución de versiones
    // ---------------------------------------------------------------------

    public function publicado(ClaveFormularioEvaluacion $clave): ?FormularioEvaluacion
    {
        return FormularioEvaluacion::query()
            ->where('clave', $clave->value)
            ->where('estado', EstadoFormularioEvaluacion::Publicado->value)
            ->orderByDesc('version')
            ->first();
    }

    public function borrador(ClaveFormularioEvaluacion $clave): ?FormularioEvaluacion
    {
        return FormularioEvaluacion::query()
            ->where('clave', $clave->value)
            ->where('estado', EstadoFormularioEvaluacion::Borrador->value)
            ->first();
    }

    /** Versión con la que se contesta/califica esta evaluación de inducción (null = config). */
    public function formularioDeInduccion(EvaluacionInduccion $evaluacion): ?FormularioEvaluacion
    {
        if ($evaluacion->formulario_evaluacion_id !== null) {
            return FormularioEvaluacion::query()->where('id', $evaluacion->formulario_evaluacion_id)->first();
        }

        return $evaluacion->intentos_count === 0 ? $this->publicado(ClaveFormularioEvaluacion::Induccion) : null;
    }

    /** Versión con la que se captura/lee esta evaluación de periodo de prueba (null = config). */
    public function formularioDePeriodoPrueba(EvaluacionPeriodoPrueba $evaluacion): ?FormularioEvaluacion
    {
        if ($evaluacion->formulario_evaluacion_id !== null) {
            return FormularioEvaluacion::query()->where('id', $evaluacion->formulario_evaluacion_id)->first();
        }

        return $evaluacion->capturada_en === null ? $this->publicado(ClaveFormularioEvaluacion::PeriodoPrueba) : null;
    }

    /**
     * Definición completa (con respuestas correctas y rúbrica — SOLO
     * servidor) de la inducción: la de la evaluación, o la vigente.
     *
     * @return array<string, mixed>
     */
    public function definicionInduccion(?EvaluacionInduccion $evaluacion = null): array
    {
        $formulario = $evaluacion !== null
            ? $this->formularioDeInduccion($evaluacion)
            : $this->publicado(ClaveFormularioEvaluacion::Induccion);

        return $this->definicionDe(ClaveFormularioEvaluacion::Induccion, $formulario);
    }

    /**
     * Definición completa del periodo de prueba: la de la evaluación, o la vigente.
     *
     * @return array<string, mixed>
     */
    public function definicionPeriodoPrueba(?EvaluacionPeriodoPrueba $evaluacion = null): array
    {
        $formulario = $evaluacion !== null
            ? $this->formularioDePeriodoPrueba($evaluacion)
            : $this->publicado(ClaveFormularioEvaluacion::PeriodoPrueba);

        return $this->definicionDe(ClaveFormularioEvaluacion::PeriodoPrueba, $formulario);
    }

    /**
     * Definición normalizada + metadatos (formulario_id, version, titulo,
     * descripcion, diseno). null = formato original de config/.
     *
     * @return array<string, mixed>
     */
    public function definicionDe(ClaveFormularioEvaluacion $clave, ?FormularioEvaluacion $formulario): array
    {
        if ($formulario === null) {
            $base = $this->metadatosPorDefecto($clave);

            return [
                ...$this->normalizar($clave, $this->definicionDesdeConfig($clave)),
                'formulario_id' => null,
                'version' => $base['version'],
                'codigo' => $base['codigo'],
                'titulo' => $base['titulo'],
                'descripcion' => $base['descripcion'],
                'diseno' => $this->normalizarDiseno($clave, []),
            ];
        }

        return [
            ...$this->normalizar($clave, $formulario->definicion),
            'formulario_id' => $formulario->id,
            'version' => $formulario->etiquetaVersion(),
            'codigo' => $formulario->codigo,
            'titulo' => $formulario->titulo,
            'descripcion' => $formulario->descripcion,
            'diseno' => $this->normalizarDiseno($clave, (array) ($formulario->diseno ?? [])),
        ];
    }

    /**
     * Lo único de la inducción que puede llegar al colaborador (web y app):
     * MISMA forma que siempre (version, instrucciones, seccion_a…seccion_e)
     * + `formulario` (título/diseño) que las apps anteriores ignoran.
     *
     * @param  array<string, mixed>  $definicion  salida de definicionInduccion()/definicionDe()
     * @return array<string, mixed>
     */
    public function contenidoPublicoInduccion(array $definicion): array
    {
        /** @var list<array{pregunta: string, opciones: list<string>}> $a */
        $a = $definicion['seccion_a'];
        /** @var list<array{afirmacion: string}> $b */
        $b = $definicion['seccion_b'];
        /** @var array{conceptos: list<array{concepto: string}>, descripciones: array<string, string>} $c */
        $c = $definicion['seccion_c'];
        /** @var list<array{clave: string, puntos_max: int, enunciado: string}> $d */
        $d = $definicion['seccion_d'];

        return [
            'version' => $definicion['version'],
            'instrucciones' => [
                'calificacion_aprobatoria' => $definicion['calificacion_aprobatoria'],
                'total_puntos' => $definicion['total_puntos'],
            ],
            'seccion_a' => array_map(fn (array $p, int $i): array => ['indice' => $i, 'pregunta' => $p['pregunta'], 'opciones' => $p['opciones']], $a, array_keys($a)),
            'seccion_b' => array_map(fn (array $p, int $i): array => ['indice' => $i, 'afirmacion' => $p['afirmacion']], $b, array_keys($b)),
            'seccion_c' => [
                'conceptos' => array_map(fn (array $p, int $i): array => ['indice' => $i, 'concepto' => $p['concepto']], $c['conceptos'], array_keys($c['conceptos'])),
                'descripciones' => $c['descripciones'],
            ],
            'seccion_d' => array_map(fn (array $caso): array => ['clave' => $caso['clave'], 'puntos_max' => $caso['puntos_max'], 'enunciado' => $caso['enunciado']], $d),
            'seccion_e' => $definicion['seccion_e'],
            'formulario' => $this->presentacion(ClaveFormularioEvaluacion::Induccion, $definicion),
        ];
    }

    /**
     * Título, descripción, diseño y textos de sección para dibujar el
     * formulario (sin respuestas): lo comparten la página real y la vista previa.
     *
     * @param  array<string, mixed>  $definicion
     * @return array<string, mixed>
     */
    public function presentacion(ClaveFormularioEvaluacion $clave, array $definicion): array
    {
        /** @var array<string, mixed> $diseno */
        $diseno = $definicion['diseno'];

        return [
            'id' => $definicion['formulario_id'],
            'clave' => $clave->value,
            'version' => $definicion['version'],
            'codigo' => $definicion['codigo'],
            'titulo' => $definicion['titulo'],
            'descripcion' => $definicion['descripcion'],
            'diseno' => [
                'acento' => $diseno['acento'],
                'estilo_encabezado' => $diseno['estilo_encabezado'],
                'banner_url' => $diseno['banner_url'],
            ],
            'secciones' => $definicion['secciones'] ?? null,
            // Puntos por sección (no revela respuestas): encabezado de cada sección.
            'puntos_secciones' => $clave === ClaveFormularioEvaluacion::Induccion ? [
                'a' => (int) collect((array) $definicion['seccion_a'])->sum('puntos'),
                'b' => (int) collect((array) $definicion['seccion_b'])->sum('puntos'),
                'c' => (int) collect((array) $definicion['seccion_c']['conceptos'])->sum('puntos'),
                'd' => (int) collect((array) $definicion['seccion_d'])->sum('puntos_max'),
            ] : null,
        ];
    }

    /**
     * Para el formulario de captura del jefe (web): criterios, filtros,
     * escala y reglas — sin nada privado (el periodo de prueba no tiene clave
     * de respuestas).
     *
     * @param  array<string, mixed>  $definicion
     * @return array<string, mixed>
     */
    public function contenidoPeriodoPrueba(array $definicion): array
    {
        return [
            ...$this->presentacion(ClaveFormularioEvaluacion::PeriodoPrueba, $definicion),
            'criterios' => $definicion['criterios'],
            'filtros' => $definicion['filtros'],
            'escala_etiquetas' => $definicion['escala_etiquetas'],
            'total_maximo' => $definicion['total_maximo'],
            'total_minimo_renovar' => $definicion['total_minimo_renovar'],
            'un_uno_no_renueva' => $definicion['un_uno_no_renueva'],
            'comentario_obligatorio_hasta' => $definicion['comentario_obligatorio_hasta'],
            'comentario_si_filtro' => $definicion['comentario_si_filtro'],
        ];
    }

    // ---------------------------------------------------------------------
    // Administración (RH)
    // ---------------------------------------------------------------------

    /**
     * Crea la versión 1 publicada desde config/ si la clave no tiene ninguna
     * fila. Idempotente: el seeder, el bootstrap de producción y la primera
     * visita al módulo la llaman.
     */
    public function asegurarVersionInicial(ClaveFormularioEvaluacion $clave): FormularioEvaluacion
    {
        return DB::transaction(function () use ($clave): FormularioEvaluacion {
            $existente = FormularioEvaluacion::query()->where('clave', $clave->value)->orderBy('version')->lockForUpdate()->first();

            if ($existente !== null) {
                return $existente;
            }

            $base = $this->metadatosPorDefecto($clave);

            return FormularioEvaluacion::query()->create([
                'clave' => $clave,
                'version' => 1,
                'codigo' => $base['codigo'],
                'titulo' => $base['titulo'],
                'descripcion' => $base['descripcion'],
                'diseno' => $this->guardableDiseno($this->normalizarDiseno($clave, [])),
                'definicion' => $this->guardable($clave, $this->normalizar($clave, $this->definicionDesdeConfig($clave))),
                'estado' => EstadoFormularioEvaluacion::Publicado,
                'publicado_en' => now(),
            ]);
        });
    }

    /**
     * Borrador editable de la clave: el existente, o uno nuevo copiado de
     * la versión publicada (o de $base, p. ej. restaurar una anterior).
     */
    public function crearBorrador(ClaveFormularioEvaluacion $clave, User $actor, ?FormularioEvaluacion $base = null): FormularioEvaluacion
    {
        $this->exigirPermiso($actor);

        if ($base !== null && $base->clave !== $clave) {
            throw ValidationException::withMessages(['formulario' => 'Esa versión pertenece a otro formulario.']);
        }

        $borrador = DB::transaction(function () use ($clave, $actor, $base): FormularioEvaluacion {
            $existente = FormularioEvaluacion::query()
                ->where('clave', $clave->value)
                ->where('estado', EstadoFormularioEvaluacion::Borrador->value)
                ->lockForUpdate()
                ->first();

            if ($existente !== null) {
                if ($base === null) {
                    return $existente;
                }

                throw ValidationException::withMessages(['formulario' => 'Ya hay un borrador en edición. Publícalo o descártalo antes de restaurar otra versión.']);
            }

            $origen = $base ?? $this->publicado($clave) ?? $this->asegurarVersionInicial($clave);
            $siguiente = (int) FormularioEvaluacion::query()->where('clave', $clave->value)->max('version') + 1;

            return FormularioEvaluacion::query()->create([
                'clave' => $clave,
                'version' => $siguiente,
                'codigo' => $origen->codigo,
                'titulo' => $origen->titulo,
                'descripcion' => $origen->descripcion,
                'diseno' => $origen->diseno,
                'definicion' => $origen->definicion,
                'estado' => EstadoFormularioEvaluacion::Borrador,
                'creado_por' => $actor->id,
                'actualizado_por' => $actor->id,
            ]);
        });

        $this->auditoria->registrar('formulario_evaluacion_borrador_creado', $borrador, $actor, [
            'clave' => $clave->value,
            'version' => $borrador->version,
            'basado_en_version' => $base?->version,
        ]);

        return $borrador;
    }

    /**
     * Guarda el borrador (autoguardado del editor). No exige que esté
     * completo — eso se valida al publicar (problemas()) — pero sí limpia
     * la estructura: nunca guarda tipos o llaves inventadas.
     *
     * @param  array<string, mixed>  $datos  titulo, descripcion?, codigo?, diseno?, definicion
     */
    public function guardarBorrador(FormularioEvaluacion $formulario, User $actor, array $datos): FormularioEvaluacion
    {
        $this->exigirPermiso($actor);
        $this->exigirBorrador($formulario);

        $clave = $formulario->clave;
        $disenoActual = (array) ($formulario->diseno ?? []);
        $disenoEntrada = (array) ($datos['diseno'] ?? []);
        // La imagen solo se cambia con subirBanner()/quitarBanner().
        $disenoEntrada['banner_path'] = $disenoActual['banner_path'] ?? null;

        $formulario->update([
            'titulo' => Str::limit(trim((string) ($datos['titulo'] ?? '')), 200, ''),
            'descripcion' => isset($datos['descripcion']) && trim((string) $datos['descripcion']) !== '' ? trim((string) $datos['descripcion']) : null,
            'codigo' => isset($datos['codigo']) && trim((string) $datos['codigo']) !== '' ? Str::limit(trim((string) $datos['codigo']), 40, '') : $formulario->codigo,
            'diseno' => $this->guardableDiseno($this->normalizarDiseno($clave, $disenoEntrada)),
            'definicion' => $this->guardable($clave, $this->normalizar($clave, (array) ($datos['definicion'] ?? []))),
            'actualizado_por' => $actor->id,
        ]);

        return $formulario->refresh();
    }

    /**
     * Publica el borrador: lo valida completo, archiva la versión vigente y
     * deja esta como la que usan las evaluaciones NUEVAS. Las evaluaciones ya
     * creadas conservan su versión.
     */
    public function publicar(FormularioEvaluacion $formulario, User $actor): FormularioEvaluacion
    {
        $this->exigirPermiso($actor);

        $publicado = DB::transaction(function () use ($formulario, $actor): FormularioEvaluacion {
            $formulario = FormularioEvaluacion::query()->lockForUpdate()->findOrFail($formulario->id);
            $this->exigirBorrador($formulario);

            $problemas = $this->problemas($formulario);

            if ($problemas !== []) {
                throw ValidationException::withMessages(['formulario' => $problemas]);
            }

            FormularioEvaluacion::query()
                ->where('clave', $formulario->clave->value)
                ->where('estado', EstadoFormularioEvaluacion::Publicado->value)
                ->update(['estado' => EstadoFormularioEvaluacion::Archivado->value]);

            $formulario->update([
                'estado' => EstadoFormularioEvaluacion::Publicado,
                'publicado_en' => now(),
                'publicado_por' => $actor->id,
                'actualizado_por' => $actor->id,
            ]);

            return $formulario;
        });

        $this->auditoria->registrar('formulario_evaluacion_publicado', $publicado, $actor, [
            'clave' => $publicado->clave->value,
            'version' => $publicado->version,
        ]);

        return $publicado->refresh();
    }

    /** Descarta el borrador (nunca toca versiones publicadas: nadie las contestó con un borrador). */
    public function descartarBorrador(FormularioEvaluacion $formulario, User $actor): void
    {
        $this->exigirPermiso($actor);
        $this->exigirBorrador($formulario);

        $this->auditoria->registrar('formulario_evaluacion_borrador_descartado', $formulario, $actor, [
            'clave' => $formulario->clave->value,
            'version' => $formulario->version,
        ]);

        $formulario->delete();
    }

    public function subirBanner(FormularioEvaluacion $formulario, User $actor, UploadedFile $archivo): FormularioEvaluacion
    {
        $this->exigirPermiso($actor);
        $this->exigirBorrador($formulario);

        $ruta = $archivo->storeAs(
            sprintf('formularios-evaluacion/%s', $formulario->clave->value),
            sprintf('banner-%s.%s', Str::uuid()->toString(), $archivo->guessExtension() ?? 'jpg'),
            'public',
        );

        if ($ruta === false) {
            throw ValidationException::withMessages(['banner' => 'No se pudo guardar la imagen. Intenta de nuevo.']);
        }

        $diseno = (array) ($formulario->diseno ?? []);
        // El archivo anterior NO se borra: una versión publicada puede usarlo.
        $diseno['banner_path'] = $ruta;
        $formulario->update(['diseno' => $diseno, 'actualizado_por' => $actor->id]);

        return $formulario->refresh();
    }

    public function quitarBanner(FormularioEvaluacion $formulario, User $actor): FormularioEvaluacion
    {
        $this->exigirPermiso($actor);
        $this->exigirBorrador($formulario);

        $diseno = (array) ($formulario->diseno ?? []);
        $diseno['banner_path'] = null;
        $formulario->update(['diseno' => $diseno, 'actualizado_por' => $actor->id]);

        return $formulario->refresh();
    }

    /**
     * Lo que impide publicar, en lenguaje de RH. [] = listo para publicar.
     *
     * @return list<string>
     */
    public function problemas(FormularioEvaluacion $formulario): array
    {
        $problemas = trim($formulario->titulo) === '' ? ['Escribe el título del formulario.'] : [];
        $definicion = $this->normalizar($formulario->clave, $formulario->definicion);

        return [...$problemas, ...($formulario->clave === ClaveFormularioEvaluacion::Induccion
            ? $this->problemasInduccion($definicion)
            : $this->problemasPeriodoPrueba($definicion))];
    }

    /**
     * Resumen de cada clave para la pantalla principal del módulo.
     *
     * @return list<array<string, mixed>>
     */
    public function resumen(): array
    {
        return array_map(function (ClaveFormularioEvaluacion $clave): array {
            $publicado = $this->publicado($clave) ?? $this->asegurarVersionInicial($clave);
            $borrador = $this->borrador($clave);
            $definicion = $this->definicionDe($clave, $publicado);

            return [
                'clave' => $clave->value,
                'etiqueta' => $clave->etiqueta(),
                'descripcion_modulo' => $clave->descripcion(),
                'publicado' => $this->aArray($publicado, false),
                'borrador' => $borrador !== null ? $this->aArray($borrador, false) : null,
                'total_versiones' => FormularioEvaluacion::query()->where('clave', $clave->value)->count(),
                'resumen' => $this->metricas($clave, $definicion),
            ];
        }, ClaveFormularioEvaluacion::cases());
    }

    /**
     * Historial de versiones (más reciente primero) con cuántas evaluaciones
     * se contestaron con cada una.
     *
     * @return list<array<string, mixed>>
     */
    public function historial(ClaveFormularioEvaluacion $clave): array
    {
        $tabla = $clave === ClaveFormularioEvaluacion::Induccion ? 'evaluaciones_induccion' : 'evaluaciones_periodo_prueba';
        /** @var Collection<int|string, int> $usos */
        $usos = DB::table($tabla)
            ->whereNotNull('formulario_evaluacion_id')
            ->groupBy('formulario_evaluacion_id')
            ->selectRaw('formulario_evaluacion_id, count(*) as total')
            ->pluck('total', 'formulario_evaluacion_id');

        return array_values(FormularioEvaluacion::query()
            ->where('clave', $clave->value)
            ->with(['publicadoPor:id,name', 'actualizadoPor:id,name'])
            ->orderByDesc('version')
            ->get()
            ->map(fn (FormularioEvaluacion $f): array => [
                ...$this->aArray($f, false),
                'evaluaciones' => (int) ($usos[$f->id] ?? 0),
            ])
            ->all());
    }

    /**
     * DTO para RH (editor e historial). Con $conDefinicion trae preguntas,
     * respuestas correctas y rúbricas — SOLO para quien administra.
     *
     * @return array<string, mixed>
     */
    public function aArray(FormularioEvaluacion $formulario, bool $conDefinicion = true): array
    {
        $clave = $formulario->clave;
        $diseno = $this->normalizarDiseno($clave, (array) ($formulario->diseno ?? []));

        return [
            'id' => $formulario->id,
            'clave' => $clave->value,
            'version' => $formulario->version,
            'etiqueta_version' => $formulario->etiquetaVersion(),
            'codigo' => $formulario->codigo,
            'titulo' => $formulario->titulo,
            'descripcion' => $formulario->descripcion,
            'estado' => $formulario->estado->value,
            'estado_etiqueta' => $formulario->estado->etiqueta(),
            'diseno' => $diseno,
            'publicado_en' => $formulario->publicado_en?->toIso8601String(),
            'publicado_por' => $formulario->publicadoPor?->name,
            'actualizado_en' => $formulario->updated_at?->toIso8601String(),
            'actualizado_por' => $formulario->actualizadoPor?->name,
            'definicion' => $conDefinicion ? $this->normalizar($clave, $formulario->definicion) : null,
            'problemas' => $conDefinicion && $formulario->esBorrador() ? $this->problemas($formulario) : [],
        ];
    }

    /**
     * Catálogos para el panel «Diseño» del editor.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public function opcionesDiseno(): array
    {
        return [
            'acentos' => array_map(fn (ColorAcentoFormulario $c): array => ['value' => $c->value, 'label' => $c->etiqueta()], ColorAcentoFormulario::cases()),
            'estilos' => array_map(fn (EstiloEncabezadoFormulario $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EstiloEncabezadoFormulario::cases()),
        ];
    }

    // ---------------------------------------------------------------------
    // Normalización
    // ---------------------------------------------------------------------

    /**
     * @param  array<mixed>  $definicion
     * @return array<string, mixed>
     */
    public function normalizar(ClaveFormularioEvaluacion $clave, array $definicion): array
    {
        return $clave === ClaveFormularioEvaluacion::Induccion
            ? $this->normalizarInduccion($definicion)
            : $this->normalizarPeriodoPrueba($definicion);
    }

    /**
     * @param  array<mixed>  $d
     * @return array<string, mixed>
     */
    private function normalizarInduccion(array $d): array
    {
        $seccionA = [];

        foreach (array_slice($this->lista($d['seccion_a'] ?? []), 0, self::MAX_PREGUNTAS) as $p) {
            $opciones = array_slice($this->textos($p['opciones'] ?? [], 500), 0, 8);
            $correcta = is_numeric($p['correcta'] ?? null) ? (int) $p['correcta'] : 0;
            $seccionA[] = [
                'pregunta' => $this->texto($p['pregunta'] ?? '', 1000),
                'opciones' => $opciones,
                'correcta' => $correcta >= 0 && $correcta < count($opciones) ? $correcta : 0,
                'puntos' => $this->puntos($p['puntos'] ?? 1),
            ];
        }

        $seccionB = [];

        foreach (array_slice($this->lista($d['seccion_b'] ?? []), 0, self::MAX_PREGUNTAS) as $p) {
            $seccionB[] = [
                'afirmacion' => $this->texto($p['afirmacion'] ?? '', 1000),
                'correcto' => filter_var($p['correcto'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'puntos' => $this->puntos($p['puntos'] ?? 1),
            ];
        }

        // Sección C: las descripciones se re-letran A, B, C… en el orden dado
        // y la letra correcta de cada concepto sigue a su descripción.
        $c = is_array($d['seccion_c'] ?? null) ? $d['seccion_c'] : [];
        $descripcionesEntrada = is_array($c['descripciones'] ?? null) ? $c['descripciones'] : [];
        $descripciones = [];
        $mapaLetras = [];
        $indice = 0;

        foreach ($descripcionesEntrada as $letraOriginal => $texto) {
            if ($indice >= self::MAX_LETRAS) {
                break;
            }

            $letra = chr(ord('A') + $indice);
            $descripciones[$letra] = $this->texto($texto, 1000);
            $mapaLetras[strtoupper((string) $letraOriginal)] = $letra;
            $indice++;
        }

        $conceptos = [];

        foreach (array_slice($this->lista($c['conceptos'] ?? []), 0, self::MAX_PREGUNTAS) as $p) {
            $letraOriginal = strtoupper($this->texto($p['letra_correcta'] ?? '', 5));
            $conceptos[] = [
                'concepto' => $this->texto($p['concepto'] ?? '', 500),
                'letra_correcta' => $mapaLetras[$letraOriginal] ?? '',
                'puntos' => $this->puntos($p['puntos'] ?? 1),
            ];
        }

        $seccionD = [];
        $clavesD = [];

        foreach (array_slice($this->lista($d['seccion_d'] ?? []), 0, 20) as $i => $caso) {
            $claveCaso = $this->claveUnica($caso['clave'] ?? '', sprintf('caso_%d', $i + 1), $clavesD);
            $clavesRubrica = [];
            $rubrica = [];

            foreach (array_slice($this->lista($caso['rubrica'] ?? []), 0, 20) as $j => $r) {
                $rubrica[] = [
                    'clave' => $this->claveUnica($r['clave'] ?? '', sprintf('criterio_%d', $j + 1), $clavesRubrica),
                    'puntos' => $this->puntos($r['puntos'] ?? 1),
                    'criterio' => $this->texto($r['criterio'] ?? '', 1000),
                ];
            }

            $seccionD[] = [
                'clave' => $claveCaso,
                'puntos_max' => (int) array_sum(array_column($rubrica, 'puntos')),
                'enunciado' => $this->texto($caso['enunciado'] ?? '', 2000),
                'rubrica' => $rubrica,
            ];
        }

        $e = is_array($d['seccion_e'] ?? null) ? $d['seccion_e'] : [];
        $clavesE = ['tema_reforzar'];
        $aspectos = [];

        foreach (array_slice($this->lista($e['aspectos'] ?? []), 0, 15) as $i => $aspecto) {
            $aspectos[] = [
                'clave' => $this->claveUnica($aspecto['clave'] ?? '', sprintf('aspecto_%d', $i + 1), $clavesE),
                'texto' => $this->texto($aspecto['texto'] ?? '', 500),
            ];
        }

        $etiquetasE = is_array($e['escala_etiquetas'] ?? null) ? $e['escala_etiquetas'] : [];
        $abierta = is_array($e['pregunta_abierta'] ?? null) ? $e['pregunta_abierta'] : [];

        $total = (int) (array_sum(array_column($seccionA, 'puntos'))
            + array_sum(array_column($seccionB, 'puntos'))
            + array_sum(array_column($conceptos, 'puntos'))
            + array_sum(array_column($seccionD, 'puntos_max')));

        $aprobatoria = is_numeric($d['calificacion_aprobatoria'] ?? null) ? (float) $d['calificacion_aprobatoria'] : 24.0;
        $sobresaliente = is_numeric($d['calificacion_sobresaliente'] ?? null) ? (float) $d['calificacion_sobresaliente'] : max($aprobatoria, 27.0);
        $dias = is_numeric($d['dias_habiles_reevaluacion'] ?? null) ? (int) $d['dias_habiles_reevaluacion'] : 5;

        return [
            'total_puntos' => $total,
            'calificacion_aprobatoria' => $this->numeroLimpio($aprobatoria),
            'calificacion_sobresaliente' => $this->numeroLimpio($sobresaliente),
            'dias_habiles_reevaluacion' => max(1, min(30, $dias)),
            'secciones' => $this->textosSecciones(is_array($d['secciones'] ?? null) ? $d['secciones'] : []),
            'seccion_a' => $seccionA,
            'seccion_b' => $seccionB,
            'seccion_c' => ['conceptos' => $conceptos, 'descripciones' => $descripciones],
            'seccion_d' => $seccionD,
            'seccion_e' => [
                'aspectos' => $aspectos,
                'escala_min' => self::ESCALA_OPINION_MIN,
                'escala_max' => self::ESCALA_OPINION_MAX,
                'escala_etiquetas' => [
                    self::ESCALA_OPINION_MIN => $this->texto($etiquetasE[self::ESCALA_OPINION_MIN] ?? 'Muy en desacuerdo', 60),
                    self::ESCALA_OPINION_MAX => $this->texto($etiquetasE[self::ESCALA_OPINION_MAX] ?? 'Totalmente de acuerdo', 60),
                ],
                // La clave es fija: la app y el PDF la leen como opinion.tema_reforzar.
                'pregunta_abierta' => ['clave' => 'tema_reforzar', 'texto' => $this->texto($abierta['texto'] ?? '¿Qué tema te gustaría reforzar?', 300)],
            ],
        ];
    }

    /**
     * @param  array<mixed>  $d
     * @return array<string, mixed>
     */
    private function normalizarPeriodoPrueba(array $d): array
    {
        $claves = [];
        $criterios = [];

        foreach (array_slice($this->lista($d['criterios'] ?? []), 0, 20) as $i => $c) {
            $criterios[] = [
                'clave' => $this->claveUnica($c['clave'] ?? '', sprintf('criterio_%d', $i + 1), $claves),
                'etiqueta' => $this->texto($c['etiqueta'] ?? '', 200),
                'descripcion' => $this->texto($c['descripcion'] ?? '', 500),
            ];
        }

        $clavesFiltros = [];
        $filtros = [];

        foreach (array_slice($this->lista($d['filtros'] ?? []), 0, 10) as $i => $f) {
            $filtros[] = [
                'clave' => $this->claveUnica($f['clave'] ?? '', sprintf('filtro_%d', $i + 1), $clavesFiltros),
                'etiqueta' => $this->texto($f['etiqueta'] ?? '', 200),
                'descripcion' => $this->texto($f['descripcion'] ?? '', 500),
            ];
        }

        $etiquetas = is_array($d['escala_etiquetas'] ?? null) ? $d['escala_etiquetas'] : [];
        $porDefecto = [4 => 'Muy bueno', 3 => 'Bueno', 2 => 'Regular', 1 => 'Deficiente'];
        $escala = [];

        foreach ($porDefecto as $valor => $texto) {
            $escala[$valor] = $this->texto($etiquetas[$valor] ?? $texto, 40);
        }

        $maximo = count($criterios) * self::ESCALA_PRUEBA_MAX;
        $minimo = is_numeric($d['total_minimo_renovar'] ?? null) ? (int) $d['total_minimo_renovar'] : 25;
        $comentarioHasta = is_numeric($d['comentario_obligatorio_hasta'] ?? null) ? (int) $d['comentario_obligatorio_hasta'] : 2;

        return [
            'escala_min' => self::ESCALA_PRUEBA_MIN,
            'escala_max' => self::ESCALA_PRUEBA_MAX,
            'escala_etiquetas' => $escala,
            'criterios' => $criterios,
            'filtros' => $filtros,
            'total_maximo' => $maximo,
            'total_minimo_renovar' => $minimo,
            'un_uno_no_renueva' => filter_var($d['un_uno_no_renueva'] ?? true, FILTER_VALIDATE_BOOLEAN),
            // Comentario obligatorio si algún criterio queda en este valor o menos (0 = nunca por calificación).
            'comentario_obligatorio_hasta' => max(0, min(self::ESCALA_PRUEBA_MAX - 1, $comentarioHasta)),
            'comentario_si_filtro' => filter_var($d['comentario_si_filtro'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Quita lo calculado (totales) antes de guardar en JSON.
     *
     * @param  array<string, mixed>  $normalizada
     * @return array<string, mixed>
     */
    private function guardable(ClaveFormularioEvaluacion $clave, array $normalizada): array
    {
        unset($normalizada['total_puntos'], $normalizada['total_maximo']);

        return $normalizada;
    }

    /**
     * @param  array<mixed>  $diseno
     * @return array{acento: string, estilo_encabezado: string, banner_path: string|null, banner_url: string|null}
     */
    private function normalizarDiseno(ClaveFormularioEvaluacion $clave, array $diseno): array
    {
        $acento = ColorAcentoFormulario::tryFrom((string) ($diseno['acento'] ?? ''))
            ?? ($clave === ClaveFormularioEvaluacion::Induccion ? ColorAcentoFormulario::Petroleo : ColorAcentoFormulario::Esmeralda);
        $estilo = EstiloEncabezadoFormulario::tryFrom((string) ($diseno['estilo_encabezado'] ?? '')) ?? EstiloEncabezadoFormulario::Franja;
        $banner = isset($diseno['banner_path']) && is_string($diseno['banner_path']) && $diseno['banner_path'] !== '' ? $diseno['banner_path'] : null;

        return [
            'acento' => $acento->value,
            'estilo_encabezado' => $estilo->value,
            'banner_path' => $banner,
            'banner_url' => $banner !== null ? Storage::disk('public')->url($banner) : null,
        ];
    }

    /**
     * @param  array{acento: string, estilo_encabezado: string, banner_path: string|null, banner_url: string|null}  $diseno
     * @return array{acento: string, estilo_encabezado: string, banner_path: string|null}
     */
    private function guardableDiseno(array $diseno): array
    {
        return ['acento' => $diseno['acento'], 'estilo_encabezado' => $diseno['estilo_encabezado'], 'banner_path' => $diseno['banner_path']];
    }

    /**
     * @param  array<mixed>  $textos
     * @return array<string, array{titulo: string, descripcion: string}>
     */
    private function textosSecciones(array $textos): array
    {
        $porDefecto = [
            'a' => ['titulo' => 'Opción múltiple', 'descripcion' => 'Elige la respuesta correcta.'],
            'b' => ['titulo' => 'Verdadero o falso', 'descripcion' => 'Marca si cada afirmación es verdadera o falsa.'],
            'c' => ['titulo' => 'Relaciona columnas', 'descripcion' => 'Elige la descripción que corresponde a cada concepto.'],
            'd' => ['titulo' => 'Casos prácticos', 'descripcion' => 'Responde con tus propias palabras. Los califica Recursos Humanos.'],
            'e' => ['titulo' => 'Tu opinión sobre la inducción', 'descripcion' => 'No califica: nos ayuda a mejorar.'],
        ];
        $salida = [];

        foreach ($porDefecto as $seccion => $base) {
            $entrada = is_array($textos[$seccion] ?? null) ? $textos[$seccion] : [];
            $titulo = $this->texto($entrada['titulo'] ?? $base['titulo'], 120);
            $salida[$seccion] = [
                'titulo' => $titulo !== '' ? $titulo : $base['titulo'],
                'descripcion' => $this->texto($entrada['descripcion'] ?? $base['descripcion'], 500),
            ];
        }

        return $salida;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return list<string>
     */
    private function problemasInduccion(array $d): array
    {
        $problemas = [];
        /** @var list<array{pregunta: string, opciones: list<string>, correcta: int}> $a */
        $a = $d['seccion_a'];
        /** @var list<array{afirmacion: string}> $b */
        $b = $d['seccion_b'];
        /** @var array{conceptos: list<array{concepto: string, letra_correcta: string}>, descripciones: array<string, string>} $c */
        $c = $d['seccion_c'];
        /** @var list<array{enunciado: string, rubrica: list<array{criterio: string}>}> $casos */
        $casos = $d['seccion_d'];
        /** @var array{aspectos: list<array{texto: string}>, pregunta_abierta: array{texto: string}} $e */
        $e = $d['seccion_e'];

        if ($a === []) {
            $problemas[] = 'La sección A necesita al menos una pregunta de opción múltiple.';
        }

        foreach ($a as $i => $p) {
            $n = $i + 1;

            if ($p['pregunta'] === '') {
                $problemas[] = sprintf('Sección A, pregunta %d: escribe la pregunta.', $n);
            }

            if (count($p['opciones']) < 2) {
                $problemas[] = sprintf('Sección A, pregunta %d: agrega al menos dos opciones.', $n);
            } elseif (in_array('', $p['opciones'], true)) {
                $problemas[] = sprintf('Sección A, pregunta %d: hay una opción vacía.', $n);
            }
        }

        if ($b === []) {
            $problemas[] = 'La sección B necesita al menos una afirmación de verdadero o falso.';
        }

        foreach ($b as $i => $p) {
            if ($p['afirmacion'] === '') {
                $problemas[] = sprintf('Sección B, afirmación %d: escribe la afirmación.', $i + 1);
            }
        }

        if ($c['conceptos'] === []) {
            $problemas[] = 'La sección C necesita al menos un concepto para relacionar.';
        }

        if (count($c['descripciones']) < 2) {
            $problemas[] = 'La sección C necesita al menos dos descripciones (A y B).';
        }

        foreach ($c['descripciones'] as $letra => $texto) {
            if ($texto === '') {
                $problemas[] = sprintf('Sección C: la descripción %s está vacía.', $letra);
            }
        }

        foreach ($c['conceptos'] as $i => $p) {
            if ($p['concepto'] === '') {
                $problemas[] = sprintf('Sección C, concepto %d: escribe el concepto.', $i + 1);
            }

            if ($p['letra_correcta'] === '') {
                $problemas[] = sprintf('Sección C, concepto %d: elige qué descripción le corresponde.', $i + 1);
            }
        }

        if ($casos === []) {
            $problemas[] = 'La sección D necesita al menos un caso práctico.';
        }

        foreach ($casos as $i => $caso) {
            if ($caso['enunciado'] === '') {
                $problemas[] = sprintf('Sección D, caso %d: escribe el caso.', $i + 1);
            }

            if ($caso['rubrica'] === []) {
                $problemas[] = sprintf('Sección D, caso %d: agrega al menos un criterio de la rúbrica para que RH pueda calificarlo.', $i + 1);
            }

            foreach ($caso['rubrica'] as $r) {
                if ($r['criterio'] === '') {
                    $problemas[] = sprintf('Sección D, caso %d: hay un criterio de la rúbrica sin texto.', $i + 1);

                    break;
                }
            }
        }

        if ($e['aspectos'] === []) {
            $problemas[] = 'La sección E necesita al menos un aspecto para opinar.';
        }

        foreach ($e['aspectos'] as $i => $aspecto) {
            if ($aspecto['texto'] === '') {
                $problemas[] = sprintf('Sección E, aspecto %d: escribe el texto.', $i + 1);
            }
        }

        if ($e['pregunta_abierta']['texto'] === '') {
            $problemas[] = 'Sección E: escribe la pregunta abierta.';
        }

        $total = (float) $d['total_puntos'];
        $aprobatoria = (float) $d['calificacion_aprobatoria'];
        $sobresaliente = (float) $d['calificacion_sobresaliente'];

        if ($aprobatoria <= 0 || $aprobatoria > $total) {
            $problemas[] = sprintf('La calificación mínima aprobatoria debe estar entre 1 y el total de puntos (%s).', $this->numeroLimpio($total));
        }

        if ($sobresaliente < $aprobatoria || $sobresaliente > $total) {
            $problemas[] = 'La calificación «sobresaliente» debe estar entre la mínima aprobatoria y el total de puntos.';
        }

        return $problemas;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return list<string>
     */
    private function problemasPeriodoPrueba(array $d): array
    {
        $problemas = [];
        /** @var list<array{etiqueta: string}> $criterios */
        $criterios = $d['criterios'];
        /** @var list<array{etiqueta: string}> $filtros */
        $filtros = $d['filtros'];

        if ($criterios === []) {
            $problemas[] = 'Agrega al menos un criterio para calificar.';
        }

        foreach ($criterios as $i => $c) {
            if ($c['etiqueta'] === '') {
                $problemas[] = sprintf('Criterio %d: escribe qué se evalúa.', $i + 1);
            }
        }

        foreach ($filtros as $i => $f) {
            if ($f['etiqueta'] === '') {
                $problemas[] = sprintf('Pregunta filtro %d: escribe la pregunta.', $i + 1);
            }
        }

        foreach ($d['escala_etiquetas'] as $valor => $texto) {
            if ($texto === '') {
                $problemas[] = sprintf('Escala: escribe el nombre del valor %d.', $valor);
            }
        }

        $maximo = (int) $d['total_maximo'];
        $minimo = (int) $d['total_minimo_renovar'];

        if ($criterios !== [] && ($minimo < count($criterios) || $minimo > $maximo)) {
            $problemas[] = sprintf('El total mínimo para renovar debe estar entre %d y %d (con %d criterios de 1 a 4).', count($criterios), $maximo, count($criterios));
        }

        return $problemas;
    }

    /**
     * @param  array<string, mixed>  $definicion
     * @return array<string, int|float>
     */
    private function metricas(ClaveFormularioEvaluacion $clave, array $definicion): array
    {
        if ($clave === ClaveFormularioEvaluacion::Induccion) {
            /** @var array{conceptos: list<mixed>} $c */
            $c = $definicion['seccion_c'];
            /** @var array{aspectos: list<mixed>} $e */
            $e = $definicion['seccion_e'];

            return [
                'preguntas' => count((array) $definicion['seccion_a']) + count((array) $definicion['seccion_b']) + count($c['conceptos']) + count((array) $definicion['seccion_d']) + count($e['aspectos']) + 1,
                'total_puntos' => (int) $definicion['total_puntos'],
                'aprobatoria' => (float) $definicion['calificacion_aprobatoria'],
            ];
        }

        return [
            'preguntas' => count((array) $definicion['criterios']) + count((array) $definicion['filtros']),
            'total_puntos' => (int) $definicion['total_maximo'],
            'aprobatoria' => (int) $definicion['total_minimo_renovar'],
        ];
    }

    // ---------------------------------------------------------------------
    // Fuente original (config/) y utilidades
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function definicionDesdeConfig(ClaveFormularioEvaluacion $clave): array
    {
        if ($clave === ClaveFormularioEvaluacion::Induccion) {
            $escala = collect((array) config('induccion_evaluacion.escala', []));
            $sobresaliente = $escala->firstWhere('nivel', 'sobresaliente');

            return [
                'calificacion_aprobatoria' => config('induccion_evaluacion.calificacion_aprobatoria', 24),
                'calificacion_sobresaliente' => is_array($sobresaliente) ? ($sobresaliente['min'] ?? 27) : 27,
                'dias_habiles_reevaluacion' => config('induccion_evaluacion.dias_habiles_reevaluacion', 5),
                'seccion_a' => config('induccion_evaluacion.seccion_a', []),
                'seccion_b' => config('induccion_evaluacion.seccion_b', []),
                'seccion_c' => config('induccion_evaluacion.seccion_c', []),
                'seccion_d' => config('induccion_evaluacion.seccion_d', []),
                'seccion_e' => config('induccion_evaluacion.seccion_e', []),
            ];
        }

        return [
            'criterios' => config('contratos.evaluacion_periodo_prueba_v2.criterios', []),
            'filtros' => config('contratos.evaluacion_periodo_prueba_v2.filtros', []),
            'total_minimo_renovar' => config('contratos.evaluacion_periodo_prueba_v2.total_minimo_renovar', 25),
            'un_uno_no_renueva' => true,
            'comentario_obligatorio_hasta' => 2,
            'comentario_si_filtro' => true,
        ];
    }

    /**
     * @return array{version: string, codigo: string, titulo: string, descripcion: string}
     */
    private function metadatosPorDefecto(ClaveFormularioEvaluacion $clave): array
    {
        if ($clave === ClaveFormularioEvaluacion::Induccion) {
            return [
                'version' => (string) config('induccion_evaluacion.version', 'FO-RH-EI-2026-v1'),
                'codigo' => $clave->codigoPorDefecto(),
                'titulo' => 'Evaluación del Programa de Inducción',
                'descripcion' => 'Responde con calma y con tus propias palabras. Las secciones A, B y C se califican al instante; los casos prácticos los revisa Recursos Humanos.',
            ];
        }

        return [
            'version' => sprintf('%s-v%d', $clave->codigoPorDefecto(), (int) config('contratos.evaluacion_periodo_prueba_v2.version_vigente', 2)),
            'codigo' => $clave->codigoPorDefecto(),
            'titulo' => 'Evaluación de Término de Periodo de Prueba',
            'descripcion' => 'Califica el desempeño del colaborador durante su capacitación inicial. Con esta evaluación se decide si se renueva su contrato.',
        ];
    }

    /**
     * @return list<array<mixed>>
     */
    private function lista(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $salida = [];

        foreach ($valor as $elemento) {
            if (is_array($elemento)) {
                $salida[] = $elemento;
            }
        }

        return $salida;
    }

    /**
     * @return list<string>
     */
    private function textos(mixed $valor, int $maximo): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $salida = [];

        foreach ($valor as $elemento) {
            $salida[] = $this->texto($elemento, $maximo);
        }

        return $salida;
    }

    private function texto(mixed $valor, int $maximo): string
    {
        return is_scalar($valor) ? Str::limit(trim((string) $valor), $maximo, '') : '';
    }

    private function puntos(mixed $valor): int
    {
        return is_numeric($valor) ? max(1, min(10, (int) $valor)) : 1;
    }

    private function numeroLimpio(float $valor): int|float
    {
        return floor($valor) === $valor ? (int) $valor : round($valor, 2);
    }

    /**
     * Clave estable (a-z, 0-9, _) y única dentro de su lista.
     *
     * @param  list<string>  $usadas
     */
    private function claveUnica(mixed $propuesta, string $porDefecto, array &$usadas): string
    {
        $clave = is_string($propuesta) ? strtolower(trim($propuesta)) : '';

        if (preg_match('/^[a-z0-9_]{1,60}$/', $clave) !== 1) {
            $clave = $porDefecto;
        }

        $base = $clave;
        $n = 2;

        while (in_array($clave, $usadas, true)) {
            $clave = sprintf('%s_%d', $base, $n++);
        }

        $usadas[] = $clave;

        return $clave;
    }

    private function exigirPermiso(User $actor): void
    {
        if (! $actor->can(self::PERMISO_ADMINISTRAR)) {
            throw new AuthorizationException('No tienes permiso para configurar los formularios de evaluación.');
        }
    }

    private function exigirBorrador(FormularioEvaluacion $formulario): void
    {
        if (! $formulario->esBorrador()) {
            throw ValidationException::withMessages(['formulario' => 'Una versión publicada ya no se puede editar: crea una nueva versión.']);
        }
    }
}
