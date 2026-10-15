<?php

namespace App\Services\Actas;

use App\Enums\AlcanceDatos;
use App\Enums\CategoriaDocumento;
use App\Enums\EstadoActa;
use App\Enums\EstadoUsuario;
use App\Enums\TipoActa;
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
    public function __construct(
        private readonly DocumentoStorageService $expediente,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AlcanceModuloService $alcanceModulo,
    ) {}

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
            $acta = ActaAdministrativa::query()->create([
                ...$this->campos($datos, $colaborador),
                'colaborador_id' => $colaborador->id,
                'tipo' => TipoActa::from($datos['tipo']),
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

        $acta->update($this->campos($datos, $acta->colaborador));
        $this->auditoria->registrar('acta_actualizada', $acta, $actor, ['campos' => array_keys($datos)]);

        return $acta->refresh();
    }

    public function agregarAnexo(ActaAdministrativa $acta, UploadedFile $archivo, ?string $descripcion, User $actor): ActaAnexo
    {
        if (in_array($acta->estado, [EstadoActa::Cerrada, EstadoActa::Cancelada], true)) {
            throw ValidationException::withMessages(['acta' => 'El acta ya está cerrada.']);
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
            'cerrada_en' => $acta->cerrada_en?->toIso8601String(),
        ];

        if ($detalle) {
            $datos += [
                'hechos' => $acta->hechos,
                'responsable_id' => $acta->responsable_id,
                'testigos' => $acta->testigos ?? [],
                'declaraciones' => $acta->declaraciones ?? [],
                'motivo_negativa' => $acta->motivo_negativa,
                'seguimiento' => $acta->seguimiento ?? [],
                'anexos' => $acta->anexos()->get()->map(fn (ActaAnexo $a) => [
                    'id' => $a->id,
                    'nombre' => $a->original_name,
                    'descripcion' => $a->descripcion,
                    'mime' => $a->mime,
                    'size' => $a->size,
                ])->all(),
            ];
        }

        return $datos;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function campos(array $datos, Colaborador $colaboradorDelActa): array
    {
        $campos = array_intersect_key($datos, array_flip(['motivo', 'fecha', 'hora', 'lugar', 'hechos', 'testigos', 'declaraciones', 'responsable_id', 'sucursal_id']));

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
