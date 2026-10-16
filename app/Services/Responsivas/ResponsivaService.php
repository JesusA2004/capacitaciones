<?php

namespace App\Services\Responsivas;

use App\Enums\CategoriaDocumento;
use App\Enums\CondicionActivoResponsiva;
use App\Enums\EstadoResponsiva;
use App\Enums\EstadoUsuario;
use App\Enums\TipoResponsiva;
use App\Models\Colaborador;
use App\Models\Responsiva;
use App\Models\ResponsivaActivo;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Responsivas de equipo (formato docs/responsiva/ResponsivaLaptopPanta.docx).
 *
 *  1. El gerente (o RH) describe lo que entrega — laptop, casco, celular… —
 *     y PEOPLE llena la responsiva con los datos del colaborador, de la
 *     empresa y de quien entrega (congelados en `snapshot`).
 *  2. Chrome (Browsershot) imprime el PDF; se guarda en el NAS dentro del
 *     expediente del colaborador, carpeta «Actas» (Actas y responsivas).
 *  3. Se imprime y se firma en papel; opcionalmente se sube el escaneo
 *     firmado (misma carpeta). Después se puede registrar la devolución del
 *     equipo o cancelarla. Nada se borra: todo queda como historial.
 *
 * La autorización vive en App\Policies\ResponsivaPolicy (gerente con su
 * alcance y RH); este servicio aplica las reglas de negocio.
 */
class ResponsivaService
{
    public function __construct(
        private readonly ResponsivaPdfService $pdf,
        private readonly DocumentoStorageService $expediente,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * @return Collection<int, Responsiva>
     */
    public function delColaborador(Colaborador $colaborador): Collection
    {
        return Responsiva::query()
            ->where('colaborador_id', $colaborador->id)
            ->with(['activos', 'entregadoPor:id,name,apellidos,colaborador_id', 'entregadoPor.colaborador', 'registradoPor:id,name,apellidos,colaborador_id', 'registradoPor.colaborador'])
            ->latest('fecha_entrega')
            ->latest('id')
            ->get();
    }

    /**
     * Lo que el formulario muestra prellenado: lugar (ciudad, estado de la
     * sucursal), fecha de hoy, quién recibe y quién entrega.
     *
     * @return array<string, mixed>
     */
    public function valoresIniciales(Colaborador $colaborador, User $actor): array
    {
        // Por defecto, quien captura es también quien entrega (se prellenan
        // iguales). Si RH captura por otro, puede elegir al responsable real
        // dentro de su alcance desde `responsables`.
        $snapshot = $this->snapshot($colaborador, $actor);
        $responsables = $this->responsablesDisponibles($actor);

        return [
            'lugar' => $this->lugarPorDefecto($colaborador),
            'fecha_entrega' => Carbon::now('America/Mexico_City')->toDateString(),
            'receptor' => $snapshot['receptor'],
            'otorgante' => $snapshot['otorgante'],
            'empresa' => $snapshot['empresa']['razon_social'] ?? $snapshot['empresa']['nombre'],
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
        $responsiva->setRelation('activos', new Collection(array_map(
            fn (array $a, int $i): ResponsivaActivo => new ResponsivaActivo($this->atributosActivo($a, $i)),
            array_values($datos['activos']),
            array_keys(array_values($datos['activos'])),
        )));

        return $this->pdf->renderizar($responsiva, borrador: true)['pdf'];
    }

    /**
     * Genera la responsiva: filas + folio, PDF con Chrome y archivo en el
     * expediente. Si el PDF no se puede imprimir o guardar, no queda nada
     * a medias (se retira lo creado, también en tablas MyISAM).
     *
     * @param  array<string, mixed>  $datos
     */
    public function crear(Colaborador $colaborador, array $datos, User $actor): Responsiva
    {
        $this->exigirColaboradorVigente($colaborador);

        $responsiva = DB::transaction(function () use ($colaborador, $datos, $actor): Responsiva {
            $responsiva = Responsiva::query()->create($this->atributos($colaborador, $datos, $actor));

            foreach (array_values($datos['activos']) as $i => $activo) {
                $responsiva->activos()->create($this->atributosActivo($activo, $i));
            }

            $responsiva->update(['folio' => sprintf('RESP-%06d', $responsiva->id)]);

            return $responsiva;
        });

        $ruta = null;

        try {
            $pdf = $this->pdf->renderizar($responsiva->load('activos'))['pdf'];
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

            $responsiva->activos()->delete();
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
            'activos' => $responsiva->activos->pluck('descripcion')->all(),
        ]);

        return $responsiva->refresh()->load(['activos', 'entregadoPor:id,name,apellidos,colaborador_id', 'entregadoPor.colaborador', 'registradoPor:id,name,apellidos,colaborador_id', 'registradoPor.colaborador']);
    }

    /**
     * Firma física sin escaneo (regla vigente): la responsiva se imprime, se
     * firma en papel y RH/gerente presiona «Marcar firmada físicamente». No
     * se sube ningún archivo; solo se deja constancia de quién y cuándo.
     * subirFirmada()/firmada_path se conservan como LEGACY de solo lectura
     * para las responsivas antiguas que ya traían escaneo.
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

    /** LEGACY: escaneo de la responsiva ya firmada en papel (PDF o foto). */
    public function subirFirmada(Responsiva $responsiva, UploadedFile $archivo, User $actor): Responsiva
    {
        $this->exigirVigente($responsiva, 'subir la responsiva firmada');
        $responsiva->loadMissing('colaborador');
        $extension = strtolower($archivo->getClientOriginalExtension() ?: ($archivo->extension() ?? 'pdf'));

        try {
            $ruta = $this->expediente->guardarContenidoEnExpediente(
                $responsiva->colaborador,
                CategoriaDocumento::Actas,
                sprintf('Responsiva %s - firmada.%s', $responsiva->folio, $extension),
                (string) $archivo->get(),
            );
        } catch (Throwable $e) {
            Log::error('Responsiva: no se pudo guardar el escaneo firmado.', ['responsiva_id' => $responsiva->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el archivo (almacenamiento no disponible). Intenta de nuevo.']);
        }

        // El escaneo anterior (si lo hubo) se conserva en el NAS: nunca se
        // borra un documento del expediente.
        $responsiva->update([
            'firmada_path' => $ruta,
            'firmada_subida_en' => now(),
            'firmada_subida_por' => $actor->id,
            'estado' => EstadoResponsiva::Firmada,
        ]);

        $this->auditoria->registrar('responsiva_firmada', $responsiva, $actor, ['folio' => $responsiva->folio]);

        return $responsiva;
    }

    /**
     * El colaborador regresó el equipo.
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
        abort_if($ruta === null, 404, $firmada ? 'Aún no se sube la responsiva firmada.' : 'Esta responsiva no tiene PDF.');

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
        // Nombre del responsable: el congelado en el snapshot (el de la
        // persona, no el de la cuenta de acceso), con respaldo al colaborador.
        $entregoNombre = is_string($otorganteSnapshot['nombre'] ?? null) && trim((string) $otorganteSnapshot['nombre']) !== ''
            ? (string) $otorganteSnapshot['nombre']
            : $entrego->nombreCompleto();

        return [
            'id' => $responsiva->id,
            'folio' => $responsiva->folio,
            'tipo' => $responsiva->tipo->value,
            'tipo_etiqueta' => $responsiva->tipo->denominacion(),
            'fecha_entrega' => $responsiva->fecha_entrega->toDateString(),
            'lugar' => $responsiva->lugar,
            'estado' => $responsiva->estado->value,
            'estado_etiqueta' => $responsiva->estado->etiqueta(),
            // Quien entregó (responsable de entrega) y su puesto congelado.
            'entregado_por' => $entregoNombre,
            'entregado_por_puesto' => is_string($entregoPuesto) ? $entregoPuesto : null,
            // Quien capturó la responsiva en PEOPLE (puede ser distinto).
            'registrado_por' => $registro?->nombreCompleto(),
            'observaciones' => $responsiva->observaciones,
            'activos' => $responsiva->activos->map(fn (ResponsivaActivo $a): array => [
                'descripcion' => $a->descripcion,
                'detalle' => collect([$a->marca, $a->modelo, $a->numero_serie !== null ? 'S/N '.$a->numero_serie : null])->filter()->implode(' · '),
                'cantidad' => $a->cantidad,
                'estado' => $a->estado->etiqueta(),
            ])->all(),
            'tiene_pdf' => $responsiva->pdf_path !== null,
            // LEGACY: escaneo firmado histórico (solo lectura, ya no se exige).
            'tiene_firmada' => $responsiva->firmada_path !== null,
            'firmada_subida_en' => $responsiva->firmada_subida_en?->toIso8601String(),
            // Firma física sin archivo (flujo vigente).
            'firmada_fisicamente_en' => $responsiva->firmada_fisicamente_en?->toIso8601String(),
            'devuelta_en' => $responsiva->devuelta_en?->toDateString(),
            'observaciones_devolucion' => $responsiva->observaciones_devolucion,
            'motivo_cancelacion' => $responsiva->motivo_cancelacion,
            'puede_operar' => Gate::forUser($usuario)->allows('operar', $responsiva),
            'puede_cancelar' => Gate::forUser($usuario)->allows('cancelar', $responsiva),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function atributos(Colaborador $colaborador, array $datos, User $actor): array
    {
        // registrado_por = quien captura (el actor); entregado_por = quien
        // realmente entregó (responsable de entrega). Si no se eligió otro,
        // ambos coinciden (el que captura entrega él mismo).
        $responsable = $this->resolverResponsable($datos, $actor);

        return [
            'colaborador_id' => $colaborador->id,
            'tipo' => TipoResponsiva::from((string) $datos['tipo']),
            'lugar' => trim((string) $datos['lugar']),
            'fecha_entrega' => Carbon::parse((string) $datos['fecha_entrega'])->toDateString(),
            'entregado_por' => $responsable->id,
            'registrado_por' => $actor->id,
            'observaciones' => $this->limpio($datos['observaciones'] ?? null),
            'snapshot' => $this->snapshot($colaborador, $responsable, $datos),
            'estado' => EstadoResponsiva::Generada,
        ];
    }

    /**
     * Resuelve al responsable de entrega: el actor por defecto, o el usuario
     * elegido (`responsable_entrega_id`) si está dentro del alcance del actor.
     * No es inventario: solo deja constancia de quién entregó el equipo.
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
     * @param  array<string, mixed>  $activo
     * @return array<string, mixed>
     */
    private function atributosActivo(array $activo, int $indice): array
    {
        return [
            'orden' => $indice + 1,
            'descripcion' => trim((string) $activo['descripcion']),
            'marca' => $this->limpio($activo['marca'] ?? null),
            'modelo' => $this->limpio($activo['modelo'] ?? null),
            'numero_serie' => $this->limpio($activo['numero_serie'] ?? null),
            'color' => $this->limpio($activo['color'] ?? null),
            'cantidad' => max(1, (int) ($activo['cantidad'] ?? 1)),
            'estado' => CondicionActivoResponsiva::from((string) $activo['estado']),
            'caracteristicas' => $this->limpio($activo['caracteristicas'] ?? null),
            'accesorios' => $this->limpio($activo['accesorios'] ?? null),
        ];
    }

    /**
     * Datos congelados al generar, para que reimprimir la responsiva meses
     * después produzca el mismo documento aunque las personas cambien de
     * puesto: quién recibe, el responsable de entrega (nombre + puesto), la
     * empresa patronal y los equipos entregados.
     *
     * El responsable de entrega se congela bajo la llave `otorgante` (así lo
     * lee ResponsivaPdfService y lo imprime como «Otorgante»): es el nombre
     * de quien realmente entregó el equipo, no el del capturador.
     *
     * @param  array<string, mixed>  $datos
     * @return array{receptor: array<string, string|null>, otorgante: array<string, string|null>, empresa: array<string, string|null>, equipos: list<array<string, mixed>>}
     */
    private function snapshot(Colaborador $colaborador, User $responsable, array $datos = []): array
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
            // Equipos entregados, congelados tal cual se capturaron (la
            // responsiva es una constancia; nunca se reescribe después).
            'equipos' => $this->equiposSnapshot(is_array($datos['activos'] ?? null) ? $datos['activos'] : []),
        ];
    }

    /**
     * Congela la descripción de cada equipo entregado para el registro
     * histórico (independiente de las filas de responsiva_activos).
     *
     * @param  array<int|string, mixed>  $activos
     * @return list<array<string, mixed>>
     */
    private function equiposSnapshot(array $activos): array
    {
        $equipos = [];

        foreach (array_values($activos) as $activo) {
            if (! is_array($activo) || $this->limpio($activo['descripcion'] ?? null) === null) {
                continue;
            }

            $equipos[] = [
                'descripcion' => trim((string) $activo['descripcion']),
                'marca' => $this->limpio($activo['marca'] ?? null),
                'modelo' => $this->limpio($activo['modelo'] ?? null),
                'numero_serie' => $this->limpio($activo['numero_serie'] ?? null),
                'cantidad' => max(1, (int) ($activo['cantidad'] ?? 1)),
                'estado' => $this->limpio($activo['estado'] ?? null),
            ];
        }

        return $equipos;
    }

    private function lugarPorDefecto(Colaborador $colaborador): string
    {
        $sucursal = $colaborador->sucursalPrincipal;
        $ciudad = $sucursal->ciudad ?? $sucursal->municipio ?? $sucursal?->nombre;

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
