<?php

namespace App\Rules;

use App\Support\NombrePersona;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El campo «nombre» no debe traer los apellidos al final (se capturan en su
 * propio campo). Misma comparación que NombrePersona: sin distinguir
 * mayúsculas ni acentos.
 */
class NombreSinApellidos implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $datos = [];

    public function __construct(private readonly string $campoApellidos = 'apellidos') {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->datos = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $apellidos = $this->datos[$this->campoApellidos] ?? null;

        if (is_string($value) && is_string($apellidos) && NombrePersona::terminaConApellidos($value, $apellidos)) {
            $fail(sprintf('El nombre no debe incluir los apellidos: escribe solo el nombre (p. ej. «%s») y los apellidos en su propio campo.', NombrePersona::sinApellidos($value, $apellidos)));
        }
    }
}
