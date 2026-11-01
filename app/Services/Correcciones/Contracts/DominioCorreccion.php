<?php

namespace App\Services\Correcciones\Contracts;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Correcciones\CampoCorregible;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que cada dominio aporta a CorreccionService. Las reglas de negocio siguen en
 * los servicios existentes: el dominio solo traduce y delega.
 */
interface DominioCorreccion
{
    public function clave(): string;

    public function sujeto(int $id, bool $bloquear = false): ?Model;

    public function etiquetaSujeto(Model $sujeto): string;

    public function colaboradorDe(Model $sujeto): ?Colaborador;

    public function puedeLeer(User $actor, Model $sujeto): bool;

    public function puedeEscribir(User $actor, Model $sujeto): bool;

    /**
     * @return list<CampoCorregible>
     */
    public function campos(Model $sujeto, User $actor): array;

    /**
     * Valores vigentes de TODOS los campos, visibles o no: base de la huella.
     *
     * @return array<string, string|null>
     */
    public function valoresActuales(Model $sujeto): array;

    /**
     * Normaliza y valida lo capturado. Lanza ValidationException.
     *
     * @param  array<string, mixed>  $valores  Solo campos editables por el actor.
     * @param  array{tipo?: string|null, vigente_desde?: string|null}  $opciones
     * @return array<string, string|null>
     */
    public function normalizar(Model $sujeto, array $valores, array $opciones, User $actor): array;

    /**
     * Texto legible de un valor nuevo ya normalizado.
     */
    public function legible(string $campo, ?string $valor): ?string;

    /**
     * Datos derivados que cambian con la corrección (para antes/después).
     *
     * @param  array<string, string|null>  $valores
     * @return list<array{campo: string, etiqueta: string, antes: string|null, despues: string|null, confidencial: bool}>
     */
    public function derivados(Model $sujeto, array $valores): array;

    /**
     * Qué registros toca o deja intactos. No escribe.
     *
     * @param  array<string, string|null>  $valores
     * @param  array{tipo?: string|null, vigente_desde?: string|null}  $opciones
     * @return list<array{tipo: string, etiqueta: string, detalle: string, accion: string}>
     */
    public function afectados(Model $sujeto, array $valores, array $opciones): array;

    /**
     * Aplica dentro de la transacción de CorreccionService delegando en el
     * servicio del dominio.
     *
     * @param  array<string, string|null>  $valores
     * @param  array{tipo?: string|null, vigente_desde?: string|null}  $opciones
     * @return array{modo?: string, tipo?: string|null, vigente_desde?: string|null, resultado?: Model|null}
     */
    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array;

    /**
     * Opciones del acto (tipos de cambio de sueldo). Vacío en los demás dominios.
     *
     * @return list<array{valor: string, etiqueta: string, descripcion: string}>
     */
    public function tiposDeCambio(Model $sujeto): array;
}
