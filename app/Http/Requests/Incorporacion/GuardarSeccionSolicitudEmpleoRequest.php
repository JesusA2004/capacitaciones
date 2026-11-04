<?php

namespace App\Http\Requests\Incorporacion;

use App\Services\Incorporacion\SolicitudEmpleo\CatalogoSolicitudEmpleo;
use App\Support\FechaMexico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guardar UNA sección de la Solicitud de empleo / Estudio socioeconómico
 * (app del candidato y web de RH). Solo valida FORMATO (tipo, largo,
 * catálogo): guardar parcial está permitido y lo obligatorio lo decide la
 * completitud (PerfilIngresoService). Las reglas salen del catálogo único.
 */
class GuardarSeccionSolicitudEmpleoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function seccion(): string
    {
        $seccion = $this->route('seccion');

        return is_string($seccion) ? $seccion : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = [
            'datos' => ['present', 'array'],
            'no_aplica' => ['nullable', 'array'],
            'no_aplica.*' => ['string', 'max:60'],
        ];

        foreach (CatalogoSolicitudEmpleo::camposDeSeccion($this->seccion()) as $clave => $campo) {
            if ($campo['almacen'] === 'base') {
                continue;
            }

            $reglas["datos.{$clave}"] = $this->reglasDe($campo['tipo'], $campo['opciones'], $campo['min'], $campo['max']);

            if ($campo['tipo'] === 'multiselect') {
                $reglas["datos.{$clave}.*"] = ['string', Rule::in(CatalogoSolicitudEmpleo::valoresDe($campo['opciones']))];
            }
        }

        $lista = CatalogoSolicitudEmpleo::listaDeSeccion($this->seccion());

        if ($lista !== null) {
            $reglas["datos.{$lista['clave']}"] = ['nullable', 'array', 'max:'.$lista['maximo']];
            $reglas["datos.{$lista['clave']}.*"] = ['array'];

            if ($lista['bandera_vacia'] !== null) {
                $reglas["datos.{$lista['bandera_vacia']}"] = ['nullable', 'boolean'];
            }

            foreach ($lista['campos'] as $clave => $campo) {
                $reglas["datos.{$lista['clave']}.*.{$clave}"] = $this->reglasDe(
                    (string) $campo['tipo'],
                    $campo['opciones'] ?? null,
                    isset($campo['min']) ? (int) $campo['min'] : null,
                    isset($campo['max']) ? (int) $campo['max'] : null,
                );
            }
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $atributos = [];

        foreach (CatalogoSolicitudEmpleo::camposDeSeccion($this->seccion()) as $clave => $campo) {
            $atributos["datos.{$clave}"] = $campo['etiqueta'];
            $atributos["datos.{$clave}.*"] = $campo['etiqueta'];
        }

        $lista = CatalogoSolicitudEmpleo::listaDeSeccion($this->seccion());

        if ($lista !== null) {
            $atributos["datos.{$lista['clave']}"] = $lista['etiqueta'];

            foreach ($lista['campos'] as $clave => $campo) {
                $atributos["datos.{$lista['clave']}.*.{$clave}"] = sprintf('%s: %s', $lista['singular'], mb_strtolower((string) $campo['etiqueta']));
            }
        }

        return $atributos;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'datos.present' => 'Envía los datos de la sección (puede ir vacío).',
            '*.in' => 'Elige una opción válida en :attribute.',
            'datos.*.*.date_format' => 'Escribe :attribute con el formato AAAA-MM (mes y año).',
            'datos.*.max' => 'Escribe como máximo :max caracteres en :attribute.',
        ];
    }

    /**
     * Valor de una sección listo para el servicio.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $datos = $this->validated('datos');

        return is_array($datos) ? $datos : [];
    }

    /**
     * @return list<string>|null
     */
    public function noAplica(): ?array
    {
        if (! $this->has('no_aplica')) {
            return null;
        }

        $valor = $this->validated('no_aplica');

        return is_array($valor) ? array_values(array_map('strval', $valor)) : [];
    }

    /**
     * @param  class-string|null  $opciones
     * @return list<mixed>
     */
    private function reglasDe(string $tipo, ?string $opciones, ?int $min, ?int $max): array
    {
        return match ($tipo) {
            'entero' => array_values(array_filter(['nullable', 'integer', $min !== null ? 'min:'.$min : null, $max !== null ? 'max:'.$max : null])),
            'moneda' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'booleano' => ['nullable', 'boolean'],
            'select' => ['nullable', 'string', Rule::in(CatalogoSolicitudEmpleo::valoresDe($opciones))],
            'multiselect' => ['nullable', 'array'],
            'fecha' => ['nullable', 'date', 'before:'.FechaMexico::hoy()],
            'mes' => ['nullable', 'date_format:Y-m'],
            default => ['nullable', 'string', 'max:'.($max ?? 191)],
        };
    }
}
