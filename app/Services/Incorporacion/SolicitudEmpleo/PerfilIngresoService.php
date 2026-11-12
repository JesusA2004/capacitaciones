<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Enums\FuenteCandidato;
use App\Enums\FuenteVacante;
use App\Enums\TipoIdentificacion;
use App\Models\Candidato;
use App\Models\CandidatoSocioeconomico;
use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\ColaboradorDependiente;
use App\Models\ColaboradorEmpleoAnterior;
use App\Models\ColaboradorPerfilIngreso;
use App\Models\ColaboradorReferenciaPersonal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\ActualizacionDatosService;
use App\Support\DatoMarcador;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Captura, prellenado y COMPLETITUD de los datos que piden la Solicitud de
 * empleo y el Estudio socioeconómico (CatalogoSolicitudEmpleo). Fuente única
 * para la app (registro del candidato) y para la web de RH (completar lo
 * faltante): ninguna regla de obligatoriedad vive en un controlador.
 *
 *  - Secciones progresivas: se guarda y se continúa por sección; nada se
 *    exige al guardar, la completitud se calcula siempre desde los datos.
 *  - «No aplica» explícito: por campo (los que lo admiten) o por lista
 *    («No tengo dependientes»); nunca se inventa un valor.
 *  - La persona captura durante su registro (origen app); RH solo COMPLETA
 *    lo que sigue vacío (origen rh): nunca recaptura ni pisa lo que la
 *    persona ya dio — eso se corrige por solicitud de corrección.
 *  - Auditoría sin valores (solo qué campos y quién).
 *
 * @phpstan-import-type Campo from CatalogoSolicitudEmpleo
 * @phpstan-import-type Lista from CatalogoSolicitudEmpleo
 *
 * @phpstan-type Faltante array{seccion: string, campo: string, etiqueta: string, documentos: list<string>, origen: string}
 */
class PerfilIngresoService
{
    public const ORIGEN_APP = 'app';

    public const ORIGEN_RH = 'rh';

    /**
     * La propia persona DESPUÉS de su registro (web o app): solo llena lo que
     * sigue vacío — lo ya capturado se corrige por solicitud de corrección.
     * En la bitácora de la sección cuenta como captura de la persona (app).
     */
    public const ORIGEN_COMPLETAR = 'completar';

    /** Llave interna de `secciones` con los campos que capturó RH (la persona ya no los cambia). */
    private const CAMPOS_RH = '_campos_rh';

    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ActualizacionDatosService $actualizacion,
    ) {}

    /**
     * RH ve y completa la solicitud de una persona de su alcance con permiso
     * de generar documentos laborales (datos sensibles: salud, ingresos).
     */
    public function puedeGestionar(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('documentos_laborales.generar') && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }

    // ───────────────────────── Lectura ─────────────────────────

    public function perfil(Colaborador $colaborador): ?ColaboradorPerfilIngreso
    {
        return ColaboradorPerfilIngreso::query()->where('colaborador_id', $colaborador->id)->first();
    }

    /**
     * Valor vigente de cada campo del catálogo (normalizado: bool, int,
     * string, list<string> o null).
     *
     * @return array<string, mixed>
     */
    public function valores(Colaborador $colaborador): array
    {
        $perfil = $this->perfil($colaborador);
        $medicos = ColaboradorDatosMedicos::query()->where('colaborador_id', $colaborador->id)->first();
        $economica = $perfil !== null ? ($perfil->situacion_economica ?? []) : [];
        $valores = [];

        foreach (CatalogoSolicitudEmpleo::campos() as $clave => $campo) {
            $crudo = match ($campo['almacen']) {
                'base', 'colaborador' => $colaborador->getAttribute($campo['columna']),
                'medicos' => $medicos?->getAttribute($campo['columna']),
                'perfil' => $perfil?->getAttribute($campo['columna']),
                'economica' => $economica[$clave] ?? null,
                default => null,
            };

            // «REGISTRADO», «S/D»… en el domicilio no es un dato: cuenta como faltante.
            if ($campo['almacen'] === 'base' && str_starts_with($clave, 'domicilio') && is_string($crudo) && DatoMarcador::es($crudo)) {
                $crudo = null;
            }

            $valores[$clave] = $this->normalizarLectura($crudo, $campo['tipo']);
        }

        return $valores;
    }

    /**
     * Filas de cada lista y su bandera «No tengo…».
     *
     * @return array<string, array{filas: list<array<string, mixed>>, vacia: bool}>
     */
    public function listas(Colaborador $colaborador): array
    {
        $perfil = $this->perfil($colaborador);

        $dependientes = ColaboradorDependiente::query()->where('colaborador_id', $colaborador->id)->orderBy('orden')->get()
            ->map(fn (ColaboradorDependiente $d): array => [
                'nombre' => $d->nombre,
                'apellido_paterno' => $d->apellido_paterno,
                'apellido_materno' => $d->apellido_materno,
                'parentesco' => $d->parentesco->value,
                'parentesco_otro' => $d->parentesco_otro,
                'fecha_nacimiento' => $d->fecha_nacimiento?->toDateString(),
                'edad' => $d->edad,
                'escolaridad_ocupacion' => $d->escolaridad_ocupacion,
            ])->values()->all();

        $empleos = ColaboradorEmpleoAnterior::query()->where('colaborador_id', $colaborador->id)->orderBy('orden')->get()
            ->map(fn (ColaboradorEmpleoAnterior $e): array => [
                'empresa' => $e->empresa,
                'puesto' => $e->puesto,
                'desde' => $e->desde->format('Y-m'),
                'hasta' => $e->hasta?->format('Y-m'),
                'funciones' => $e->funciones,
                'motivo_salida' => $e->motivo_salida,
                'telefono' => $e->telefono,
            ])->values()->all();

        $referencias = ColaboradorReferenciaPersonal::query()->where('colaborador_id', $colaborador->id)->orderBy('orden')->get()
            ->map(fn (ColaboradorReferenciaPersonal $r): array => [
                'nombre' => $r->nombre,
                'relacion' => $r->relacion,
                'telefono' => $r->telefono,
                'anios_conocerlo' => $r->anios_conocerlo,
            ])->values()->all();

        return [
            'dependientes' => ['filas' => array_values($dependientes), 'vacia' => (bool) $perfil?->sin_dependientes],
            'empleos' => ['filas' => array_values($empleos), 'vacia' => (bool) $perfil?->sin_experiencia_laboral],
            'referencias' => ['filas' => array_values($referencias), 'vacia' => false],
        ];
    }

    /**
     * @return list<string>
     */
    public function noAplica(Colaborador $colaborador): array
    {
        $perfil = $this->perfil($colaborador);

        return array_values(array_filter(array_map('strval', (array) ($perfil->no_aplica ?? []))));
    }

    /**
     * ¿El campo es obligatorio con los valores actuales? (condiciones:
     * cónyuge solo si está casado, años de moto solo con licencia…).
     *
     * @param  Campo  $campo
     * @param  array<string, mixed>  $valores
     */
    public function esRequerido(array $campo, array $valores): bool
    {
        if (! $campo['requerido']) {
            return false;
        }

        $si = $campo['requerido_si'];

        if ($si === null) {
            return true;
        }

        $referencia = $valores[$si[0]] ?? null;
        $esperado = $si[2] ?? null;

        return match ($si[1]) {
            'igual' => $referencia === $esperado,
            'en' => is_array($esperado) && in_array($referencia, $esperado, true),
            'distinto' => $referencia !== null && $referencia !== $esperado,
            'positivo' => is_numeric($referencia) && (float) $referencia > 0,
            'contiene' => is_array($referencia) && in_array($esperado, $referencia, true),
            default => true,
        };
    }

    /**
     * ¿Un campo condicional NO aplica por la respuesta de otro (sin cónyuge,
     * sin licencia…)? Solo entonces el documento imprime «No aplica».
     *
     * @param  Campo  $campo
     * @param  array<string, mixed>  $valores
     */
    public function noAplicaPorCondicion(array $campo, array $valores): bool
    {
        if ($campo['requerido_si'] === null) {
            return false;
        }

        $referencia = $valores[$campo['requerido_si'][0]] ?? null;

        return $referencia !== null && ! $this->esRequerido($campo, $valores);
    }

    /**
     * @param  Campo  $campo
     */
    public function vacio(array $campo, mixed $valor): bool
    {
        if ($valor === null || $valor === '') {
            return true;
        }

        return $campo['tipo'] === 'multiselect' && is_array($valor) && $campo['min'] !== null && count($valor) < $campo['min'];
    }

    // ───────────────────────── Completitud ─────────────────────────

    /**
     * Qué falta EXACTAMENTE, por sección y por documento.
     *
     * @return array{completo: bool, porcentaje: int, total: int, completos: int, faltantes: list<Faltante>, secciones: list<array{clave: string, titulo: string, completa: bool, porcentaje: int, faltantes: list<string>, guardada_en: string|null, origen: string|null}>, documentos: array<string, array{clave: string, nombre: string, completo: bool, faltantes: list<string>, mensaje: string}>}
     */
    public function completitud(Colaborador $colaborador): array
    {
        $valores = $this->valores($colaborador);
        $noAplica = $this->noAplica($colaborador);
        $listas = $this->listas($colaborador);
        $perfil = $this->perfil($colaborador);
        $faltantes = [];
        $porSeccion = [];

        foreach (array_keys(CatalogoSolicitudEmpleo::SECCIONES) as $seccion) {
            $porSeccion[$seccion] = ['total' => 0, 'completos' => 0, 'faltantes' => []];
        }

        foreach (CatalogoSolicitudEmpleo::campos() as $clave => $campo) {
            if (! $this->esRequerido($campo, $valores)) {
                continue;
            }

            $porSeccion[$campo['seccion']]['total']++;
            $completo = ! $this->vacio($campo, $valores[$clave]) || ($campo['no_aplica'] && in_array($clave, $noAplica, true));

            if ($completo) {
                $porSeccion[$campo['seccion']]['completos']++;

                continue;
            }

            $faltante = ['seccion' => $campo['seccion'], 'campo' => $clave, 'etiqueta' => $campo['etiqueta'], 'documentos' => $campo['documentos'], 'origen' => $campo['almacen'] === 'base' ? 'datos_personales' : 'solicitud'];
            $faltantes[] = $faltante;
            $porSeccion[$campo['seccion']]['faltantes'][] = $campo['etiqueta'];
        }

        foreach (CatalogoSolicitudEmpleo::listas() as $clave => $lista) {
            foreach ($this->faltantesDeLista($lista, $listas[$clave]['filas'], $listas[$clave]['vacia']) as $etiqueta) {
                $faltantes[] = ['seccion' => $lista['seccion'], 'campo' => $clave, 'etiqueta' => $etiqueta, 'documentos' => $lista['documentos'], 'origen' => 'solicitud'];
                $porSeccion[$lista['seccion']]['faltantes'][] = $etiqueta;
            }

            $porSeccion[$lista['seccion']]['total']++;

            if ($this->faltantesDeLista($lista, $listas[$clave]['filas'], $listas[$clave]['vacia']) === []) {
                $porSeccion[$lista['seccion']]['completos']++;
            }
        }

        $total = array_sum(array_column($porSeccion, 'total'));
        $completos = array_sum(array_column($porSeccion, 'completos'));
        $bitacora = (array) ($perfil->secciones ?? []);
        $secciones = [];

        foreach ($porSeccion as $seccion => $datos) {
            $registro = is_array($bitacora[$seccion] ?? null) ? $bitacora[$seccion] : [];
            $secciones[] = [
                'clave' => $seccion,
                'titulo' => CatalogoSolicitudEmpleo::SECCIONES[$seccion]['titulo'],
                'completa' => $datos['faltantes'] === [],
                'porcentaje' => $datos['total'] > 0 ? (int) floor($datos['completos'] * 100 / $datos['total']) : 100,
                'faltantes' => $datos['faltantes'],
                'guardada_en' => isset($registro['guardada_en']) ? (string) $registro['guardada_en'] : null,
                'origen' => isset($registro['origen']) ? (string) $registro['origen'] : null,
            ];
        }

        $documentos = [];

        foreach (CatalogoSolicitudEmpleo::DOCUMENTOS as $clave => $nombre) {
            $deDocumento = array_values(array_unique(array_map(fn (array $f): string => $f['etiqueta'], array_filter($faltantes, fn (array $f): bool => in_array($clave, $f['documentos'], true)))));
            $documentos[$clave] = [
                'clave' => $clave,
                'nombre' => $nombre,
                'completo' => $deDocumento === [],
                'faltantes' => $deDocumento,
                'mensaje' => self::mensajeFaltantes($nombre, $deDocumento, CatalogoSolicitudEmpleo::ARTICULOS[$clave]),
            ];
        }

        return [
            'completo' => $faltantes === [],
            'porcentaje' => $total > 0 ? (int) floor($completos * 100 / $total) : 100,
            'total' => $total,
            'completos' => $completos,
            'faltantes' => $faltantes,
            'secciones' => $secciones,
            'documentos' => $documentos,
        ];
    }

    /**
     * «Faltan 3 datos para generar la Solicitud de Empleo: Escolaridad, …».
     *
     * @param  list<string>  $faltantes
     */
    public static function mensajeFaltantes(string $documento, array $faltantes, string $articulo = 'la'): string
    {
        $n = count($faltantes);

        if ($n === 0) {
            return sprintf('%s %s tiene todos sus datos: ya se puede generar.', ucfirst($articulo), $documento);
        }

        return sprintf('Falta%s %d dato%s para generar %s %s: %s.', $n === 1 ? '' : 'n', $n, $n === 1 ? '' : 's', $articulo === 'el' ? 'el' : 'la', $documento, implode(', ', $faltantes));
    }

    /**
     * @param  Lista  $lista
     * @param  list<array<string, mixed>>  $filas
     * @return list<string>
     */
    public function faltantesDeLista(array $lista, array $filas, bool $vacia): array
    {
        if ($filas === []) {
            if ($lista['bandera_vacia'] !== null && $vacia) {
                return [];
            }

            return [$lista['minimo'] > 0
                ? sprintf('%s (captura al menos %d)', $lista['etiqueta'], $lista['minimo'])
                : sprintf('%s (agrega al menos uno o marca «%s»)', $lista['etiqueta'], (string) $lista['etiqueta_vacia'])];
        }

        $faltan = [];

        if (count($filas) < $lista['minimo']) {
            $faltan[] = sprintf('%s (captura al menos %d)', $lista['etiqueta'], $lista['minimo']);
        }

        foreach ($filas as $i => $fila) {
            foreach ($lista['campos'] as $clave => $campo) {
                $vacio = ! isset($fila[$clave]) || trim((string) (is_scalar($fila[$clave]) ? $fila[$clave] : '')) === '';
                $requerido = (bool) $campo['requerido']
                    || ($clave === 'parentesco_otro' && ($fila['parentesco'] ?? null) === 'otro')
                    || ($clave === 'edad' && trim((string) ($fila['fecha_nacimiento'] ?? '')) === '');

                if ($requerido && $vacio) {
                    $faltan[] = sprintf('%s %d: %s', $lista['singular'], $i + 1, mb_strtolower((string) $campo['etiqueta']));
                }
            }
        }

        return $faltan;
    }

    /**
     * 422 DATOS_FALTANTES con la lista exacta si el documento no está completo.
     *
     * @throws FaltanDatosIngresoException
     */
    public function exigirCompleto(Colaborador $colaborador, string $documento): void
    {
        $completitud = $this->completitud($colaborador)['documentos'][$documento] ?? null;

        if ($completitud !== null && ! $completitud['completo']) {
            throw new FaltanDatosIngresoException($completitud['nombre'], $completitud['faltantes'], $completitud['mensaje']);
        }
    }

    // ───────────────────────── Estado por sección (app y web) ─────────────────────────

    /**
     * Todas las secciones con sus campos, valores, sugerencias (prellenado) y
     * avance. `modo` app = la persona en su registro; rh = RH completando.
     *
     * @return array{secciones: list<array<string, mixed>>, completitud: array<string, mixed>}
     */
    public function estado(Colaborador $colaborador, string $modo, bool $editable = true): array
    {
        $valores = $this->valores($colaborador);
        $noAplica = $this->noAplica($colaborador);
        $listas = $this->listas($colaborador);
        $sugerencias = $this->sugerencias($colaborador, $valores);
        $camposRh = $this->camposRh($colaborador);
        $completitud = $this->completitud($colaborador);
        $avance = [];

        foreach ($completitud['secciones'] as $s) {
            $avance[$s['clave']] = $s;
        }

        $secciones = [];

        foreach (CatalogoSolicitudEmpleo::SECCIONES as $seccion => $meta) {
            $campos = [];

            foreach (CatalogoSolicitudEmpleo::camposDeSeccion($seccion) as $clave => $campo) {
                $valor = $valores[$clave];
                $bloqueado = ! $editable || $campo['almacen'] === 'base'
                    || ($this->soloCompletaVacios($modo) && (! $this->vacio($campo, $valor) || in_array($clave, $noAplica, true)))
                    || ($modo === self::ORIGEN_APP && in_array($clave, $camposRh, true));

                $campos[] = [
                    'campo' => $clave,
                    'etiqueta' => $campo['etiqueta'],
                    'tipo' => $campo['tipo'],
                    'opciones' => CatalogoSolicitudEmpleo::opcionesDe($campo['opciones']),
                    'valor' => $valor,
                    'valor_legible' => $campo['almacen'] === 'base' && is_string($valor) ? $this->actualizacion->legible($clave, $valor) : null,
                    'sugerencia' => $sugerencias[$clave] ?? null,
                    'requerido' => $this->esRequerido($campo, $valores),
                    'requerido_si' => $campo['requerido_si'] !== null ? ['campo' => $campo['requerido_si'][0], 'operador' => $campo['requerido_si'][1], 'valor' => $campo['requerido_si'][2] ?? null] : null,
                    'permite_no_aplica' => $campo['no_aplica'],
                    'no_aplica' => in_array($clave, $noAplica, true),
                    'bloqueado' => $bloqueado,
                    'origen' => $campo['almacen'] === 'base' ? 'datos_personales' : 'solicitud',
                    'documentos' => $campo['documentos'],
                    'min' => $campo['min'],
                    'max' => $campo['max'],
                    'ayuda' => $campo['ayuda'],
                ];
            }

            $lista = CatalogoSolicitudEmpleo::listaDeSeccion($seccion);
            $bloqueLista = null;

            if ($lista !== null) {
                $actual = $listas[$lista['clave']];
                $bloqueLista = [
                    'clave' => $lista['clave'],
                    'etiqueta' => $lista['etiqueta'],
                    'singular' => $lista['singular'],
                    'minimo' => $lista['minimo'],
                    'maximo' => $lista['maximo'],
                    'bandera_vacia' => $lista['bandera_vacia'],
                    'etiqueta_vacia' => $lista['etiqueta_vacia'],
                    'vacia' => $actual['vacia'],
                    'campos' => array_map(fn (string $clave, array $c): array => [
                        'campo' => $clave,
                        'etiqueta' => (string) $c['etiqueta'],
                        'tipo' => (string) $c['tipo'],
                        'requerido' => (bool) $c['requerido'],
                        'opciones' => CatalogoSolicitudEmpleo::opcionesDe($c['opciones'] ?? null),
                        'min' => isset($c['min']) ? (int) $c['min'] : null,
                        'max' => isset($c['max']) ? (int) $c['max'] : null,
                    ], array_keys($lista['campos']), array_values($lista['campos'])),
                    'filas' => $actual['filas'],
                    'bloqueada' => ! $editable || ($this->soloCompletaVacios($modo) && ($actual['filas'] !== [] || $actual['vacia'])),
                ];
            }

            $secciones[] = [
                'clave' => $seccion,
                'titulo' => $meta['titulo'],
                'descripcion' => $meta['descripcion'],
                'completa' => $avance[$seccion]['completa'],
                'porcentaje' => $avance[$seccion]['porcentaje'],
                'faltantes' => $avance[$seccion]['faltantes'],
                'guardada_en' => $avance[$seccion]['guardada_en'],
                'origen' => $avance[$seccion]['origen'],
                'campos' => $campos,
                'lista' => $bloqueLista,
            ];
        }

        return ['secciones' => $secciones, 'completitud' => $completitud];
    }

    /**
     * Prellenado con lo que PEOPLE ya sabe (nunca se guarda solo: la persona
     * lo confirma al guardar la sección).
     *
     * @param  array<string, mixed>  $valores
     * @return array<string, mixed>
     */
    public function sugerencias(Colaborador $colaborador, array $valores): array
    {
        $sugerencias = [];
        $candidato = $colaborador->candidato_id !== null ? Candidato::query()->where('id', $colaborador->candidato_id)->first() : null;

        if ($candidato !== null) {
            $visita = CandidatoSocioeconomico::query()->where('candidato_id', $candidato->id)->latest('fecha_visita')->first();
            $checklist = (array) ($visita->checklist ?? []);

            if ($valores['tiempo_residencia_anios'] === null && is_numeric($checklist['arraigo_anios'] ?? null)) {
                $sugerencias['tiempo_residencia_anios'] = (int) $checklist['arraigo_anios'];
                $sugerencias['tiempo_residencia_meses'] = is_numeric($checklist['arraigo_meses'] ?? null) ? (int) $checklist['arraigo_meses'] : null;
            }

            $fuente = FuenteCandidato::tryFrom((string) $candidato->fuente);
            $vacante = match ($fuente) {
                FuenteCandidato::Referido => FuenteVacante::Recomendacion,
                FuenteCandidato::Meta, FuenteCandidato::FacebookGrupos, FuenteCandidato::LinkedIn => FuenteVacante::RedesSociales,
                FuenteCandidato::Indeed, FuenteCandidato::Computrabajo => FuenteVacante::BolsaTrabajo,
                default => null,
            };

            if ($valores['fuente_vacante'] === null && $vacante !== null) {
                $sugerencias['fuente_vacante'] = $vacante->value;
            }

            if ($valores['aplico_antes'] === null && $this->aplicoAntes($candidato)) {
                $sugerencias['aplico_antes'] = true;
            }
        }

        if ($valores['identificacion_tipo'] === null && trim((string) $colaborador->clave_elector) !== '') {
            $sugerencias['identificacion_tipo'] = TipoIdentificacion::Ine->value;
        }

        return $sugerencias;
    }

    // ───────────────────────── Guardado ─────────────────────────

    /**
     * Guarda una sección (parcial: solo las claves que llegan). Nunca exige
     * completitud al guardar.
     *
     * @param  array<string, mixed>  $datos  campo => valor (+ la lista de la sección y su bandera «No tengo…»).
     * @param  list<string>|null  $noAplica  Campos de ESTA sección declarados «No aplica» (null = sin cambios).
     *
     * @throws ValidationException
     */
    public function guardarSeccion(Colaborador $colaborador, string $seccion, array $datos, ?array $noAplica, User $actor, string $origen): void
    {
        if (! CatalogoSolicitudEmpleo::existeSeccion($seccion)) {
            throw ValidationException::withMessages(['seccion' => 'La sección no existe.']);
        }

        $valores = $this->valores($colaborador);
        $vigentesNoAplica = $this->noAplica($colaborador);
        $camposRh = $this->camposRh($colaborador);
        $listas = $this->listas($colaborador);
        $cambios = [];
        $bloqueados = [];

        foreach (CatalogoSolicitudEmpleo::camposDeSeccion($seccion) as $clave => $campo) {
            if ($campo['almacen'] === 'base' || ! array_key_exists($clave, $datos)) {
                continue;
            }

            $nuevo = $this->normalizarEscritura($datos[$clave], $campo);

            if ($nuevo === $valores[$clave]) {
                continue;
            }

            // RH completa: solo lo vacío. La persona: nada de lo que capturó RH.
            if (($this->soloCompletaVacios($origen) && ! $this->vacio($campo, $valores[$clave])) || ($origen === self::ORIGEN_APP && in_array($clave, $camposRh, true))) {
                $bloqueados[] = $campo['etiqueta'];

                continue;
            }

            $cambios[$clave] = $nuevo;
        }

        $nuevoNoAplica = $vigentesNoAplica;

        if ($noAplica !== null) {
            $permitidos = array_keys(array_filter(CatalogoSolicitudEmpleo::camposDeSeccion($seccion), fn (array $c): bool => $c['no_aplica']));
            $ajenos = array_diff($noAplica, $permitidos);

            if ($ajenos !== []) {
                throw ValidationException::withMessages(['no_aplica' => sprintf('Estos datos no admiten «No aplica»: %s.', implode(', ', array_map(fn (string $c): string => CatalogoSolicitudEmpleo::existe($c) ? CatalogoSolicitudEmpleo::campo($c)['etiqueta'] : $c, $ajenos)))]);
            }

            $quitar = array_diff(array_intersect($vigentesNoAplica, $permitidos), $noAplica);
            $agregar = array_diff($noAplica, $vigentesNoAplica);

            if ($this->soloCompletaVacios($origen) && $quitar !== []) {
                $bloqueados = [...$bloqueados, ...array_map(fn (string $c): string => CatalogoSolicitudEmpleo::campo($c)['etiqueta'], $quitar)];
                $quitar = [];
            }

            $nuevoNoAplica = array_values(array_unique([...array_diff($vigentesNoAplica, $quitar), ...$agregar]));

            // Un dato marcado «No aplica» se queda sin valor (nunca ambos).
            foreach ($agregar as $campo) {
                $cambios[$campo] = null;
            }
        }

        $lista = CatalogoSolicitudEmpleo::listaDeSeccion($seccion);
        $filasNuevas = null;
        $banderaNueva = null;

        if ($lista !== null) {
            $actual = $listas[$lista['clave']];

            if (array_key_exists($lista['clave'], $datos) || ($lista['bandera_vacia'] !== null && array_key_exists($lista['bandera_vacia'], $datos))) {
                if ($this->soloCompletaVacios($origen) && ($actual['filas'] !== [] || $actual['vacia'])) {
                    $bloqueados[] = $lista['etiqueta'];
                } else {
                    $filasNuevas = array_key_exists($lista['clave'], $datos) ? $this->normalizarFilas($lista, $datos[$lista['clave']]) : $actual['filas'];
                    $banderaNueva = $lista['bandera_vacia'] !== null && array_key_exists($lista['bandera_vacia'], $datos) ? (bool) $datos[$lista['bandera_vacia']] : $actual['vacia'];

                    if ($banderaNueva && $filasNuevas !== []) {
                        throw ValidationException::withMessages([$lista['clave'] => sprintf('Marcaste «%s» pero capturaste %d renglón(es): elige una opción.', (string) $lista['etiqueta_vacia'], count($filasNuevas))]);
                    }

                    if (count($filasNuevas) > $lista['maximo']) {
                        throw ValidationException::withMessages([$lista['clave'] => sprintf('El formato solo tiene espacio para %d %s.', $lista['maximo'], mb_strtolower($lista['etiqueta']))]);
                    }
                }
            }
        }

        if ($bloqueados !== []) {
            throw ValidationException::withMessages(['datos' => match ($origen) {
                self::ORIGEN_RH => sprintf('La persona ya capturó: %s. RH solo completa lo que falta; si algo está mal, se corrige por solicitud de corrección.', implode(', ', $bloqueados)),
                self::ORIGEN_COMPLETAR => sprintf('Ya está registrado: %s. Si algo está mal, pide una corrección de datos desde «Mis solicitudes».', implode(', ', $bloqueados)),
                default => sprintf('Recursos Humanos ya registró: %s. Si algo está mal, solicita una corrección cuando entres a la app.', implode(', ', $bloqueados)),
            }]);
        }

        if ($cambios === [] && $filasNuevas === null && $nuevoNoAplica === $vigentesNoAplica) {
            $this->marcarSeccion($colaborador, $seccion, $actor, $origen, []);

            return;
        }

        $claveLista = $lista !== null && $filasNuevas !== null ? $lista['clave'] : null;

        DB::transaction(function () use ($colaborador, $seccion, $cambios, $nuevoNoAplica, $lista, $filasNuevas, $banderaNueva, $claveLista, $actor, $origen): void {
            $this->persistir($colaborador, $cambios, $nuevoNoAplica, $actor);

            if ($lista !== null && $filasNuevas !== null) {
                $this->reemplazarFilas($colaborador, $lista, $filasNuevas, (bool) $banderaNueva);
            }

            $campos = [...array_keys($cambios), ...($claveLista !== null ? [$claveLista] : [])];
            $this->marcarSeccion($colaborador, $seccion, $actor, $origen, $origen === self::ORIGEN_RH ? array_keys($cambios) : []);

            // Nunca los valores (salud, ingresos, folio): solo qué se capturó.
            $this->auditoria->registrar('solicitud_empleo_seccion_guardada', $colaborador, $actor, [
                'seccion' => $seccion,
                'campos' => $campos,
                'origen' => $origen,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @param  list<string>  $noAplica
     */
    private function persistir(Colaborador $colaborador, array $cambios, array $noAplica, User $actor): void
    {
        $colaboradorCambios = [];
        $medicos = [];
        $perfil = [];
        $economica = [];

        foreach ($cambios as $clave => $valor) {
            $campo = CatalogoSolicitudEmpleo::campo($clave);

            match ($campo['almacen']) {
                'colaborador' => $colaboradorCambios[$campo['columna']] = $valor,
                'medicos' => $medicos[$campo['columna']] = $valor,
                'perfil' => $perfil[$campo['columna']] = $valor,
                'economica' => $economica[$clave] = $valor,
                default => null,
            };
        }

        if ($colaboradorCambios !== []) {
            $colaborador->update($colaboradorCambios);
        }

        if ($medicos !== []) {
            $existente = ColaboradorDatosMedicos::query()->where('colaborador_id', $colaborador->id)->first();

            if ($existente === null) {
                ColaboradorDatosMedicos::query()->create([...$medicos, 'colaborador_id' => $colaborador->id, 'fuente' => 'registro_app', 'actualizado_por' => $actor->id]);
            } else {
                $existente->update([...$medicos, 'actualizado_por' => $actor->id]);
            }
        }

        $registro = ColaboradorPerfilIngreso::query()->firstOrNew(['colaborador_id' => $colaborador->id]);
        $registro->colaborador_id = $colaborador->id;
        $registro->fill($perfil);

        if ($economica !== []) {
            $registro->situacion_economica = [...($registro->situacion_economica ?? []), ...$economica];
        }

        $registro->no_aplica = $noAplica;
        $registro->save();
    }

    /**
     * @param  Lista  $lista
     * @param  list<array<string, mixed>>  $filas
     */
    private function reemplazarFilas(Colaborador $colaborador, array $lista, array $filas, bool $vacia): void
    {
        $modelo = match ($lista['clave']) {
            'dependientes' => ColaboradorDependiente::class,
            'empleos' => ColaboradorEmpleoAnterior::class,
            default => ColaboradorReferenciaPersonal::class,
        };

        $modelo::query()->where('colaborador_id', $colaborador->id)->delete();

        foreach ($filas as $i => $fila) {
            if ($lista['clave'] === 'empleos') {
                $fila['desde'] = $fila['desde'] !== null ? sprintf('%s-01', (string) $fila['desde']) : null;
                $fila['hasta'] = $fila['hasta'] !== null ? sprintf('%s-01', (string) $fila['hasta']) : null;
            }

            $modelo::query()->create([...$fila, 'colaborador_id' => $colaborador->id, 'orden' => $i + 1]);
        }

        if ($lista['bandera_vacia'] !== null) {
            $perfil = ColaboradorPerfilIngreso::query()->firstOrNew(['colaborador_id' => $colaborador->id]);
            $perfil->colaborador_id = $colaborador->id;
            $perfil->setAttribute($lista['bandera_vacia'], $vacia);
            $perfil->save();
        }
    }

    /**
     * RH y la persona después de su registro solo llenan lo vacío.
     */
    private function soloCompletaVacios(string $origen): bool
    {
        return $origen === self::ORIGEN_RH || $origen === self::ORIGEN_COMPLETAR;
    }

    /**
     * @param  list<string>  $camposRh
     */
    private function marcarSeccion(Colaborador $colaborador, string $seccion, User $actor, string $origen, array $camposRh): void
    {
        $origen = $origen === self::ORIGEN_COMPLETAR ? self::ORIGEN_APP : $origen;
        $perfil = ColaboradorPerfilIngreso::query()->firstOrNew(['colaborador_id' => $colaborador->id]);
        $perfil->colaborador_id = $colaborador->id;
        $secciones = (array) ($perfil->secciones ?? []);
        $secciones[$seccion] = ['guardada_en' => now()->toIso8601String(), 'origen' => $origen, 'por' => $actor->id];

        if ($camposRh !== []) {
            $previos = is_array($secciones[self::CAMPOS_RH] ?? null) ? array_map('strval', $secciones[self::CAMPOS_RH]) : [];
            $secciones[self::CAMPOS_RH] = array_values(array_unique([...$previos, ...$camposRh]));
        }

        $perfil->secciones = $secciones;
        $perfil->save();
    }

    /**
     * @return list<string>
     */
    private function camposRh(Colaborador $colaborador): array
    {
        $secciones = (array) ($this->perfil($colaborador)->secciones ?? []);

        return is_array($secciones[self::CAMPOS_RH] ?? null) ? array_values(array_map('strval', $secciones[self::CAMPOS_RH])) : [];
    }

    // ───────────────────────── Normalización ─────────────────────────

    private function normalizarLectura(mixed $valor, string $tipo): mixed
    {
        return match (true) {
            $valor === null => null,
            $valor instanceof BackedEnum => $valor->value,
            $valor instanceof DateTimeInterface => $valor->format('Y-m-d'),
            $tipo === 'booleano' => (bool) $valor,
            $tipo === 'entero' => is_numeric($valor) ? (int) $valor : null,
            $tipo === 'moneda' => is_numeric($valor) ? number_format((float) $valor, 2, '.', '') : null,
            $tipo === 'multiselect' => is_array($valor) ? array_values(array_map('strval', $valor)) : null,
            is_scalar($valor) => trim((string) $valor) === '' ? null : trim((string) $valor),
            default => null,
        };
    }

    /**
     * @param  Campo  $campo
     */
    private function normalizarEscritura(mixed $valor, array $campo): mixed
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }

        return match ($campo['tipo']) {
            'booleano' => filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'entero' => is_numeric($valor) ? (int) $valor : null,
            'moneda' => is_numeric($valor) ? number_format((float) $valor, 2, '.', '') : null,
            'multiselect' => is_array($valor) ? array_values(array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $valor))) : null,
            'fecha' => CarbonImmutable::parse(is_scalar($valor) ? (string) $valor : 'now')->toDateString(),
            default => is_scalar($valor) ? trim((string) $valor) : null,
        };
    }

    /**
     * @param  Lista  $lista
     * @return list<array<string, mixed>>
     */
    private function normalizarFilas(array $lista, mixed $filas): array
    {
        if (! is_array($filas)) {
            return [];
        }

        $resultado = [];

        foreach (array_values($filas) as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $limpia = [];

            foreach ($lista['campos'] as $clave => $campo) {
                $valor = $fila[$clave] ?? null;
                $texto = is_scalar($valor) ? trim((string) $valor) : '';
                $limpia[$clave] = match (true) {
                    $texto === '' => null,
                    $campo['tipo'] === 'entero' => (int) $texto,
                    default => $texto,
                };
            }

            // Renglón totalmente vacío: no se guarda.
            if (array_filter($limpia, fn (mixed $v): bool => $v !== null) !== []) {
                $resultado[] = $limpia;
            }
        }

        return $resultado;
    }

    private function aplicoAntes(Candidato $candidato): bool
    {
        $telefono = trim((string) $candidato->telefono);
        $correo = trim((string) $candidato->correo);

        if ($telefono === '' && $correo === '') {
            return false;
        }

        return Candidato::query()
            ->where('id', '!=', $candidato->id)
            ->where('id', '<', $candidato->id)
            ->where(function ($q) use ($telefono, $correo): void {
                if ($telefono !== '') {
                    $q->orWhere('telefono', $telefono);
                }

                if ($correo !== '') {
                    $q->orWhere('correo', $correo);
                }
            })
            ->exists();
    }
}
