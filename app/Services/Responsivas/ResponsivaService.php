<?php

namespace App\Services\Responsivas;

use App\Enums\AccesorioEquipoElectronico;
use App\Enums\AccesorioVehiculo;
use App\Enums\CategoriaDocumento;
use App\Enums\EstadoConservacion;
use App\Enums\EstadoFisicoResponsiva;
use App\Enums\EstadoResponsiva;
use App\Enums\EstadoUsuario;
use App\Enums\NivelCombustible;
use App\Enums\TipoBienMueble;
use App\Enums\TipoEquipoElectronico;
use App\Enums\TipoResponsiva;
use App\Models\Colaborador;
use App\Models\Responsiva;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosMaestros\FirmaPatronService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Tareas\NotificadorRhService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Responsivas de las tres familias oficiales (docs/responsiva/*.docx):
 * bien mueble, equipo electrónico y vehículo.
 *
 *  1. El gerente (o RH) elige la familia y captura los campos legales de ese
 *     formato; PEOPLE llena la responsiva con los datos del colaborador, de la
 *     empresa y de quien entrega (congelados en `snapshot` y en `datos`).
 *  2. Vista previa → generar → el PDF (Browsershot) se guarda en el NAS dentro
 *     del expediente del colaborador, carpeta «Actas» (Actas y responsivas).
 *  3. Se imprime y se firma en papel; RH/gerente marca «firmada físicamente»
 *     (sin subir escaneo). Después se puede registrar la devolución o
 *     cancelarla. Nada se borra: todo queda como historial.
 *
 * Es DOCUMENTAL, no inventario: no hay stock, kardex ni serial único; se
 * permiten datos repetidos. La autorización vive en App\Policies\ResponsivaPolicy.
 */
class ResponsivaService
{
    public function __construct(
        private readonly ResponsivaPdfService $pdf,
        private readonly DocumentoStorageService $expediente,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FirmaPatronService $firmaPatron,
    ) {}

    /**
     * @return Collection<int, Responsiva>
     */
    public function delColaborador(Colaborador $colaborador): Collection
    {
        return Responsiva::query()
            ->where('colaborador_id', $colaborador->id)
            ->with(['entregadoPor:id,name,apellidos,colaborador_id', 'entregadoPor.colaborador', 'registradoPor:id,name,apellidos,colaborador_id', 'registradoPor.colaborador'])
            ->latest('fecha_entrega')
            ->latest('id')
            ->get();
    }

    /**
     * Panorama DOCUMENTAL de TODAS las responsivas dentro del alcance del
     * usuario (módulo «responsivas»), para el índice web
     * Rh/Responsivas/Index.vue. No es inventario: solo acopia las entregas
     * ya registradas de varias personas en una tabla, reutilizando el MISMO
     * scoping y las MISMAS operaciones per-persona (ResponsivaController),
     * sin duplicar ninguna regla de negocio.
     *
     * @param  array<string, mixed>  $filtros  tipo?, estado?, busqueda?, per_page?
     * @return LengthAwarePaginator<int, Responsiva>
     */
    public function panorama(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = Responsiva::query()->with([
            'colaborador:id,name,apellidos,numero_empleado,puesto_id,sucursal_principal_id',
            'colaborador.puesto:id,nombre',
            'colaborador.sucursalPrincipal:id,nombre',
            'entregadoPor:id,name,apellidos,colaborador_id',
            'entregadoPor.colaborador',
            'registradoPor:id,name,apellidos,colaborador_id',
            'registradoPor.colaborador',
        ]);

        $this->alcance->limitarPorModulo($query, $usuario, 'responsivas', 'colaborador_id', true);

        $tipo = isset($filtros['tipo']) && is_string($filtros['tipo']) ? TipoResponsiva::tryFrom($filtros['tipo']) : null;
        $estado = isset($filtros['estado']) && is_string($filtros['estado']) ? EstadoResponsiva::tryFrom($filtros['estado']) : null;
        $busqueda = isset($filtros['busqueda']) && is_string($filtros['busqueda']) ? trim($filtros['busqueda']) : '';

        return $query
            ->when($tipo !== null, fn (Builder $q) => $q->where('tipo', $tipo?->value))
            ->when($estado !== null, fn (Builder $q) => $q->where('estado', $estado?->value))
            ->when($busqueda !== '', fn (Builder $q) => $q->whereHas('colaborador', fn (Builder $c) => $c->where(fn (Builder $w) => $w
                ->where('name', 'like', sprintf('%%%s%%', $busqueda))
                ->orWhere('apellidos', 'like', sprintf('%%%s%%', $busqueda))
                ->orWhere('numero_empleado', 'like', sprintf('%%%s%%', $busqueda)))))
            ->latest('fecha_entrega')
            ->latest('id')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null));
    }

    /**
     * Fila del panorama: lo que presentar() ya produce más a quién pertenece
     * la responsiva (colaborador, puesto, sucursal), para listar las de varias
     * personas en una sola tabla.
     *
     * @return array<string, mixed>
     */
    public function presentarParaIndice(Responsiva $responsiva, User $usuario): array
    {
        $colaborador = $responsiva->colaborador;

        return [
            ...$this->presentar($responsiva, $usuario),
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            ],
        ];
    }

    /**
     * Lo que el formulario muestra prellenado: lugar (ciudad, estado de la
     * sucursal), fecha de hoy, quién recibe y quién entrega.
     *
     * @return array<string, mixed>
     */
    public function valoresIniciales(Colaborador $colaborador, User $actor): array
    {
        $snapshot = $this->snapshot($colaborador, $actor);
        $responsables = $this->responsablesDisponibles($actor);

        return [
            'lugar' => $this->lugarPorDefecto($colaborador),
            'fecha_entrega' => Carbon::now('America/Mexico_City')->toDateString(),
            'receptor' => [
                'nombre' => $snapshot['receptor']['nombre'],
                'numero_empleado' => $snapshot['receptor']['numero_empleado'],
                'puesto' => $snapshot['receptor']['puesto'],
                'area' => $snapshot['receptor']['departamento'],
                'sucursal' => $snapshot['receptor']['sucursal'],
            ],
            'otorgante' => $snapshot['otorgante'],
            'empresa' => $this->firmaPatron->razonSocial($snapshot['empresa']['nombre'], $snapshot['empresa']['razon_social']),
            'responsable_entrega_id' => $actor->id,
            'responsables' => $responsables,
            // Solo tiene sentido elegir a otro responsable si hay más de uno
            // (p.ej. RH con alcance amplio); un gerente normalmente entrega él
            // mismo y no necesita elegir.
            'puede_elegir_responsable' => count($responsables) > 1,
        ];
    }

    /**
     * Candidatos a «responsable de entrega» dentro del alcance del actor
     * (usuarios con colaborador que el actor puede ver), incluyéndose a sí
     * mismo siempre. No es inventario: solo acota quién pudo entregar.
     *
     * @return list<array{id: int, nombre: string, puesto: string|null}>
     */
    private function responsablesDisponibles(User $actor): array
    {
        $actor->loadMissing('colaborador.puesto:id,nombre');

        $candidatos = $this->alcance->limitarUsuariosPorAlcance(User::query(), $actor)
            ->whereHas('colaborador')
            ->with('colaborador.puesto:id,nombre')
            ->orderBy('name')
            ->limit(200)
            ->get();

        $responsables = [];
        $responsables[$actor->id] = [
            'id' => $actor->id,
            'nombre' => $actor->colaborador?->nombreCompleto() ?? trim(sprintf('%s %s', $actor->name, $actor->apellidos ?? '')),
            'puesto' => $actor->colaborador?->puesto?->nombre,
        ];

        foreach ($candidatos as $usuario) {
            $responsables[$usuario->id] = [
                'id' => $usuario->id,
                'nombre' => $usuario->colaborador?->nombreCompleto() ?? trim(sprintf('%s %s', $usuario->name, $usuario->apellidos ?? '')),
                'puesto' => $usuario->colaborador?->puesto?->nombre,
            ];
        }

        return array_values($responsables);
    }

    /**
     * PDF de vista previa con lo capturado (marca «VISTA PREVIA»; no guarda nada).
     *
     * @param  array<string, mixed>  $datos
     */
    public function vistaPrevia(Colaborador $colaborador, array $datos, User $actor): string
    {
        $this->exigirColaboradorVigente($colaborador);

        $responsiva = new Responsiva($this->atributos($colaborador, $datos, $actor));
        $responsiva->folio = 'VISTA PREVIA';

        return $this->pdf->renderizar($responsiva, borrador: true)['pdf'];
    }

    /**
     * Genera la responsiva: folio, PDF con Chrome y archivo en el expediente.
     * Si el PDF no se puede imprimir o guardar, no queda nada a medias.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crear(Colaborador $colaborador, array $datos, User $actor): Responsiva
    {
        $this->exigirColaboradorVigente($colaborador);

        $responsiva = DB::transaction(function () use ($colaborador, $datos, $actor): Responsiva {
            $responsiva = Responsiva::query()->create($this->atributos($colaborador, $datos, $actor));
            $responsiva->update(['folio' => sprintf('RESP-%06d', $responsiva->id)]);

            return $responsiva;
        });

        $ruta = null;

        try {
            $pdf = $this->pdf->renderizar($responsiva)['pdf'];
            $ruta = $this->expediente->guardarContenidoEnExpediente(
                $colaborador,
                CategoriaDocumento::Actas,
                sprintf('Responsiva %s - %s.pdf', $responsiva->folio, $responsiva->fecha_entrega->toDateString()),
                $pdf,
            );
            $responsiva->update(['pdf_path' => $ruta]);
        } catch (Throwable $e) {
            if ($ruta !== null) {
                $this->expediente->eliminar($ruta);
            }

            $responsiva->delete();

            if ($e instanceof ValidationException) {
                throw $e;
            }

            Log::error('Responsiva: no se pudo guardar el PDF en el expediente.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'responsiva' => 'No se pudo guardar la responsiva en el expediente (almacenamiento no disponible). Intenta de nuevo; si continúa, avisa a sistemas.',
            ]);
        }

        $this->auditoria->registrar('responsiva_generada', $responsiva, $actor, [
            'folio' => $responsiva->folio,
            'colaborador_id' => $colaborador->id,
            'tipo' => $responsiva->tipo->value,
        ]);

        // El colaborador que recibe el activo se entera (regla
        // «responsiva_registrada»: solo la persona). Nunca deshace la responsiva.
        try {
            app(NotificadorRhService::class)->notificarEvento(
                'responsiva_registrada',
                $colaborador,
                ['excluir' => [$actor->id]],
                'Responsiva de activos generada',
                sprintf('Se generó tu responsiva %s (%s). La firmarás en papel al recibir el equipo o activo.', (string) $responsiva->folio, mb_strtolower($responsiva->tipo->etiqueta())),
                $responsiva,
                'ver_responsiva',
            );
        } catch (Throwable $e) {
            Log::warning('ResponsivaService: no se pudo avisar al colaborador.', ['responsiva_id' => $responsiva->id, 'error' => $e->getMessage()]);
        }

        return $responsiva->refresh()->load(['entregadoPor:id,name,apellidos,colaborador_id', 'entregadoPor.colaborador', 'registradoPor:id,name,apellidos,colaborador_id', 'registradoPor.colaborador']);
    }

    /**
     * Regenera el PDF de una responsiva ya existente con el FORMATO VIGENTE
     * (p. ej. para corregir responsivas antiguas cuyo PDF decía «Apoderado
     * legal» o un nombre comercial). Reemplaza el archivo almacenado sin tocar
     * el snapshot (receptor, otorgante, fecha) ni el estado legal: la razón
     * social y el cargo del patrón se recalculan con FirmaPatronService.
     */
    public function regenerar(Responsiva $responsiva, User $actor): Responsiva
    {
        $colaborador = $responsiva->colaborador;
        $anterior = $responsiva->pdf_path;
        $nueva = null;

        try {
            $pdf = $this->pdf->renderizar($responsiva)['pdf'];
            $nueva = $this->expediente->guardarContenidoEnExpediente(
                $colaborador,
                CategoriaDocumento::Actas,
                sprintf('Responsiva %s - %s.pdf', $responsiva->folio, $responsiva->fecha_entrega->toDateString()),
                $pdf,
            );
            $responsiva->update(['pdf_path' => $nueva]);
        } catch (Throwable $e) {
            if ($nueva !== null) {
                $this->expediente->eliminar($nueva);
            }

            if ($e instanceof ValidationException) {
                throw $e;
            }

            Log::error('Responsiva: no se pudo regenerar el PDF.', ['folio' => $responsiva->folio, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'responsiva' => 'No se pudo regenerar la responsiva (impresión o almacenamiento no disponible). Intenta de nuevo; si continúa, avisa a sistemas.',
            ]);
        }

        // El PDF anterior se borra SOLO cuando ya se guardó el nuevo y la ruta cambió.
        if ($anterior !== null && $anterior !== $nueva) {
            try {
                $this->expediente->eliminar($anterior);
            } catch (Throwable $e) {
                Log::warning('Responsiva: no se pudo borrar el PDF anterior tras regenerar.', ['folio' => $responsiva->folio, 'error' => $e->getMessage()]);
            }
        }

        $this->auditoria->registrar('responsiva_regenerada', $responsiva, $actor, [
            'folio' => $responsiva->folio,
            'colaborador_id' => $colaborador->id,
        ]);

        return $responsiva->refresh()->load(['entregadoPor:id,name,apellidos,colaborador_id', 'entregadoPor.colaborador', 'registradoPor:id,name,apellidos,colaborador_id', 'registradoPor.colaborador']);
    }

    /**
     * Firma física sin escaneo (regla vigente): la responsiva se imprime, se
     * firma en papel y RH/gerente presiona «Marcar firmada físicamente». No se
     * sube ningún archivo; solo se deja constancia de quién y cuándo. El
     * escaneo firmado (firmada_path) solo existe en responsivas legacy y es de
     * solo lectura: ya no se puede subir uno nuevo (ver P1-14).
     */
    public function marcarFirmadaFisicamente(Responsiva $responsiva, User $actor): Responsiva
    {
        $this->exigirVigente($responsiva, 'marcarla como firmada físicamente');

        $responsiva->update([
            'estado' => EstadoResponsiva::Firmada,
            'firmada_fisicamente_en' => now(),
            'firmada_fisicamente_por' => $actor->id,
        ]);

        $this->auditoria->registrar('responsiva_firmada_fisicamente', $responsiva, $actor, ['folio' => $responsiva->folio]);

        return $responsiva;
    }

    /**
     * El colaborador regresó el bien.
     *
     * @param  array{devuelta_en: string, observaciones?: string|null}  $datos
     */
    public function registrarDevolucion(Responsiva $responsiva, array $datos, User $actor): Responsiva
    {
        $this->exigirVigente($responsiva, 'registrar la devolución');

        $fecha = Carbon::parse($datos['devuelta_en']);

        if ($fecha->lt($responsiva->fecha_entrega)) {
            throw ValidationException::withMessages(['devuelta_en' => 'La devolución no puede ser anterior a la entrega.']);
        }

        $responsiva->update([
            'estado' => EstadoResponsiva::Devuelta,
            'devuelta_en' => $fecha->toDateString(),
            'observaciones_devolucion' => $this->limpio($datos['observaciones'] ?? null),
            'devolucion_registrada_por' => $actor->id,
        ]);

        $this->auditoria->registrar('responsiva_devuelta', $responsiva, $actor, ['folio' => $responsiva->folio, 'devuelta_en' => $fecha->toDateString()]);

        return $responsiva;
    }

    public function cancelar(Responsiva $responsiva, string $motivo, User $actor): Responsiva
    {
        $this->exigirVigente($responsiva, 'cancelarla');

        $responsiva->update([
            'estado' => EstadoResponsiva::Cancelada,
            'cancelada_en' => now(),
            'cancelada_por' => $actor->id,
            'motivo_cancelacion' => trim($motivo),
        ]);

        $this->auditoria->registrar('responsiva_cancelada', $responsiva, $actor, ['folio' => $responsiva->folio, 'motivo' => trim($motivo)]);

        return $responsiva;
    }

    public function respuestaPdf(Responsiva $responsiva, bool $firmada = false): StreamedResponse
    {
        $ruta = $firmada ? $responsiva->firmada_path : $responsiva->pdf_path;
        abort_if($ruta === null, 404, $firmada ? 'Esta responsiva no tiene escaneo firmado.' : 'Esta responsiva no tiene PDF.');

        $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) ?: 'pdf';
        $nombre = sprintf('Responsiva %s%s.%s', $responsiva->folio, $firmada ? ' firmada' : '', $extension);

        return $this->expediente->respuesta($ruta, [
            'Content-Disposition' => sprintf('inline; filename="%s"', $nombre),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Fila para la ficha del colaborador (web), con lo que $usuario puede hacer.
     *
     * @return array<string, mixed>
     */
    public function presentar(Responsiva $responsiva, User $usuario): array
    {
        $entrego = $responsiva->entregadoPor;
        $registro = $responsiva->registradoPor;
        $otorganteSnapshot = is_array($responsiva->snapshot['otorgante'] ?? null) ? $responsiva->snapshot['otorgante'] : [];
        $entregoPuesto = $otorganteSnapshot['puesto'] ?? null;
        $entregoNombre = is_string($otorganteSnapshot['nombre'] ?? null) && trim((string) $otorganteSnapshot['nombre']) !== ''
            ? (string) $otorganteSnapshot['nombre']
            : $entrego->nombreCompleto();

        return [
            'id' => $responsiva->id,
            'folio' => $responsiva->folio,
            'tipo' => $responsiva->tipo->value,
            'tipo_etiqueta' => $responsiva->tipo->etiqueta(),
            'fecha_entrega' => $responsiva->fecha_entrega->toDateString(),
            'lugar' => $responsiva->lugar,
            'estado' => $responsiva->estado->value,
            'estado_etiqueta' => $responsiva->estado->etiqueta(),
            'entregado_por' => $entregoNombre,
            'entregado_por_puesto' => is_string($entregoPuesto) ? $entregoPuesto : null,
            'registrado_por' => $registro?->nombreCompleto(),
            'observaciones' => $responsiva->observaciones,
            // Resumen corto de lo entregado (para la tarjeta del listado).
            'resumen' => $this->resumen($responsiva),
            'tiene_pdf' => $responsiva->pdf_path !== null,
            // LEGACY: escaneo firmado histórico (solo lectura, ya no se sube).
            'tiene_firmada' => $responsiva->firmada_path !== null,
            'firmada_subida_en' => $responsiva->firmada_subida_en?->toIso8601String(),
            // Firma física sin archivo (flujo vigente).
            'firmada_fisicamente_en' => $responsiva->firmada_fisicamente_en?->toIso8601String(),
            'devuelta_en' => $responsiva->devuelta_en?->toDateString(),
            'observaciones_devolucion' => $responsiva->observaciones_devolucion,
            'motivo_cancelacion' => $responsiva->motivo_cancelacion,
            'puede_operar' => Gate::forUser($usuario)->allows('operar', $responsiva),
            // Acción por acción (misma ResponsivaPolicy que cada endpoint).
            'puede_registrar_firma' => Gate::forUser($usuario)->allows('registrarFirma', $responsiva),
            'puede_registrar_devolucion' => Gate::forUser($usuario)->allows('registrarDevolucion', $responsiva),
            'puede_descargar' => Gate::forUser($usuario)->allows('descargar', $responsiva),
            'puede_cancelar' => Gate::forUser($usuario)->allows('cancelar', $responsiva),
            // Reimprimir el PDF con el formato vigente (corrige responsivas antiguas).
            'puede_regenerar' => Gate::forUser($usuario)->allows('regenerar', $responsiva),
        ];
    }

    /**
     * Chips descriptivos de lo entregado, según la familia.
     *
     * @return list<string>
     */
    private function resumen(Responsiva $responsiva): array
    {
        $datos = is_array($responsiva->datos) ? $responsiva->datos : [];
        $texto = fn (string $clave): ?string => is_string($datos[$clave] ?? null) && trim((string) $datos[$clave]) !== '' ? trim((string) $datos[$clave]) : null;

        $chips = [];

        if ($responsiva->tipo === TipoResponsiva::BienMueble) {
            $tipo = is_string($datos['tipo_mueble'] ?? null) ? TipoBienMueble::tryFrom($datos['tipo_mueble']) : null;
            $chips[] = $tipo === TipoBienMueble::Otro ? ($texto('tipo_mueble_otro') ?? 'Otro mueble') : ($tipo?->etiqueta() ?? 'Mobiliario');
            $chips[] = $texto('numero_inventario') !== null ? sprintf('Inv. %s', $texto('numero_inventario')) : null;
            $chips[] = is_string($datos['estado'] ?? null) ? EstadoFisicoResponsiva::tryFrom($datos['estado'])?->etiqueta() : null;
        } elseif ($responsiva->tipo === TipoResponsiva::EquipoElectronico) {
            $tipo = is_string($datos['tipo_equipo'] ?? null) ? TipoEquipoElectronico::tryFrom($datos['tipo_equipo']) : null;
            $chips[] = $tipo === TipoEquipoElectronico::Otro ? ($texto('tipo_equipo_otro') ?? 'Otro equipo') : ($tipo?->etiqueta() ?? 'Equipo');
            $chips[] = collect([$texto('marca'), $texto('modelo')])->filter()->implode(' ') ?: null;
            $chips[] = $texto('numero_serie') !== null ? sprintf('S/N %s', $texto('numero_serie')) : null;
        } else {
            $chips[] = collect([$texto('marca'), $texto('submarca'), $texto('modelo_anio')])->filter()->implode(' ') ?: 'Vehículo';
            $chips[] = $texto('placas') !== null ? sprintf('Placas %s', $texto('placas')) : null;
        }

        return array_values(array_filter($chips, fn (?string $c): bool => $c !== null && $c !== ''));
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function atributos(Colaborador $colaborador, array $datos, User $actor): array
    {
        $responsable = $this->resolverResponsable($datos, $actor);
        $tipo = TipoResponsiva::from((string) $datos['tipo']);
        $datosFamilia = $this->normalizarDatos($tipo, is_array($datos['datos'] ?? null) ? $datos['datos'] : []);

        return [
            'colaborador_id' => $colaborador->id,
            'tipo' => $tipo,
            'lugar' => trim((string) $datos['lugar']),
            'fecha_entrega' => Carbon::parse((string) $datos['fecha_entrega'])->toDateString(),
            'entregado_por' => $responsable->id,
            'registrado_por' => $actor->id,
            'observaciones' => $this->limpio($datos['observaciones'] ?? null),
            'datos' => $datosFamilia,
            'snapshot' => $this->snapshot($colaborador, $responsable, $datosFamilia),
            'estado' => EstadoResponsiva::Generada,
        ];
    }

    /**
     * Resuelve al responsable de entrega: el actor por defecto, o el usuario
     * elegido (`responsable_entrega_id`) si está dentro del alcance del actor.
     *
     * @param  array<string, mixed>  $datos
     */
    private function resolverResponsable(array $datos, User $actor): User
    {
        $id = $datos['responsable_entrega_id'] ?? null;

        if ($id === null || (int) $id === $actor->id) {
            return $actor;
        }

        $responsable = User::query()->where('id', (int) $id)->first();
        $responsable?->loadMissing('colaborador');

        if ($responsable === null || $responsable->colaborador === null || ! $this->alcance->alcanzaColaboradorEnModulo($actor, $responsable->colaborador, 'responsivas')) {
            throw ValidationException::withMessages([
                'responsable_entrega_id' => 'El responsable de entrega seleccionado no es válido o está fuera de tu alcance.',
            ]);
        }

        return $responsable;
    }

    /**
     * Normaliza y limpia los campos de la familia: solo las claves conocidas,
     * strings vacíos a null, listas de accesorios filtradas a valores válidos.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizarDatos(TipoResponsiva $tipo, array $datos): array
    {
        $t = fn (string $clave): ?string => $this->limpio($datos[$clave] ?? null);
        $entero = fn (string $clave): ?int => isset($datos[$clave]) && is_numeric($datos[$clave]) ? (int) $datos[$clave] : null;

        return match ($tipo) {
            TipoResponsiva::BienMueble => [
                'tipo_mueble' => $this->enumValor(TipoBienMueble::class, $datos['tipo_mueble'] ?? null),
                'tipo_mueble_otro' => $t('tipo_mueble_otro'),
                'marca' => $t('marca'),
                'modelo' => $t('modelo'),
                'numero_inventario' => $t('numero_inventario'),
                'numero_serie' => $t('numero_serie'),
                'color' => $t('color'),
                'material' => $t('material'),
                'tamano' => $t('tamano'),
                'estado' => $this->enumValor(EstadoFisicoResponsiva::class, $datos['estado'] ?? null),
                'danos' => $t('danos'),
                'ubicacion_lugar' => $t('ubicacion_lugar'),
                'ubicacion_area' => $t('ubicacion_area'),
            ],
            TipoResponsiva::EquipoElectronico => [
                'tipo_equipo' => $this->enumValor(TipoEquipoElectronico::class, $datos['tipo_equipo'] ?? null),
                'tipo_equipo_otro' => $t('tipo_equipo_otro'),
                'marca' => $t('marca'),
                'modelo' => $t('modelo'),
                'numero_serie' => $t('numero_serie'),
                'numero_inventario' => $t('numero_inventario'),
                'imei1' => $t('imei1'),
                'imei2' => $t('imei2'),
                'numero_telefonico' => $t('numero_telefonico'),
                'estado' => $this->enumValor(EstadoFisicoResponsiva::class, $datos['estado'] ?? null),
                'danos' => $t('danos'),
                'accesorios' => $this->listaEnum(AccesorioEquipoElectronico::class, $datos['accesorios'] ?? null),
                'accesorios_otro' => $t('accesorios_otro'),
            ],
            TipoResponsiva::Vehiculo => [
                'marca' => $t('marca'),
                'submarca' => $t('submarca'),
                'modelo_anio' => $t('modelo_anio'),
                'color' => $t('color'),
                'niv' => $t('niv'),
                'numero_motor' => $t('numero_motor'),
                'placas' => $t('placas'),
                'kilometraje' => $entero('kilometraje'),
                'combustible' => $this->enumValor(NivelCombustible::class, $datos['combustible'] ?? null),
                'estado_exterior' => $this->enumValor(EstadoConservacion::class, $datos['estado_exterior'] ?? null),
                'estado_interior' => $this->enumValor(EstadoConservacion::class, $datos['estado_interior'] ?? null),
                'danos' => $t('danos'),
                'accesorios' => $this->listaEnum(AccesorioVehiculo::class, $datos['accesorios'] ?? null),
                'accesorios_otro' => $t('accesorios_otro'),
            ],
        };
    }

    /**
     * Valor (string) de un caso válido del enum, o null. Todos los catálogos
     * de responsivas son enums string-backed; normalizamos a string para
     * guardarlos en `datos`.
     *
     * @param  class-string<\BackedEnum>  $enum
     */
    private function enumValor(string $enum, mixed $valor): ?string
    {
        if (! is_string($valor) && ! is_int($valor)) {
            return null;
        }

        $caso = $enum::tryFrom($valor);

        return $caso !== null ? (string) $caso->value : null;
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return list<string>
     */
    private function listaEnum(string $enum, mixed $valores): array
    {
        if (! is_array($valores)) {
            return [];
        }

        $validos = [];

        foreach ($valores as $valor) {
            if ((is_string($valor) || is_int($valor)) && $enum::tryFrom($valor) !== null) {
                $validos[] = (string) $valor;
            }
        }

        return array_values(array_unique($validos));
    }

    /**
     * Datos congelados al generar, para que reimprimir la responsiva meses
     * después produzca el mismo documento aunque las personas cambien de
     * puesto. El responsable de entrega se congela bajo la llave `otorgante`.
     *
     * @param  array<string, mixed>  $datosFamilia
     * @return array{receptor: array<string, string|null>, otorgante: array<string, string|null>, empresa: array<string, string|null>, datos: array<string, mixed>}
     */
    private function snapshot(Colaborador $colaborador, User $responsable, array $datosFamilia = []): array
    {
        $colaborador->loadMissing(['puesto:id,nombre', 'departamento:id,nombre', 'sucursalPrincipal.empresa']);
        $responsable->loadMissing('colaborador.puesto:id,nombre');
        $sucursal = $colaborador->sucursalPrincipal;
        $empresa = $sucursal?->empresa;
        $entrega = $responsable->colaborador;

        return [
            'receptor' => [
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'sucursal' => $sucursal?->nombre,
            ],
            'otorgante' => [
                'nombre' => $entrega !== null ? $entrega->nombreCompleto() : trim(sprintf('%s %s', $responsable->name, $responsable->apellidos ?? '')),
                'puesto' => $entrega?->puesto?->nombre,
            ],
            'empresa' => [
                'nombre' => $empresa?->nombre,
                'razon_social' => $empresa?->razon_social,
                'logo_path' => $empresa?->logo_path,
            ],
            // Campos de la familia, congelados tal cual se capturaron.
            'datos' => $datosFamilia,
        ];
    }

    private function lugarPorDefecto(Colaborador $colaborador): string
    {
        $sucursal = $colaborador->sucursalPrincipal;
        // Sin sucursal principal no reventamos: el lugar queda vacío. El primer
        // dato con valor (ciudad, luego municipio, luego nombre) hace de ciudad.
        $ciudad = collect([$sucursal?->ciudad, $sucursal?->municipio, $sucursal?->nombre])
            ->first(fn (?string $v): bool => is_string($v) && trim($v) !== '');

        return collect([$ciudad, $sucursal?->estado])
            ->filter(fn (?string $v): bool => is_string($v) && trim($v) !== '')
            ->unique()
            ->implode(', ');
    }

    private function exigirColaboradorVigente(Colaborador $colaborador): void
    {
        if ($colaborador->trashed() || $colaborador->estatus === EstadoUsuario::Inactivo) {
            throw ValidationException::withMessages(['responsiva' => 'No se puede entregar equipo a un colaborador dado de baja.']);
        }
    }

    private function exigirVigente(Responsiva $responsiva, string $accion): void
    {
        if (! $responsiva->estado->vigente()) {
            throw ValidationException::withMessages(['responsiva' => sprintf('La responsiva %s está «%s»: ya no se puede %s.', $responsiva->folio, mb_strtolower($responsiva->estado->etiqueta(), 'UTF-8'), $accion)]);
        }
    }

    private function limpio(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }
}
