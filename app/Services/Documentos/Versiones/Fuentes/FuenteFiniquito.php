<?php

namespace App\Services\Documentos\Versiones\Fuentes;

use App\Enums\EstadoFiniquito;
use App\Enums\TipoFiniquito;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Documentos\Versiones\DocumentoRenderizado;
use App\Services\Documentos\Versiones\FuenteDocumento;
use App\Services\Finiquitos\FiniquitoService;
use Illuminate\Database\Eloquent\Model;

/**
 * Finiquito (en PEOPLE no existen liquidaciones). Sus datos son filas calculadas: se corrigen con
 * FiniquitoService::corregir() (motivo y bitácora) y aquí solo se emite. Firmado
 * o pagado no admite rectificación: la define Jurídico (docs/CORRECCIONES.md).
 */
final class FuenteFiniquito implements FuenteDocumento
{
    public const CLAVE = 'finiquito';

    public function __construct(
        private readonly FiniquitoService $finiquitos,
        private readonly FiniquitoCalculo $finiquito,
    ) {}

    public function colaborador(): Colaborador
    {
        return $this->finiquito->colaborador;
    }

    public function registro(): Model
    {
        return $this->finiquito;
    }

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function etiqueta(): string
    {
        return TipoFiniquito::Finiquito->etiqueta();
    }

    public function puedeEditar(User $actor): bool
    {
        return $actor->can('generarDocumento', $this->finiquito);
    }

    public function puedeVerVersiones(User $actor): bool
    {
        return $actor->can('descargar', $this->finiquito);
    }

    public function puedeRectificar(User $actor): bool
    {
        return false;
    }

    public function campos(User $actor): array
    {
        return [];
    }

    public function valoresHuella(array $correcciones, User $actor): array
    {
        return $this->finiquitos->valoresDocumento($this->finiquito);
    }

    public function vistaPrevia(array $correcciones, User $actor): string
    {
        return $this->finiquitos->vistaPreviaPdf($this->finiquito);
    }

    public function renderizar(array $correcciones, User $actor): DocumentoRenderizado
    {
        return $this->finiquitos->prepararDocumento($this->finiquito, $actor);
    }

    public function despuesDeEmitir(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void
    {
        $this->finiquitos->ligarDocumento($this->finiquito, $documento);
    }

    public function bloqueo(?GeneratedDocument $vigente, bool $rectificar): ?string
    {
        return in_array($this->finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)
            ? 'Este finiquito ya está firmado o pagado y no puede modificarse en el sistema. Si hay un error, repórtalo a RH y Jurídico: el procedimiento para rectificarlo está pendiente de validación.'
            : null;
    }
}
