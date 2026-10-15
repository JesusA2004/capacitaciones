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
    ) {}

    /**
     * @return Collection<int, Responsiva>
     */
    public function delColaborador(Colaborador $colaborador): Collection
    {
        return Responsiva::query()
            ->where('colaborador_id', $colaborador->id)
            ->with(['activos', 'entregadoPor:id,name,apellidos'])
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
        $snapshot = $this->snapshot($colaborador, $actor);

        return [
            'lugar' => $this->lugarPorDefecto($colaborador),
            'fecha_entrega' => Carbon::now('America/Mexico_City')->toDateString(),
            'receptor' => $snapshot['receptor'],
            'otorgante' => $snapshot['otorgante'],
            'empresa' => $snapshot['empresa']['razon_social'] ?? $snapshot['empresa']['nombre'],
        ];
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

        return $responsiva->refresh()->load(['activos', 'entregadoPor:id,name,apellidos']);
    }

    /** Escaneo de la responsiva ya firmada en papel (PDF o foto). */
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

        return [
            'id' => $responsiva->id,
            'folio' => $responsiva->folio,
            'tipo' => $responsiva->tipo->value,
            'tipo_etiqueta' => $responsiva->tipo->denominacion(),
            'fecha_entrega' => $responsiva->fecha_entrega->toDateString(),
            'lugar' => $responsiva->lugar,
            'estado' => $responsiva->estado->value,
            'estado_etiqueta' => $responsiva->estado->etiqueta(),
            'entregado_por' => trim(sprintf('%s %s', $entrego->name, $entrego->apellidos ?? '')),
            'observaciones' => $responsiva->observaciones,
            'activos' => $responsiva->activos->map(fn (ResponsivaActivo $a): array => [
                'descripcion' => $a->descripcion,
                'detalle' => collect([$a->marca, $a->modelo, $a->numero_serie !== null ? 'S/N '.$a->numero_serie : null])->filter()->implode(' · '),
                'cantidad' => $a->cantidad,
                'estado' => $a->estado->etiqueta(),
            ])->all(),
            'tiene_pdf' => $responsiva->pdf_path !== null,
            'tiene_firmada' => $responsiva->firmada_path !== null,
            'firmada_subida_en' => $responsiva->firmada_subida_en?->toIso8601String(),
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
        return [
            'colaborador_id' => $colaborador->id,
            'tipo' => TipoResponsiva::from((string) $datos['tipo']),
            'lugar' => trim((string) $datos['lugar']),
            'fecha_entrega' => Carbon::parse((string) $datos['fecha_entrega'])->toDateString(),
            'entregado_por' => $actor->id,
            'observaciones' => $this->limpio($datos['observaciones'] ?? null),
            'snapshot' => $this->snapshot($colaborador, $actor),
            'estado' => EstadoResponsiva::Generada,
        ];
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
     * Datos congelados al generar: quién recibe, quién entrega y la empresa
     * patronal (razón social de Empresas, la misma de los formatos oficiales).
     *
     * @return array{receptor: array<string, string|null>, otorgante: array<string, string|null>, empresa: array<string, string|null>}
     */
    private function snapshot(Colaborador $colaborador, User $actor): array
    {
        $colaborador->loadMissing(['puesto:id,nombre', 'departamento:id,nombre', 'sucursalPrincipal.empresa']);
        $actor->loadMissing('colaborador.puesto:id,nombre');
        $sucursal = $colaborador->sucursalPrincipal;
        $empresa = $sucursal?->empresa;
        $otorgante = $actor->colaborador;

        return [
            'receptor' => [
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'sucursal' => $sucursal?->nombre,
            ],
            'otorgante' => [
                'nombre' => $otorgante !== null ? $otorgante->nombreCompleto() : trim(sprintf('%s %s', $actor->name, $actor->apellidos ?? '')),
                'puesto' => $otorgante?->puesto?->nombre,
            ],
            'empresa' => [
                'nombre' => $empresa?->nombre,
                'razon_social' => $empresa?->razon_social,
                'logo_path' => $empresa?->logo_path,
            ],
        ];
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
