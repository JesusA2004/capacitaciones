<?php

namespace App\Services\Correcciones\Dominios;

use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Datos de un documento generado. La corrección ocurre al emitir la versión
 * (render fuera de transacción): este dominio solo da acceso al historial y nunca
 * aplica nada desde CorreccionService::aplicar().
 */
class DocumentoGeneradoDominio extends DominioBase
{
    public function clave(): string
    {
        return 'documento_generado';
    }

    public function sujeto(int $id, bool $bloquear = false): ?Model
    {
        return GeneratedDocument::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function etiquetaSujeto(Model $sujeto): string
    {
        return $sujeto instanceof GeneratedDocument ? (string) $sujeto->titulo : '';
    }

    public function colaboradorDe(Model $sujeto): ?Colaborador
    {
        return $sujeto instanceof GeneratedDocument ? $sujeto->colaborador : null;
    }

    public function puedeLeer(User $actor, Model $sujeto): bool
    {
        return $actor->can('ver', $sujeto);
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        return $actor->can('regenerar', $sujeto);
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        return [];
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        throw ValidationException::withMessages(['valores' => 'Los datos de un documento se corrigen desde «Editar datos» y se emiten como nueva versión.']);
    }
}
