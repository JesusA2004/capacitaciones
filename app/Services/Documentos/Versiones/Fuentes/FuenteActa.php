<?php

namespace App\Services\Documentos\Versiones\Fuentes;

use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Actas\ActaPdfService;
use App\Services\Documentos\Versiones\AlEmitirDocumento;
use App\Services\Documentos\Versiones\DocumentoRenderizado;
use App\Services\Documentos\Versiones\FuenteDocumento;
use Illuminate\Database\Eloquent\Model;

/**
 * Acta con formato propio. Sus datos se corrigen en el acta misma, con motivo si
 * ya se generó (ActaService::actualizar); aquí solo se emite la versión.
 */
final class FuenteActa implements FuenteDocumento
{
    public function __construct(
        private readonly ActaPdfService $pdf,
        private readonly ActaAdministrativa $acta,
        private readonly ?AlEmitirDocumento $alEmitir = null,
    ) {}

    public function colaborador(): Colaborador
    {
        return $this->acta->colaborador;
    }

    public function registro(): Model
    {
        return $this->acta;
    }

    public function clave(): string
    {
        return $this->acta->tipo->clavePlantilla();
    }

    public function etiqueta(): string
    {
        return $this->acta->tipo->etiqueta();
    }

    public function puedeEditar(User $actor): bool
    {
        return $actor->can('documentos_laborales.generar');
    }

    public function puedeVerVersiones(User $actor): bool
    {
        return $actor->can('ver', $this->acta);
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
        return $this->pdf->valoresDocumento($this->acta->refresh());
    }

    public function vistaPrevia(array $correcciones, User $actor): string
    {
        return $this->pdf->vistaPrevia($this->acta);
    }

    public function renderizar(array $correcciones, User $actor): DocumentoRenderizado
    {
        return $this->pdf->preparar($this->acta, $actor);
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
}
