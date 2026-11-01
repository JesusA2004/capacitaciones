<?php

namespace App\Services\Documentos\Versiones\Fuentes;

use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\Documentos\Versiones\DocumentoRenderizado;
use App\Services\Documentos\Versiones\FuenteDocumento;
use App\Services\Nomina\ReciboNominaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Recibo de nómina: solo consulta de versiones. Un preparado se corrige en su
 * pantalla, un emitido solo por rectificación (recibo nuevo, ReciboNominaService).
 */
final class FuenteRecibo implements FuenteDocumento
{
    public const CLAVE = 'recibo_nomina_interno';

    public function __construct(
        private readonly ReciboNominaService $recibos,
        private readonly ReciboNomina $recibo,
    ) {}

    public function colaborador(): Colaborador
    {
        return $this->recibo->colaborador;
    }

    public function registro(): Model
    {
        return $this->recibo;
    }

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function etiqueta(): string
    {
        return sprintf('Recibo %s', (string) ($this->recibo->folio ?? $this->recibo->id));
    }

    public function puedeEditar(User $actor): bool
    {
        return false;
    }

    public function puedeVerVersiones(User $actor): bool
    {
        return $actor->can('ver', $this->recibo);
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
        return ['neto' => (string) $this->recibo->neto, 'percepciones' => (string) $this->recibo->total_percepciones, 'deducciones' => (string) $this->recibo->total_deducciones];
    }

    public function vistaPrevia(array $correcciones, User $actor): string
    {
        return $this->recibos->vistaPreviaGuardado($this->recibo);
    }

    public function renderizar(array $correcciones, User $actor): DocumentoRenderizado
    {
        throw ValidationException::withMessages(['documento' => $this->bloqueo(null, false)]);
    }

    public function despuesDeEmitir(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void {}

    public function bloqueo(?GeneratedDocument $vigente, bool $rectificar): string
    {
        return 'Un recibo se corrige en Nómina: preparado en su pantalla; emitido solo por rectificación, que conserva el original.';
    }
}
