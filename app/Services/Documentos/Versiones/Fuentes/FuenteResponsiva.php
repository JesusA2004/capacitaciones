<?php

namespace App\Services\Documentos\Versiones\Fuentes;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoResponsiva;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Responsiva;
use App\Models\User;
use App\Services\Documentos\Versiones\DatosVolatiles;
use App\Services\Documentos\Versiones\DocumentoRenderizado;
use App\Services\Documentos\Versiones\FuenteDocumento;
use App\Services\Responsivas\ResponsivaPdfService;
use Illuminate\Database\Eloquent\Model;

/**
 * Responsiva de activos: el snapshot no se edita. Cada versión queda como
 * GeneratedDocument y ningún PDF anterior se borra. La firmada conserva `pdf_path`
 * apuntando a lo firmado: la rectificación queda aparte, ligada a ella.
 */
final class FuenteResponsiva implements FuenteDocumento
{
    public const CLAVE = 'responsiva_activos';

    public function __construct(
        private readonly ResponsivaPdfService $pdf,
        private readonly Responsiva $responsiva,
    ) {}

    public function colaborador(): Colaborador
    {
        return $this->responsiva->colaborador;
    }

    public function registro(): Model
    {
        return $this->responsiva;
    }

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function etiqueta(): string
    {
        return sprintf('Responsiva %s', (string) $this->responsiva->folio);
    }

    public function puedeEditar(User $actor): bool
    {
        return $actor->can('regenerar', $this->responsiva);
    }

    public function puedeVerVersiones(User $actor): bool
    {
        return $actor->can('descargar', $this->responsiva);
    }

    public function puedeRectificar(User $actor): bool
    {
        return $actor->can('regenerar', $this->responsiva) && $actor->can('documentos_laborales.revisar_firmado');
    }

    public function campos(User $actor): array
    {
        return [];
    }

    public function valoresHuella(array $correcciones, User $actor): array
    {
        return DatosVolatiles::quitar($this->pdf->datos($this->responsiva));
    }

    public function vistaPrevia(array $correcciones, User $actor): string
    {
        return $this->pdf->renderizar($this->responsiva, borrador: true)['pdf'];
    }

    public function renderizar(array $correcciones, User $actor): DocumentoRenderizado
    {
        $titulo = $this->etiqueta();

        return new DocumentoRenderizado($this->pdf->renderizar($this->responsiva)['pdf'], $titulo, CategoriaDocumento::Actas, [
            'categoria' => CategoriaDocumento::Actas,
            'proceso' => 'responsiva',
            'nombre_archivo' => sprintf('%s - %s.pdf', $titulo, $this->responsiva->fecha_entrega->toDateString()),
        ]);
    }

    public function despuesDeEmitir(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void
    {
        if ($this->responsiva->estado !== EstadoResponsiva::Firmada) {
            $this->responsiva->update(['pdf_path' => $documento->path]);
        }
    }

    public function bloqueo(?GeneratedDocument $vigente, bool $rectificar): ?string
    {
        return match ($this->responsiva->estado) {
            EstadoResponsiva::Cancelada => 'Esta responsiva está cancelada: se conserva como está.',
            EstadoResponsiva::Devuelta => 'El activo ya se devolvió: la responsiva se conserva como histórico.',
            default => null,
        };
    }
}
