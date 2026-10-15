<?php

namespace App\Services\Actas;

use App\Enums\AlcanceDatos;
use App\Enums\CaracterLevantamientoActa;
use App\Enums\CategoriaDocumento;
use App\Enums\EstadoActa;
use App\Enums\EstadoUsuario;
use App\Enums\RelacionTestigoActa;
use App\Enums\TipoActa;
use App\Enums\TipoIncidenciaActa;
use App\Models\ActaAdministrativa;
use App\Models\ActaAnexo;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Permisos\AlcanceModuloService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Acta administrativa, acta de hechos, carta responsiva y acta de
 * auditoría: captura estructurada (fecha, hora, lugar, hechos,
 * responsable, testigos, declaraciones, anexos, negativa a firmar,
 * seguimiento), formato generado con el motor documental (plantilla por
 * tipo, texto de Jurídico) y archivo en el expediente (carpeta Actas).
 */
class ActaService
{
    /** Acta administrativa circunstanciada (docs/acta/…docx): formato nuevo. */
    public const FORMATO_CIRCUNSTANCIADA = 'circunstanciada';

    public function __construct(
        private readonly DocumentoStorageService $expediente,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AlcanceModuloService $alcanceModulo,
    ) {}

    /**
     * Catálogos cerrados del acta circunstanciada para la captura (web y API).
     *
     * @return array{incidencias: list<array{value: string, label: string}>, caracteres: list<array{value: string, label: string}>, relaciones: list<array{value: string, label: string}>}
     */
    public static function catalogos(): array
    {
        $opciones = fn (array $casos): array => array_values(array_map(fn (TipoIncidenciaActa|CaracterLevantamientoActa|RelacionTestigoActa $c): array => ['value' => $c->value, 'label' => $c->etiqueta()], $casos));

        return [
            'incidencias' => $opciones(TipoIncidenciaActa::cases()),
            'caracteres' => $opciones(CaracterLevantamientoActa::cases()),
            'relaciones' => $opciones(RelacionTestigoActa::cases()),
        ];
    }

    /**
     * Carácter con el que interviene quien levanta el acta, propuesto desde
     * PEOPLE (rol y organigrama). La captura puede cambiarlo.
     */
    public function caracterSugerido(User $usuario, Colaborador $trabajador): CaracterLevantamientoActa
    {
        return match (true) {
            $usuario->hasAnyRole(['super_admin', 'rh_admin', 'rh_auxiliar']) => CaracterLevantamientoActa::RecursosHumanos,
            $usuario->colaborador !== null && $trabajador->jefe_id === $usuario->colaborador->id => CaracterLevantamientoActa::JefeDirecto,
            $usuario->hasAnyRole(['gerente', 'gerente_sucursal', 'subgerente', 'gerente_regional']) => CaracterLevantamientoActa::Gerente,
            default => CaracterLevantamientoActa::ResponsableAutorizado,
        };
    }

    /**
     * Testigos elegibles: colaboradores ACTIVOS dentro del alcance del
     * módulo de actas de quien levanta (gerente/subgerente: su sucursal;
     * RH/corporativo con alcance global: toda la organización). Al abrir el
     * selector (sin texto) salen primero los de SU sucursal; al escribir se
     * busca por nombre, apellido, número de empleado, puesto o sucursal
     * («Orizaba» → activos de Orizaba). El cargo sale del puesto, nunca
     * texto libre.
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null}>
     */
    public function buscarTestigos(User $usuario, string $termino, ?int $excluirId = null): array
    {
        $palabras = array_slice(preg_split('/\s+/u', Str::lower(Str::ascii(trim($termino))), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
        $sucursalPropia = $usuario->colaborador?->sucursal_principal_id;

        $consulta = Colaborador::query()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->where('estatus', EstadoUsuario::Activo->value)
            ->when($excluirId !== null, fn (Builder $q) => $q->where('id', '!=', $excluirId));

        $global = $usuario->hasRole('super_admin') || $this->alcanceModulo->nivel($usuario, 'actas') === AlcanceDatos::Todos;

        if (! $global) {
            $this->alcanceModulo->limitarColaboradores($consulta, $usuario, 'actas');
        }

        // Sin texto: solo los de su sucursal (no cargar a toda la empresa).
        if ($palabras === [] && $sucursalPropia !== null) {
            $consulta->where('sucursal_principal_id', $sucursalPropia);
        }

        $candidatos = $consulta->orderBy('name')->orderBy('apellidos')->limit(3000)->get();

        return array_values($candidatos
            ->filter(function (Colaborador $c) use ($palabras): bool {
                $texto = Str::lower(Str::ascii(sprintf('%s %s %s %s %s', $c->name, $c->apellidos, $c->numero_empleado, $c->puesto?->nombre, $c->sucursalPrincipal?->nombre)));

                return collect($palabras)->every(fn (string $p): bool => str_contains($texto, $p));
            })
            // Los de su sucursal primero.
            ->sortBy(fn (Colaborador $c): int => $c->sucursal_principal_id === $sucursalPropia ? 0 : 1)
            ->take(30)
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'numero_empleado' => $c->numero_empleado,
                'puesto' => $c->puesto?->nombre,
                'sucursal' => $c->sucursalPrincipal?->nombre,
            ])
            ->values()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $datos  Validado por GuardarActaRequest.
     */
    public function crear(Colaborador $colaborador, array $datos, User $actor): ActaAdministrativa
    {
        $acta = DB::transaction(function () use ($colaborador, $datos, $actor): ActaAdministrativa {
            $tipo = TipoActa::from($datos['tipo']);
            $acta = ActaAdministrativa::query()->create([
                ...$this->campos($datos, $colaborador, null, $datos['responsable_id'] ?? $actor->id),
                'colaborador_id' => $colaborador->id,
                'tipo' => $tipo,
                'formato' => $tipo === TipoActa::Administrativa ? self::FORMATO_CIRCUNSTANCIADA : null,
                'estado' => EstadoActa::Borrador,
                'sucursal_id' => $datos['sucursal_id'] ?? $colaborador->sucursal_principal_id,
                'responsable_id' => $datos['responsable_id'] ?? $actor->id,
                'creado_por' => $actor->id,
            ]);

            $acta->update(['folio' => sprintf('ACT-%06d', $acta->id)]);

            return $acta;
        });

        $this->auditoria->registrar('acta_creada', $acta, $actor, ['tipo' => $acta->tipo->value, 'colaborador_id' => $colaborador->id]);

        return $acta->refresh();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(ActaAdministrativa $acta, array $datos, User $actor): ActaAdministrativa
    {
        if (! $acta->estado->esEditable()) {
            throw ValidationException::withMessages(['acta' => 'Solo un acta en borrador puede editarse; registra cambios como seguimiento.']);
        }

        $acta->update($this->campos($datos, $acta->colaborador, $acta->circunstanciada, $datos['responsable_id'] ?? $acta->responsable_id));
        $this->auditoria->registrar('acta_actualizada', $acta, $actor, ['campos' => array_keys($datos)]);

        return $acta->refresh();
    }

    public function agregarAnexo(ActaAdministrativa $acta, UploadedFile $archivo, ?string $descripcion, User $actor, ?string $fechaIdentificacion = null, ?string $relacionHechos = null): ActaAnexo
    {
        if (in_array($acta->estado, [EstadoActa::Cerrada, EstadoActa::Cancelada], true)) {
            throw ValidationException::withMessages(['acta' => 'El acta ya está cerrada.']);
        }

        // El acta circunstanciada relaciona sus anexos en el PDF congelado:
        // uno agregado después no aparecería en el documento firmado.
        if ($acta->formato === self::FORMATO_CIRCUNSTANCIADA && ! $acta->estado->esEditable()) {
            throw ValidationException::withMessages(['archivo' => 'El acta definitiva ya relaciona sus anexos. Registra la nueva evidencia como seguimiento.']);
        }

        $contenido = (string) file_get_contents($archivo->getRealPath());
        $ruta = $this->expediente->guardarContenidoEnExpediente(
            $acta->colaborador,
            CategoriaDocumento::Actas,
            sprintf('%s - anexo - %s.%s', $acta->folio, pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME), strtolower($archivo->getClientOriginalExtension())),
            $contenido,
        );

        $anexo = $acta->anexos()->create([
            'disk' => config('expedientes.disk'),
            'path' => $ruta,
            'original_name' => $archivo->getClientOriginalName(),
            'mime' => $archivo->getClientMimeType(),
            'size' => $archivo->getSize() ?: null,
            'checksum' => hash('sha256', $contenido),
            'descripcion' => $descripcion,
            'fecha_identificacion' => $fechaIdentificacion,
            'relacion_hechos' => $relacionHechos,
            'subido_por' => $actor->id,
        ]);

        $this->auditoria->registrar('acta_anexo', $acta, $actor, ['anexo_id' => $anexo->id]);

        return $anexo;
    }

    public function descargarAnexo(ActaAdministrativa $acta, ActaAnexo $anexo): StreamedResponse
    {
        abort_unless($anexo->acta_administrativa_id === $acta->id, 404);
        $disco = Storage::disk($anexo->disk);
        $nombre = str_replace(['"', '\\', '/'], '', $anexo->original_name);

        return $disco->response($anexo->path, $nombre, [
            'Content-Type' => $anexo->mime ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$nombre.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Genera el formato del acta con la plantilla de su tipo (texto de
     * Jurídico) y los datos capturados.
     */
    public function generarDocumento(ActaAdministrativa $acta, User $actor): GeneratedDocument
    {
        // Mismo camino que la web: formato oficial, faltantes, flujo físico
        // y archivo en el expediente (DocumentoProcesoService).
        return app(DocumentoProcesoService::class)->generar('acta', $acta, $acta->tipo->clavePlantilla(), $actor, regenerar: $acta->generated_document_id !== null);
    }

    public function registrarNegativaFirma(ActaAdministrativa $acta, string $motivo, User $actor): ActaAdministrativa
    {
        $acta->update(['negativa_firma' => true, 'motivo_negativa' => $motivo]);
        $this->agregarSeguimientoInterno($acta, "Negativa a firmar: {$motivo}", $actor);
        $this->auditoria->registrar('acta_negativa_firma', $acta, $actor, ['motivo' => $motivo]);

        return $acta->refresh();
    }

    public function agregarSeguimiento(ActaAdministrativa $acta, string $nota, User $actor): ActaAdministrativa
    {
        $this->agregarSeguimientoInterno($acta, $nota, $actor);
        $this->auditoria->registrar('acta_seguimiento', $acta, $actor);

        return $acta->refresh();
    }

    public function cerrar(ActaAdministrativa $acta, User $actor): ActaAdministrativa
    {
        if ($acta->estado === EstadoActa::Borrador) {
            throw ValidationException::withMessages(['acta' => 'Genera el formato del acta antes de cerrarla.']);
        }

        $firmada = $acta->documento?->estado_flujo?->estaFirmado() ?? false;

        $acta->update([
            'estado' => EstadoActa::Cerrada,
            'cerrada_en' => now(),
        ]);

        $this->agregarSeguimientoInterno($acta, $firmada ? 'Acta cerrada (firmada).' : ($acta->negativa_firma ? 'Acta cerrada con negativa a firmar.' : 'Acta cerrada.'), $actor);
        $this->auditoria->registrar('acta_cerrada', $acta, $actor);

        return $acta->refresh();
    }

    /**
     * @param  array{tipo?: string|null, colaborador_id?: int|string|null, estado?: string|null, per_page?: int|string|null}  $filtros
     * @return LengthAwarePaginator<int, ActaAdministrativa>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = ActaAdministrativa::query()->with('colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id');

        // Alcance del módulo «actas» (Roles y permisos), no por nombre de rol.
        $this->alcance->limitarPorModulo($query, $usuario, 'actas', 'colaborador_id', true);

        return $query
            ->when($filtros['tipo'] ?? null, fn (Builder $q, string $v) => $q->where('tipo', $v))
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('colaborador_id', (int) $v))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(ActaAdministrativa $acta, bool $detalle = false): array
    {
        $datos = [
            'id' => $acta->id,
            'folio' => $acta->folio,
            'tipo' => $acta->tipo->value,
            'tipo_etiqueta' => $acta->tipo->etiqueta(),
            'motivo' => $acta->motivo,
            'estado_etiqueta' => $acta->estado->etiqueta(),
            'estado' => $acta->estado->value,
            'colaborador' => ['id' => $acta->colaborador->id, 'nombre' => $acta->colaborador->nombreCompleto(), 'numero_empleado' => $acta->colaborador->numero_empleado],
            'fecha' => $acta->fecha->toDateString(),
            'hora' => $acta->hora,
            'lugar' => $acta->lugar,
            'negativa_firma' => $acta->negativa_firma,
            'documento_id' => $acta->generated_document_id,
            'formato' => $acta->formato,
            'cerrada_en' => $acta->cerrada_en?->toIso8601String(),
        ];

        if ($detalle) {
            $datos += [
                'hechos' => $acta->hechos,
                'responsable_id' => $acta->responsable_id,
                'testigos' => $acta->testigos ?? [],
                'declaraciones' => $acta->declaraciones ?? [],
                'motivo_negativa' => $acta->motivo_negativa,
                'circunstanciada' => $acta->circunstanciada,
                // Jefe inmediato que PEOPLE propone (organigrama), para precargar.
                'jefe_sugerido' => $acta->colaborador->jefe !== null ? ['id' => $acta->colaborador->jefe->id, 'nombre' => $acta->colaborador->jefe->nombreCompleto(), 'puesto' => $acta->colaborador->jefe->puesto?->nombre] : null,
                'seguimiento' => $acta->seguimiento ?? [],
                'anexos' => $acta->anexos()->get()->map(fn (ActaAnexo $a) => [
                    'id' => $a->id,
                    'nombre' => $a->original_name,
                    'descripcion' => $a->descripcion,
                    'fecha_identificacion' => $a->fecha_identificacion,
                    'relacion_hechos' => $a->relacion_hechos,
                    'mime' => $a->mime,
                    'size' => $a->size,
                ])->all(),
            ];
        }

        return $datos;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>|null  $circunstanciadaPrevia
     * @return array<string, mixed>
     */
    private function campos(array $datos, Colaborador $colaboradorDelActa, ?array $circunstanciadaPrevia = null, ?int $responsableId = null): array
    {
        $campos = array_intersect_key($datos, array_flip(['motivo', 'fecha', 'hora', 'lugar', 'hechos', 'testigos', 'declaraciones', 'responsable_id', 'sucursal_id']));

        if (array_key_exists('circunstanciada', $datos) && is_array($datos['circunstanciada'])) {
            $campos['circunstanciada'] = $this->resolverCircunstanciada($datos['circunstanciada'], $colaboradorDelActa, $circunstanciadaPrevia ?? [], $responsableId);
            $incidencia = $campos['circunstanciada']['incidencia'];
            // Resumen para listados: el tipo de incidencia (nunca una calificación).
            $campos['motivo'] = $incidencia['tipo'] === TipoIncidenciaActa::Otra->value
                ? ($incidencia['otro'] ?? null)
                : (TipoIncidenciaActa::tryFrom((string) $incidencia['tipo'])?->etiqueta());
        }

        if (array_key_exists('testigos', $campos)) {
            $campos['testigos'] = $this->resolverTestigos($campos['testigos'] ?? []);
        }

        if (array_key_exists('declaraciones', $campos)) {
            $campos['declaraciones'] = $this->resolverDeclaraciones($colaboradorDelActa, $campos['declaraciones'] ?? []);
        }

        return $campos;
    }

    /**
     * Nunca confía en nombre/puesto que mande el cliente (encargo §24-26): el
     * backend resuelve cada testigo por su colaborador_id (ya validado
     * activo y distinto del sujeto del acta por GuardarActaRequest) y
     * guarda un snapshot real — nombre completo y puesto actual —, no lo que
     * venga en el request.
     *
     * @param  array<int, array<string, mixed>>  $testigos
     * @return array<int, array{colaborador_id: int, nombre: string, puesto: string}>
     */
    private function resolverTestigos(array $testigos): array
    {
        return array_values(array_map(function (array $testigo): array {
            $colaborador = Colaborador::query()->findOrFail((int) $testigo['colaborador_id']);

            return [
                'colaborador_id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'puesto' => $colaborador->puesto->nombre ?? '',
            ];
        }, $testigos));
    }

    /**
     * La primera manifestación es siempre la del sujeto del acta: nunca se
     * borra, nunca cambia de persona, y el nombre lo pone el backend (nunca
     * el cliente) — encargo §28. Si no hay texto, "No quiso manifestar
     * nada." en vez de mandar una declaración vacía (encargo §30).
     *
     * @param  array<int, array<string, mixed>>  $declaraciones
     * @return array<int, array{persona: string, declaracion: string}>
     */
    private function resolverDeclaraciones(Colaborador $colaboradorDelActa, array $declaraciones): array
    {
        $declaraciones = array_values($declaraciones);

        // Whitelist: cualquier otra llave que venga en el request se descarta.
        $resueltas = array_map(fn (array $d): array => [
            'persona' => trim((string) ($d['persona'] ?? '')),
            'declaracion' => trim((string) ($d['declaracion'] ?? '')),
        ], $declaraciones);

        $resueltas[0] = [
            'persona' => $colaboradorDelActa->nombreCompleto(),
            'declaracion' => ($resueltas[0]['declaracion'] ?? '') !== '' ? $resueltas[0]['declaracion'] : 'No quiso manifestar nada.',
        ];

        return $resueltas;
    }

    /**
     * Normaliza la captura del acta circunstanciada. Las personas se
     * resuelven SIEMPRE de PEOPLE por colaborador_id (nombre, puesto y
     * área reales); lo que el cliente mande como texto se ignora. Entrega de
     * copia y control documental no se capturan aquí: se conservan.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $previa
     * @return array<string, mixed>
     */
    private function resolverCircunstanciada(array $d, Colaborador $trabajador, array $previa, ?int $responsableId): array
    {
        $texto = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $persona = function (mixed $id): ?array {
            $c = is_numeric($id) ? Colaborador::query()->with(['puesto.departamento', 'departamento'])->where('id', (int) $id)->first() : null;

            return $c === null ? null : ['colaborador_id' => $c->id, ...self::fichaPersona($c)];
        };

        $responsable = $responsableId !== null ? User::query()->with('colaborador.puesto.departamento', 'colaborador.departamento')->where('id', $responsableId)->first() : null;
        $jefeParticipa = (bool) ($d['jefe']['participa'] ?? false);
        $jefe = $jefeParticipa ? $persona($d['jefe']['colaborador_id'] ?? null) : null;
        $manifesto = $d['trabajador']['manifesto'] ?? null;

        return [
            'disposiciones' => $texto($d['disposiciones'] ?? null),
            'incidencia' => [
                'tipo' => $texto($d['incidencia']['tipo'] ?? null),
                'otro' => $texto($d['incidencia']['otro'] ?? null),
                'fecha_desde' => $texto($d['incidencia']['fecha_desde'] ?? null),
                'fecha_hasta' => $texto($d['incidencia']['fecha_hasta'] ?? null),
                'hora_desde' => $texto($d['incidencia']['hora_desde'] ?? null),
                'hora_hasta' => $texto($d['incidencia']['hora_hasta'] ?? null),
                'fecha_hechos' => self::periodoEnTexto($texto($d['incidencia']['fecha_desde'] ?? null), $texto($d['incidencia']['fecha_hasta'] ?? null)) ?? $texto($d['incidencia']['fecha_hechos'] ?? null),
                'hora_hechos' => self::horarioEnTexto($texto($d['incidencia']['hora_desde'] ?? null), $texto($d['incidencia']['hora_hasta'] ?? null)) ?? $texto($d['incidencia']['hora_hechos'] ?? null),
                'lugar_hechos' => $texto($d['incidencia']['lugar_hechos'] ?? null),
            ],
            'responsable' => [
                'caracter' => $texto($d['responsable']['caracter'] ?? null) ?? ($responsable !== null ? $this->caracterSugerido($responsable, $trabajador)->value : null),
                ...($responsable?->colaborador !== null ? self::fichaPersona($responsable->colaborador) : ['nombre' => $responsable !== null ? self::nombreDe($responsable) : null, 'puesto' => null, 'area' => null]),
                'manifestacion' => $texto($d['responsable']['manifestacion'] ?? null),
            ],
            'jefe' => [
                'participa' => $jefe !== null,
                ...($jefe ?? ['colaborador_id' => null, 'nombre' => null, 'puesto' => null, 'area' => null]),
                'manifestacion' => $jefe !== null ? $texto($d['jefe']['manifestacion'] ?? null) : null,
            ],
            'testigos_hechos' => array_values(array_filter(array_map(function (mixed $t) use ($persona, $texto): ?array {
                $p = is_array($t) ? $persona($t['colaborador_id'] ?? null) : null;

                return $p === null ? null : [
                    ...$p,
                    'relacion' => $texto($t['relacion'] ?? null),
                    'relacion_otro' => $texto($t['relacion_otro'] ?? null),
                    'declaro' => (bool) ($t['declaro'] ?? $texto($t['manifestacion'] ?? null) !== null),
                    'manifestacion' => $texto($t['manifestacion'] ?? null),
                ];
            }, (array) ($d['testigos_hechos'] ?? [])))),
            'testigos_asistencia' => array_values(array_filter(array_map(function (mixed $t) use ($persona, $texto): ?array {
                $p = is_array($t) ? $persona($t['colaborador_id'] ?? null) : null;

                return $p === null ? null : [...$p, 'manifestacion' => $texto($t['manifestacion'] ?? null)];
            }, (array) ($d['testigos_asistencia'] ?? [])))),
            'trabajador' => [
                'manifesto' => $manifesto === null ? null : (bool) $manifesto,
                'manifestacion' => (bool) $manifesto ? $texto($d['trabajador']['manifestacion'] ?? null) : null,
                'elementos' => $texto($d['trabajador']['elementos'] ?? null),
            ],
            'incidencias_levantamiento' => $texto($d['incidencias_levantamiento'] ?? null),
            'cierre' => [
                'fecha' => $texto($d['cierre']['fecha'] ?? null),
                'hora' => $texto($d['cierre']['hora'] ?? null),
            ],
            'entrega' => $previa['entrega'] ?? ['estado' => 'pendiente', 'motivo' => null, 'por' => null, 'en' => null],
            'control' => $previa['control'] ?? null,
        ];
    }

    /**
     * «13 de octubre de 2026», «del 6 al 8 de octubre de 2026» o «del 30 de
     * septiembre al 2 de octubre de 2026».
     */
    public static function periodoEnTexto(?string $desde, ?string $hasta): ?string
    {
        if ($desde === null) {
            return null;
        }

        $a = Carbon::parse($desde)->settings(['locale' => 'es']);
        $b = $hasta !== null ? Carbon::parse($hasta)->settings(['locale' => 'es']) : null;

        if ($b === null || $a->isSameDay($b)) {
            return $a->isoFormat('D [de] MMMM [de] YYYY');
        }

        return match (true) {
            $a->isSameMonth($b) => sprintf('del %s al %s', $a->isoFormat('D'), $b->isoFormat('D [de] MMMM [de] YYYY')),
            $a->isSameYear($b) => sprintf('del %s al %s', $a->isoFormat('D [de] MMMM'), $b->isoFormat('D [de] MMMM [de] YYYY')),
            default => sprintf('del %s al %s', $a->isoFormat('D [de] MMMM [de] YYYY'), $b->isoFormat('D [de] MMMM [de] YYYY')),
        };
    }

    /** «09:10 horas» o «de las 09:00 a las 09:30 horas». */
    public static function horarioEnTexto(?string $desde, ?string $hasta): ?string
    {
        if ($desde === null) {
            return null;
        }

        return $hasta !== null && $hasta !== $desde
            ? sprintf('de las %s a las %s horas', substr($desde, 0, 5), substr($hasta, 0, 5))
            : sprintf('%s horas', substr($desde, 0, 5));
    }

    /**
     * Nombre, puesto y área de una persona tal como están en PEOPLE.
     *
     * @return array{nombre: string, puesto: string|null, area: string|null}
     */
    public static function fichaPersona(Colaborador $c): array
    {
        return [
            'nombre' => $c->nombreCompleto(),
            'puesto' => $c->puesto?->nombre,
            'area' => $c->departamento->nombre ?? $c->puesto?->departamento?->nombre,
        ];
    }

    /** Nombre de una cuenta como está en PEOPLE (su colaborador; si no tiene, el de la cuenta). */
    public static function nombreDe(User $usuario): string
    {
        return $usuario->colaborador?->nombreCompleto() ?? trim($usuario->name.' '.($usuario->apellidos ?? ''));
    }

    /**
     * Constancia de entrega de copia (sección XII): pendiente, entregada o
     * no fue posible (con la circunstancia real). Nunca se afirma antes de
     * que ocurra.
     */
    public function registrarEntrega(ActaAdministrativa $acta, string $estado, ?string $motivo, User $actor): ActaAdministrativa
    {
        if (! in_array($estado, ['entregada', 'no_posible'], true)) {
            throw ValidationException::withMessages(['estado' => 'Indica si se entregó la copia o por qué no fue posible.']);
        }

        if ($estado === 'no_posible' && trim((string) $motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Describe la circunstancia real por la que no fue posible entregar la copia.']);
        }

        $datos = $acta->circunstanciada ?? [];
        $datos['entrega'] = [
            'estado' => $estado,
            'motivo' => $estado === 'no_posible' ? trim((string) $motivo) : null,
            'por' => self::nombreDe($actor),
            'en' => now()->toIso8601String(),
        ];
        $acta->update(['circunstanciada' => $datos]);
        $this->agregarSeguimientoInterno($acta, $estado === 'entregada' ? 'Se entregó copia del acta y sus anexos al trabajador.' : sprintf('No fue posible entregar copia: %s', trim((string) $motivo)), $actor);
        $this->auditoria->registrar('acta_entrega_copia', $acta, $actor, ['estado' => $estado]);

        return $acta->refresh();
    }

    private function agregarSeguimientoInterno(ActaAdministrativa $acta, string $nota, User $actor): void
    {
        $seguimiento = $acta->seguimiento ?? [];
        $seguimiento[] = ['fecha' => now()->toIso8601String(), 'nota' => $nota, 'user_id' => $actor->id];
        $acta->update(['seguimiento' => $seguimiento]);
    }

    /**
     * Lo que el formato toma del acta: motivo, hechos, manifestaciones,
     * fecha/hora/lugar y testigos. Lo demás (nombre, puesto, contrato,
     * empresa, representantes propuestos) sale de los datos del
     * colaborador en DatosDocumentoService. Vacío = dato faltante (el
     * formato no se emite con la línea en blanco).
     *
     * @return array<string, string>
     */
    public function variablesDocumento(ActaAdministrativa $acta): array
    {
        $testigos = array_values($acta->testigos ?? []);
        $variables = [
            'fecha_documento' => $acta->fecha->toDateString(),
            'fecha_acta_larga' => $acta->fecha->copy()->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY'),
            'hora_acta' => (string) $acta->hora,
            'motivo_acta_mayusculas' => mb_strtoupper(trim((string) $acta->motivo), 'UTF-8'),
            'hechos_acta' => trim($acta->hechos),
            'manifestaciones_acta' => collect($acta->declaraciones ?? [])
                ->map(fn (array $d): string => trim(sprintf('%s: %s', trim($d['persona']), trim($d['declaracion']))))
                ->implode("\n"),
        ];

        if (filled($acta->lugar)) {
            $variables['lugar_acta'] = (string) $acta->lugar;
        }

        // Variables de las plantillas anteriores (HTML) de actas: se conservan
        // para que un formato ya cargado con ellas siga llenándose igual.
        $acta->loadMissing('responsable');
        $variables += [
            'folio_acta' => (string) $acta->folio,
            'tipo_acta' => $acta->tipo->etiqueta(),
            'fecha_acta' => $acta->fecha->format('d/m/Y'),
            'responsable_acta' => $acta->responsable !== null ? trim($acta->responsable->name.' '.$acta->responsable->apellidos) : '',
            'testigos_acta' => collect($testigos)->map(fn (array $t): string => trim($t['nombre'].(isset($t['puesto']) && $t['puesto'] ? " ({$t['puesto']})" : '')))->implode(', '),
            'declaraciones_acta' => $variables['manifestaciones_acta'],
        ];

        foreach ([0, 1] as $i) {
            $variables[sprintf('testigo_%d_nombre', $i + 1)] = trim((string) ($testigos[$i]['nombre'] ?? ''));
            $variables[sprintf('testigo_%d_cargo', $i + 1)] = trim((string) ($testigos[$i]['puesto'] ?? ''));
        }

        return $variables;
    }

    /**
     * El formato quedó generado (web o app): se liga al acta y se anota en
     * su seguimiento.
     */
    public function formatoGenerado(ActaAdministrativa $acta, GeneratedDocument $documento, User $actor): void
    {
        $acta->update([
            'generated_document_id' => $documento->id,
            'estado' => $acta->estado === EstadoActa::Borrador ? EstadoActa::Generada : $acta->estado,
        ]);
        $this->agregarSeguimientoInterno($acta, 'Formato del acta generado.', $actor);
        $this->auditoria->registrar('acta_documento_generado', $acta, $actor, ['documento_id' => $documento->id]);
    }
}
