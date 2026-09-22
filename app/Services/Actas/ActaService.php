<?php

namespace App\Services\Actas;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoActa;
use App\Enums\TipoActa;
use App\Models\ActaAdministrativa;
use App\Models\ActaAnexo;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        private readonly MotorDocumentalService $motor,
        private readonly DocumentoStorageService $expediente,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por GuardarActaRequest.
     */
    public function crear(Colaborador $colaborador, array $datos, User $actor): ActaAdministrativa
    {
        $acta = DB::transaction(function () use ($colaborador, $datos, $actor): ActaAdministrativa {
            $acta = ActaAdministrativa::query()->create([
                ...$this->campos($datos),
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

        $acta->update($this->campos($datos));
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
        if (in_array($acta->estado, [EstadoActa::Cerrada, EstadoActa::Cancelada], true)) {
            throw ValidationException::withMessages(['acta' => 'El acta ya está cerrada.']);
        }

        $documento = $this->motor->generar($acta->colaborador, $acta->tipo->clavePlantilla(), $actor, $this->variables($acta), $acta, sprintf('%s %s', $acta->tipo->etiqueta(), $acta->folio));

        $acta->update(['generated_document_id' => $documento->id, 'estado' => EstadoActa::Generada]);
        $this->auditoria->registrar('acta_documento_generado', $acta, $actor, ['documento_id' => $documento->id]);

        return $documento;
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

        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->withTrashed(), $usuario)->select('id'));
        }

        return $query
            ->when($filtros['tipo'] ?? null, fn (Builder $q, string $v) => $q->where('tipo', $v))
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('colaborador_id', (int) $v))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
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
    private function campos(array $datos): array
    {
        return array_intersect_key($datos, array_flip(['fecha', 'hora', 'lugar', 'hechos', 'testigos', 'declaraciones', 'responsable_id', 'sucursal_id']));
    }

    private function agregarSeguimientoInterno(ActaAdministrativa $acta, string $nota, User $actor): void
    {
        $seguimiento = $acta->seguimiento ?? [];
        $seguimiento[] = ['fecha' => now()->toIso8601String(), 'nota' => $nota, 'user_id' => $actor->id];
        $acta->update(['seguimiento' => $seguimiento]);
    }

    /**
     * @return array<string, string>
     */
    private function variables(ActaAdministrativa $acta): array
    {
        $acta->loadMissing(['responsable', 'sucursal']);

        return [
            'folio_acta' => (string) $acta->folio,
            'tipo_acta' => $acta->tipo->etiqueta(),
            'fecha_acta' => $acta->fecha->format('d/m/Y'),
            'hora_acta' => (string) $acta->hora,
            'lugar_acta' => (string) ($acta->lugar ?? $acta->sucursal?->nombre),
            'hechos_acta' => $acta->hechos,
            'responsable_acta' => $acta->responsable !== null ? trim($acta->responsable->name.' '.$acta->responsable->apellidos) : '',
            'testigos_acta' => collect($acta->testigos ?? [])->map(fn (array $t) => trim($t['nombre'].(isset($t['puesto']) && $t['puesto'] ? " ({$t['puesto']})" : '')))->implode(', '),
            'declaraciones_acta' => collect($acta->declaraciones ?? [])->map(fn (array $d) => "{$d['persona']}: {$d['declaracion']}")->implode("\n"),
        ];
    }
}
