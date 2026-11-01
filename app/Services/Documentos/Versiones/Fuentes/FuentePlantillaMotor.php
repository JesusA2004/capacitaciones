<?php

namespace App\Services\Documentos\Versiones\Fuentes;

use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Documentos\Versiones\AlEmitirDocumento;
use App\Services\Documentos\Versiones\DatosVolatiles;
use App\Services\Documentos\Versiones\DocumentoRenderizado;
use App\Services\Documentos\Versiones\FuenteDocumento;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla del motor documental ligada a un registro, fuera de los procesos de
 * DocumentoProcesoService: paquete del contrato, aviso de término y emisión directa
 * por API. Le da el flujo uniforme (huella, motivo, firma, autoridad) a lo que antes
 * llamaba al motor sin pasar por VersionadoDocumentoService.
 */
final class FuentePlantillaMotor implements FuenteDocumento
{
    private ?DocumentTemplate $resuelta = null;

    /**
     * @param  array<string, mixed>  $extra  Variables del contexto.
     */
    public function __construct(
        private readonly MotorDocumentalService $motor,
        private readonly Colaborador $persona,
        private readonly Model $registro,
        private readonly DocumentTemplate|string $plantilla,
        private readonly array $extra = [],
        private readonly ?string $titulo = null,
        private readonly ?AlEmitirDocumento $alEmitir = null,
    ) {}

    public function colaborador(): Colaborador
    {
        return $this->persona;
    }

    public function registro(): Model
    {
        return $this->registro;
    }

    public function clave(): string
    {
        return is_string($this->plantilla) ? $this->plantilla : $this->plantilla->clave;
    }

    public function etiqueta(): string
    {
        return $this->titulo ?? $this->resolver()->nombre;
    }

    public function puedeEditar(User $actor): bool
    {
        return $actor->can('generarDocumento', $this->persona);
    }

    public function puedeVerVersiones(User $actor): bool
    {
        return $actor->can('verDocumentosLaborales', $this->persona);
    }

    public function puedeRectificar(User $actor): bool
    {
        return $actor->can('documentos_laborales.revisar_firmado');
    }

    public function campos(User $actor): array
    {
        return [];
    }

    public function valoresHuella(array $correcciones, User $actor): array
    {
        $plantilla = $this->resolver();

        $valores = $plantilla->estado_master === null
            ? [...$this->motor->valoresDePlantilla($this->persona, $this->extra), '_plantilla' => $plantilla->id.':'.$plantilla->version]
            : [...$this->motor->valoresDelMaestro($plantilla, $this->motor->contextoDesde($this->persona, $this->registro, $actor, $this->extra)), '_maestro' => (string) $plantilla->master_hash];

        return DatosVolatiles::quitar([...$valores, '_titulo' => $this->titulo], $correcciones);
    }

    public function vistaPrevia(array $correcciones, User $actor): string
    {
        return $this->renderizar($correcciones, $actor)->pdf;
    }

    public function renderizar(array $correcciones, User $actor): DocumentoRenderizado
    {
        $plantilla = $this->resolver();

        return $plantilla->estado_master === null
            ? $this->motor->prepararDesdePlantilla($this->persona, $plantilla, $this->extra, $this->registro, $actor, $this->titulo)
            : $this->motor->prepararDesdeMaestro($plantilla, $this->motor->contextoDesde($this->persona, $this->registro, $actor, $this->extra), $this->titulo);
    }

    public function despuesDeEmitir(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void
    {
        if ($this->alEmitir !== null) {
            ($this->alEmitir)($documento, $anterior, $correcciones, $motivo, $actor);
        }
    }

    public function bloqueo(?GeneratedDocument $vigente, bool $rectificar): ?string
    {
        return null;
    }

    private function resolver(): DocumentTemplate
    {
        return $this->resuelta ??= $this->motor->resolverPlantilla($this->plantilla, $this->persona);
    }
}
