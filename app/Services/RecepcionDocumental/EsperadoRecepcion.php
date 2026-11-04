<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\ReglaRecepcionDocumental;
use App\Models\Colaborador;
use Illuminate\Database\Eloquent\Model;

/**
 * Un original esperado que calcula una regla: qué documento, de qué evento
 * real (origen) y el snapshot mínimo con que se guardará el renglón.
 */
final class EsperadoRecepcion
{
    public function __construct(
        public readonly ReglaRecepcionDocumental $regla,
        public readonly string $tipoDocumento,
        public readonly string $tipoEtiqueta,
        public readonly Model $origen,
        public readonly ?int $generatedDocumentId,
        public readonly ?int $colaboradorId,
        public readonly ?int $sucursalId,
        public readonly ?string $colaboradorNombre,
        public readonly ?string $numeroEmpleado,
        public readonly ?string $puestoNombre,
        public readonly ?string $sucursalNombre,
        public readonly ?string $fechaEvento,
        public readonly string $descripcion,
        public readonly int $cantidad = 1,
    ) {}

    /** Snapshot tomado del colaborador (su puesto y sucursal de hoy). */
    public static function deColaborador(
        ReglaRecepcionDocumental $regla,
        string $tipoDocumento,
        string $tipoEtiqueta,
        Model $origen,
        ?Colaborador $colaborador,
        ?string $fechaEvento,
        string $descripcion,
        ?int $generatedDocumentId = null,
        int $cantidad = 1,
    ): self {
        return new self(
            $regla,
            $tipoDocumento,
            $tipoEtiqueta,
            $origen,
            $generatedDocumentId,
            $colaborador?->id,
            $colaborador?->sucursal_principal_id,
            $colaborador?->nombreCompleto(),
            $colaborador?->numero_empleado,
            $colaborador?->puesto?->nombre,
            $colaborador?->sucursalPrincipal?->nombre,
            $fechaEvento,
            $descripcion,
            $cantidad,
        );
    }

    /** Llave de idempotencia (misma que el índice único rec_doc_renglon_unico). */
    public function clave(): string
    {
        return self::claveDe($this->regla->value, $this->origen->getMorphClass(), (int) $this->origen->getKey(), $this->tipoDocumento);
    }

    public static function claveDe(string $regla, string $origenType, int $origenId, string $tipo): string
    {
        return sprintf('%s|%s|%d|%s', $regla, $origenType, $origenId, $tipo);
    }

    /** Nombre visible del tipo documental (config/recepcion_documental.php → tipos). */
    public static function etiquetaTipo(string $clave): string
    {
        $etiqueta = config(sprintf('recepcion_documental.tipos.%s', $clave));

        return is_string($etiqueta) && $etiqueta !== '' ? $etiqueta : $clave;
    }
}
