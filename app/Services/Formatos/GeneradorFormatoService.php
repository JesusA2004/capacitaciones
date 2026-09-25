<?php

namespace App\Services\Formatos;

use App\Enums\EstadoFormatoOficialGeneracion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\FiniquitoCalculo;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\OfficialFormatVersion;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\Motor\RenderizadorFormato;
use App\Services\Formatos\Variables\CatalogoVariablesFormato;
use App\Services\Formatos\Variables\ContextoFormato;
use App\Services\Formatos\Variables\FormateadorValores;
use App\Services\Formatos\Variables\ResolvedorVariablesFormato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Genera documentos a partir de la versión VIGENTE de una plantilla
 * oficial. Flujo (docs/FORMATOS_OFICIALES.md → "Generar"):
 *
 *   1. contexto (persona + solicitud/préstamo/contrato si el formato los usa)
 *   2. resolver variables del catálogo (ResolvedorVariablesFormato)
 *   3. validar requeridos: si falta un dato requerido NO se genera
 *   4. vista previa real (mismo render; faltantes marcados en rojo)
 *   5. PDF final → expediente del colaborador (o almacenamiento de
 *      formatos si es candidato) → OfficialFormatGeneration con versión,
 *      snapshot, valores manuales y checksum → auditoría
 *
 * Lo mismo sirve para la generación manual (RH), desde el expediente y
 * desde una solicitud aprobada (SolicitudFormatoOficialService): nadie
 * más resuelve variables.
 *
 * @phpstan-type Preparacion array{
 *     version: OfficialFormatVersion,
 *     textos: array<string, string>,
 *     imagenes: array<string, string>,
 *     marcas: array<string, string>,
 *     faltantes: list<array{variable: string, etiqueta: string, completar_url: string|null}>,
 *     manuales: list<array{clave: string, etiqueta: string, requerido: bool, valor: string}>,
 *     contextos_faltantes: list<string>,
 *     bloqueo_sensible: bool,
 *     datos: list<array{etiqueta: string, valor: string}>,
 *     snapshot: array<string, string>,
 *     puede_generar: bool,
 * }
 */
class GeneradorFormatoService
{
    private const CONTEXTOS = [
        'solicitud' => 'la solicitud',
        'prestamo' => 'el préstamo',
        'contrato' => 'el contrato',
        'finiquito' => 'el finiquito',
    ];

    public function __construct(
        private readonly CatalogoVariablesFormato $catalogo,
        private readonly ResolvedorVariablesFormato $resolvedor,
        private readonly FormateadorValores $formateador,
        private readonly RenderizadorFormato $renderizador,
        private readonly OfficialFormatStorageService $storage,
        private readonly DocumentoStorageService $expediente,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Arma el contexto verificando que la solicitud/préstamo/contrato
     * pertenezcan a esa persona (nunca se mezcla información de otro).
     */
    public function contexto(Colaborador|Candidato $sujeto, ?int $solicitudId, ?int $prestamoId, ?int $contratoId, ?User $generador): ContextoFormato
    {
        $colaboradorId = $sujeto instanceof Colaborador ? $sujeto->id : null;

        if ($sujeto instanceof Colaborador) {
            $sujeto->loadMissing(['sucursalPrincipal.empresa', 'puesto', 'departamento', 'jefe.jefe', 'gerente', 'user']);
        } else {
            $sujeto->loadMissing(['empresa', 'sucursal', 'departamento', 'puestoObjetivo']);
        }

        $solicitud = $solicitudId !== null
            ? SolicitudInterna::query()->with('revisadoPor.colaborador')->whereKey($solicitudId)->first()
            : null;

        if ($solicitud !== null && ! in_array($colaboradorId, [$solicitud->personaSolicitante()?->id, $solicitud->colaboradorDeBaja()?->id], true)) {
            throw ValidationException::withMessages(['solicitud_id' => 'La solicitud no pertenece a esta persona.']);
        }

        $prestamo = $prestamoId !== null ? Prestamo::query()->whereKey($prestamoId)->where('colaborador_id', $colaboradorId)->first() : null;
        $contrato = $contratoId !== null ? ContratoLaboral::query()->with(['puesto', 'sucursal'])->whereKey($contratoId)->where('colaborador_id', $colaboradorId)->first() : null;

        if (($prestamoId !== null && $prestamo === null) || ($contratoId !== null && $contrato === null)) {
            throw ValidationException::withMessages(['contexto' => 'El préstamo o contrato indicado no pertenece a esta persona.']);
        }

        return new ContextoFormato($sujeto, $solicitud, $prestamo, $contrato, $generador, now('America/Mexico_City'), 'FO-'.strtoupper(Str::random(8)));
    }

    /**
     * Punto de entrada de web y API: resuelve la persona (dentro del alcance
     * de quien genera), arma el contexto y prepara la versión vigente.
     *
     * @param  array{tipo_sujeto: string, sujeto_id: int, solicitud_id?: int|null, prestamo_id?: int|null, contrato_id?: int|null}  $datos
     * @param  array<string, string|null>  $manuales
     * @return array{sujeto: Colaborador|Candidato, contexto: ContextoFormato, preparacion: Preparacion}
     */
    public function prepararPara(OfficialFormat $formato, User $usuario, array $datos, array $manuales): array
    {
        $sujeto = $datos['tipo_sujeto'] === 'colaborador'
            ? Colaborador::query()->where('id', $datos['sujeto_id'])->first()
            : Candidato::query()->where('id', $datos['sujeto_id'])->first();

        abort_if($sujeto === null, 404, 'No se encontró el colaborador o candidato indicado.');

        if ($sujeto instanceof Colaborador) {
            abort_unless($this->alcance->puedeVerExpediente($usuario, $sujeto), 404);
        }

        $contexto = $this->contexto($sujeto, $datos['solicitud_id'] ?? null, $datos['prestamo_id'] ?? null, $datos['contrato_id'] ?? null, $usuario);
        $preparacion = $this->preparar($this->versionParaGenerar($formato), $contexto, $manuales, $usuario->can('formatos_oficiales.datos_salariales'));

        return ['sujeto' => $sujeto, 'contexto' => $contexto, 'preparacion' => $preparacion];
    }

    /**
     * Normaliza la entrada validada de GenerarFormatoOficialRequest.
     *
     * @param  array<string, mixed>  $validado
     * @return array{0: array{tipo_sujeto: string, sujeto_id: int, solicitud_id: int|null, prestamo_id: int|null, contrato_id: int|null}, 1: array<string, string|null>}
     */
    public static function entrada(array $validado): array
    {
        $entero = fn (string $clave): ?int => isset($validado[$clave]) && is_numeric($validado[$clave]) ? (int) $validado[$clave] : null;
        $manuales = [];

        foreach ((array) ($validado['manuales'] ?? []) as $clave => $valor) {
            $manuales[(string) $clave] = is_scalar($valor) ? (string) $valor : null;
        }

        return [[
            'tipo_sujeto' => (string) ($validado['tipo_sujeto'] ?? 'colaborador'),
            'sujeto_id' => (int) ($entero('sujeto_id') ?? 0),
            'solicitud_id' => $entero('solicitud_id'),
            'prestamo_id' => $entero('prestamo_id'),
            'contrato_id' => $entero('contrato_id'),
        ], $manuales];
    }

    /**
     * @return list<string> 'solicitud' | 'prestamo' | 'contrato'
     */
    public function contextosQueUsa(OfficialFormatVersion $version): array
    {
        $usados = [];

        foreach ($version->camposConfigurados() as $campo) {
            $definicion = $this->catalogo->definicion((string) ($campo['variable'] ?? ''));

            if ($definicion !== null && isset(self::CONTEXTOS[$definicion['contexto']])) {
                $usados[$definicion['contexto']] = true;
            }
        }

        return array_keys($usados);
    }

    public function versionParaGenerar(OfficialFormat $formato): OfficialFormatVersion
    {
        $formato->loadMissing('versionVigente');

        if (! $formato->is_active || $formato->estaArchivado()) {
            throw ValidationException::withMessages(['formato' => 'Este formato está archivado y ya no se usa para generar documentos nuevos.']);
        }

        if (! $formato->tieneConfiguracion() || $formato->versionVigente === null) {
            throw ValidationException::withMessages(['formato' => 'Este formato todavía no tiene una versión publicada con campos configurados.']);
        }

        return $formato->versionVigente;
    }

    /**
     * @param  array<string, string|null>  $manuales  clave de campo manual → valor capturado
     * @return Preparacion
     */
    public function preparar(OfficialFormatVersion $version, ContextoFormato $contexto, array $manuales, bool $puedeVerSensibles): array
    {
        $valores = $this->resolvedor->resolver($contexto);
        $colaborador = $contexto->colaborador();
        $usados = $this->contextosQueUsa($version);
        $contextosFaltantes = array_values(array_filter($usados, fn (string $c) => match ($c) {
            'solicitud' => $contexto->solicitud === null,
            'prestamo' => $contexto->prestamo === null,
            'contrato' => $contexto->contrato === null,
            'finiquito' => $contexto->finiquito === null,
            default => false,
        }));

        $textos = [];
        $imagenes = [];
        $marcas = [];
        $faltantes = [];
        $manualesPedidos = [];
        $datos = [];
        $snapshot = [];
        $bloqueoSensible = false;

        foreach ($version->camposConfigurados() as $campo) {
            $id = (string) $campo['id'];
            $tipo = (string) ($campo['tipo'] ?? 'variable');
            $requerido = (bool) ($campo['requerido'] ?? false);

            if ($tipo === 'texto') {
                $textos[$id] = (string) ($campo['texto'] ?? '');

                continue;
            }

            if ($tipo === 'manual') {
                $clave = $this->claveManual($campo);
                $valor = trim((string) ($manuales[$clave] ?? ''));
                $etiqueta = (string) ($campo['etiqueta'] ?? 'Dato manual');

                if (! isset($manualesPedidos[$clave])) {
                    $manualesPedidos[$clave] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'requerido' => $requerido, 'valor' => $valor];
                } elseif ($requerido) {
                    $manualesPedidos[$clave]['requerido'] = true;
                }

                if ($valor === '') {
                    if ($requerido) {
                        $marcas[$id] = sprintf('[Falta: %s]', $etiqueta);
                    }

                    continue;
                }

                $textos[$id] = $valor;
                $snapshot['manual.'.$clave] = $valor;

                continue;
            }

            $variable = (string) ($campo['variable'] ?? '');
            $definicion = $this->catalogo->definicion($variable);

            if ($definicion === null) {
                continue;
            }

            if ($definicion['sensible'] && ! $puedeVerSensibles) {
                $bloqueoSensible = true;
                $marcas[$id] = '[Dato restringido]';

                continue;
            }

            if ($tipo === 'imagen' || $definicion['tipo'] === 'imagen') {
                $bytes = $this->resolvedor->imagen($variable, $contexto);

                if ($bytes !== null) {
                    $imagenes[$id] = $bytes;
                } elseif ($requerido) {
                    $faltantes[$variable] = $this->faltante($variable, $definicion['etiqueta'], $definicion['completar'], $colaborador);
                }

                continue;
            }

            $texto = $this->formateador->formatear($valores[$variable] ?? null, $definicion['tipo'], $campo['formato'] ?? null);

            if (trim($texto) === '') {
                if ($requerido) {
                    $faltantes[$variable] = $this->faltante($variable, $definicion['etiqueta'], $definicion['completar'], $colaborador);
                    $marcas[$id] = sprintf('[Falta: %s]', $definicion['etiqueta']);
                }

                continue;
            }

            $textos[$id] = $texto;
            $snapshot[$variable] = $texto;
            $datos[$variable] = ['etiqueta' => $definicion['etiqueta'], 'valor' => $texto];
        }

        $manualesPedidos = array_values($manualesPedidos);
        $manualFaltante = array_filter($manualesPedidos, fn (array $m) => $m['requerido'] && $m['valor'] === '') !== [];

        return [
            'version' => $version,
            'textos' => $textos,
            'imagenes' => $imagenes,
            'marcas' => $marcas,
            'faltantes' => array_values($faltantes),
            'manuales' => $manualesPedidos,
            'contextos_faltantes' => $contextosFaltantes,
            'bloqueo_sensible' => $bloqueoSensible,
            'datos' => array_values($datos),
            'snapshot' => $snapshot,
            'puede_generar' => $faltantes === [] && ! $manualFaltante && $contextosFaltantes === [] && ! $bloqueoSensible,
        ];
    }

    /**
     * Mensaje único para "no se puede generar todavía".
     *
     * @param  Preparacion  $preparacion
     */
    public function motivoBloqueo(array $preparacion): ?string
    {
        if ($preparacion['puede_generar']) {
            return null;
        }

        $partes = [];

        if ($preparacion['bloqueo_sensible']) {
            $partes[] = 'este formato incluye datos salariales y no tienes permiso para verlos';
        }

        foreach ($preparacion['contextos_faltantes'] as $contexto) {
            $partes[] = sprintf('selecciona %s', self::CONTEXTOS[$contexto] ?? $contexto);
        }

        $faltan = [
            ...array_column($preparacion['faltantes'], 'etiqueta'),
            ...array_column(array_filter($preparacion['manuales'], fn (array $m) => $m['requerido'] && $m['valor'] === ''), 'etiqueta'),
        ];

        if ($faltan !== []) {
            $partes[] = sprintf('falta: %s', implode(', ', $faltan));
        }

        return sprintf('No se puede generar todavía: %s.', implode('; ', $partes));
    }

    /**
     * @param  Preparacion  $preparacion
     */
    public function vistaPrevia(array $preparacion): string
    {
        return $this->renderizador->renderizar($preparacion['version'], $preparacion['textos'], $preparacion['imagenes'], $preparacion['marcas'], 'VISTA PREVIA — NO ES EL DOCUMENTO OFICIAL');
    }

    /**
     * Vista previa del editor con DATOS DE EJEMPLO del catálogo (nunca de
     * una persona real), rotulada como tal en cada página.
     */
    public function vistaPreviaEjemplo(OfficialFormatVersion $version): string
    {
        $textos = [];

        foreach ($version->camposConfigurados() as $campo) {
            $id = (string) $campo['id'];

            $textos[$id] = match ($campo['tipo'] ?? 'variable') {
                'texto' => (string) ($campo['texto'] ?? ''),
                'manual' => sprintf('«%s»', (string) ($campo['etiqueta'] ?? 'Dato manual')),
                'imagen' => '',
                default => $this->ejemplo((string) ($campo['variable'] ?? ''), $campo['formato'] ?? null),
            };
        }

        return $this->renderizador->renderizar($version, $textos, [], [], 'VISTA PREVIA — DATOS DE EJEMPLO');
    }

    private function ejemplo(string $variable, mixed $formato): string
    {
        $definicion = $this->catalogo->definicion($variable);

        if ($definicion === null || $definicion['ejemplo'] === '') {
            return '';
        }

        $valor = $definicion['tipo'] === 'fecha'
            ? Carbon::createFromFormat('d/m/Y', $definicion['ejemplo'])
            : $definicion['ejemplo'];

        return $this->formateador->formatear($valor, $definicion['tipo'], is_string($formato) ? $formato : null);
    }

    /**
     * @param  Preparacion  $preparacion
     */
    public function generar(array $preparacion, ContextoFormato $contexto, User $actor, bool $guardarEnExpediente = true): OfficialFormatGeneration
    {
        $bloqueo = $this->motivoBloqueo($preparacion);

        if ($bloqueo !== null) {
            throw ValidationException::withMessages(['documento' => $bloqueo]);
        }

        $version = $preparacion['version'];
        $formato = $version->formato;
        $pdf = $this->renderizador->renderizar($version, $preparacion['textos'], $preparacion['imagenes']);
        $colaborador = $contexto->colaborador();
        $nombre = sprintf('%s - %s.pdf', $formato->nombre, now()->format('Y-m-d His'));
        $enExpediente = $guardarEnExpediente && $colaborador !== null;

        try {
            if ($enExpediente) {
                $disco = (string) config('expedientes.disk');
                $ruta = $this->expediente->guardarContenidoEnExpediente($colaborador, $formato->tipo->categoriaDocumento(), $nombre, $pdf);
            } else {
                $disco = (string) config('formatos_oficiales.disk');
                $ruta = $this->storage->rutaGenerado();
                $this->storage->guardarContenido($ruta, $pdf);
            }
        } catch (Throwable $e) {
            Log::error('No se pudo guardar un documento generado desde plantilla oficial.', ['formato_id' => $formato->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['documento' => 'No se pudo guardar el documento en el almacenamiento (NAS no disponible). Intenta de nuevo.']);
        }

        $manuales = [];

        foreach ($preparacion['manuales'] as $manual) {
            if ($manual['valor'] !== '') {
                $manuales[$manual['clave']] = $manual['valor'];
            }
        }

        try {
            $generacion = DB::transaction(fn () => OfficialFormatGeneration::query()->create([
                'official_format_id' => $formato->id,
                'official_format_version_id' => $version->id,
                'version_numero' => $version->numero,
                'solicitud_interna_id' => $contexto->solicitud?->id,
                'prestamo_id' => $contexto->prestamo?->id,
                'contrato_laboral_id' => $contexto->contrato?->id,
                'colaborador_id' => $colaborador?->id,
                'candidato_id' => $contexto->sujeto instanceof Candidato ? $contexto->sujeto->id : null,
                'generated_by_id' => $actor->id,
                'generated_disk' => $disco,
                'generated_path' => $ruta,
                'generated_name' => Str::of($nombre)->replace(['/', '\\', '"'], '-')->toString(),
                'data_snapshot' => [...$preparacion['snapshot'], 'otros.referencia' => $contexto->referencia],
                'valores_manuales' => $manuales,
                'checksum' => hash('sha256', $pdf),
                'en_expediente' => $enExpediente,
                'status' => EstadoFormatoOficialGeneracion::Generado,
            ]));
        } catch (Throwable $e) {
            // La fila no existe: el PDF recién escrito no queda huérfano.
            $enExpediente ? $this->expediente->eliminar($ruta) : $this->storage->eliminar($ruta);

            throw $e;
        }

        $this->auditoria->registrar('formato_oficial_generado', $generacion, $actor, [
            'formato_id' => $formato->id,
            'version' => $version->numero,
            'colaborador_id' => $colaborador?->id,
            'candidato_id' => $generacion->candidato_id,
            'solicitud_id' => $generacion->solicitud_interna_id,
            'checksum' => $generacion->checksum,
        ]);

        return $generacion;
    }

    /**
     * Solicitudes / préstamos / contratos de la persona para elegir de cuál
     * sale el documento, solo de los contextos que la plantilla usa.
     *
     * @param  list<string>  $requeridos
     * @return array<string, list<array{id: int, label: string}>>
     */
    public function opcionesContexto(Colaborador|Candidato $sujeto, array $requeridos): array
    {
        if (! $sujeto instanceof Colaborador) {
            return [];
        }

        $opciones = [];

        if (in_array('solicitud', $requeridos, true)) {
            $opciones['solicitud'] = array_values(SolicitudInterna::query()
                ->where(fn ($q) => $q->where('colaborador_id', $sujeto->id)
                    ->orWhere('objetivo_colaborador_id', $sujeto->id)
                    ->when($sujeto->user !== null, fn ($q2) => $q2->orWhere('user_id', $sujeto->user?->id)))
                ->latest()
                ->limit(40)
                ->get()
                ->map(fn (SolicitudInterna $s) => ['id' => $s->id, 'label' => sprintf('%s · %s · %s', $s->folio, $s->tipo->etiqueta(), $s->estado->etiqueta())])
                ->all());
        }

        if (in_array('prestamo', $requeridos, true)) {
            $opciones['prestamo'] = array_values(Prestamo::query()
                ->where('colaborador_id', $sujeto->id)
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (Prestamo $p) => ['id' => $p->id, 'label' => sprintf('Préstamo #%d · $%s · %s', $p->id, number_format((float) $p->monto_original, 2), str_replace('_', ' ', $p->estado))])
                ->all());
        }

        if (in_array('contrato', $requeridos, true)) {
            $opciones['contrato'] = array_values(ContratoLaboral::query()
                ->where('colaborador_id', $sujeto->id)
                ->latest('fecha_inicio')
                ->limit(20)
                ->get()
                ->map(fn (ContratoLaboral $c) => ['id' => $c->id, 'label' => sprintf('%s · desde %s', $c->tipo->etiqueta(), $c->fecha_inicio->format('d/m/Y'))])
                ->all());
        }

        return $opciones;
    }

    /**
     * PDF final sin persistir nada, para módulos que archivan el documento
     * por su cuenta (MotorDocumentalService: contratos, pagarés, actas;
     * FiniquitoService). Mismas reglas: si falta un dato requerido, no se
     * genera.
     *
     * @param  array<string, string|null>  $manuales
     */
    public function renderizarPara(OfficialFormat $formato, ContextoFormato $contexto, array $manuales = []): string
    {
        $preparacion = $this->preparar($this->versionParaGenerar($formato), $contexto, $manuales, true);
        $bloqueo = $this->motivoBloqueo($preparacion);

        if ($bloqueo !== null) {
            throw ValidationException::withMessages(['documento' => sprintf('«%s»: %s', $formato->nombre, $bloqueo)]);
        }

        return $this->renderizador->renderizar($preparacion['version'], $preparacion['textos'], $preparacion['imagenes']);
    }

    /**
     * Contexto a partir del registro que origina el documento (contrato,
     * préstamo, finiquito, solicitud) sin volver a pedir nada.
     */
    public function contextoDesde(Colaborador $colaborador, ?Model $origen, ?User $generador, string $referencia = ''): ContextoFormato
    {
        $colaborador->loadMissing(['sucursalPrincipal.empresa', 'puesto', 'departamento', 'jefe.jefe', 'gerente', 'user']);

        return new ContextoFormato(
            sujeto: $colaborador,
            solicitud: $origen instanceof SolicitudInterna ? $origen : ($origen instanceof FiniquitoCalculo ? $origen->solicitudInterna : null),
            prestamo: $origen instanceof Prestamo ? $origen : null,
            contrato: $origen instanceof ContratoLaboral ? $origen : null,
            generador: $generador,
            fecha: now('America/Mexico_City'),
            referencia: $referencia,
            finiquito: $origen instanceof FiniquitoCalculo ? $origen : null,
        );
    }

    /**
     * @param  array<string, mixed>  $campo
     */
    public function claveManual(array $campo): string
    {
        $base = (string) ($campo['placeholder'] ?? '') !== '' ? (string) $campo['placeholder'] : (string) ($campo['etiqueta'] ?? $campo['id']);

        return Str::slug($base, '_') ?: (string) $campo['id'];
    }

    /**
     * @return array{variable: string, etiqueta: string, completar_url: string|null}
     */
    private function faltante(string $variable, string $etiqueta, ?string $completar, ?Colaborador $colaborador): array
    {
        $url = match ($completar) {
            'expediente' => $colaborador !== null ? route('rh.expedientes.show', $colaborador->id) : null,
            'sucursal' => $colaborador?->sucursal_principal_id !== null ? route('administracion.sucursales.show', $colaborador->sucursal_principal_id) : null,
            'empresa' => route('administracion.empresas.index'),
            default => null,
        };

        return ['variable' => $variable, 'etiqueta' => $etiqueta, 'completar_url' => $url];
    }
}
