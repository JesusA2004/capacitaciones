<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\ModoCorreccion;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Correcciones\CampoCorregible;
use App\Services\Correcciones\Contracts\DominioCorreccion;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * Comportamiento común: lee los campos de config/correcciones.php, calcula el
 * modo efectivo y valida. Cada dominio restringe por estado y delega al aplicar.
 */
abstract class DominioBase implements DominioCorreccion
{
    /**
     * Modo efectivo por estado del registro. Null: el configurado.
     *
     * @return array{0: ModoCorreccion, 1: string}|null
     */
    protected function restriccion(Model $sujeto, string $campo): ?array
    {
        return null;
    }

    protected function esVisible(Model $sujeto, string $campo, User $actor): bool
    {
        return true;
    }

    /**
     * Opciones de un campo de selección.
     *
     * @return list<array{valor: string, etiqueta: string}>
     */
    protected function opcionesDe(string $campo): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function definiciones(Model $sujeto): array
    {
        /** @var array<string, array<string, mixed>> $campos */
        $campos = config(sprintf('correcciones.dominios.%s.campos', $this->clave()), []);

        return $campos;
    }

    protected function valor(Model $sujeto, string $campo): mixed
    {
        return $sujeto->getAttribute($campo);
    }

    public function tiposDeCambio(Model $sujeto): array
    {
        return [];
    }

    public function derivados(Model $sujeto, array $valores): array
    {
        return [];
    }

    public function legible(string $campo, ?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        foreach ($this->opcionesDe($campo) as $opcion) {
            if ($opcion['valor'] === $valor) {
                return $opcion['etiqueta'];
            }
        }

        return $valor;
    }

    public function valoresActuales(Model $sujeto): array
    {
        $valores = [];

        foreach (array_keys($this->definiciones($sujeto)) as $campo) {
            $valores[$campo] = $this->comoTexto($this->valor($sujeto, $campo));
        }

        return $valores;
    }

    public function campos(Model $sujeto, User $actor): array
    {
        $enApi = request()->is('api/*');
        $lista = [];

        foreach ($this->definiciones($sujeto) as $campo => $definicion) {
            if (! $this->esVisible($sujeto, $campo, $actor) || ($enApi && ($definicion['api'] ?? true) === false)) {
                continue;
            }

            $modo = ModoCorreccion::from((string) $definicion['modo']);
            $explicacion = isset($definicion['explicacion']) ? (string) $definicion['explicacion'] : null;
            $restriccion = $this->restriccion($sujeto, $campo);

            if ($restriccion !== null) {
                [$modo, $explicacion] = $restriccion;
            }

            $valor = $this->comoTexto($this->valor($sujeto, $campo));
            /** @var list<mixed> $reglas */
            $reglas = (array) ($definicion['reglas'] ?? []);
            $lista[] = new CampoCorregible(
                campo: $campo,
                etiqueta: (string) $definicion['etiqueta'],
                tipo: (string) $definicion['tipo'],
                modo: $modo,
                valor: $valor,
                legible: $this->legibleDeTipo((string) $definicion['tipo'], $campo, $valor),
                permitido: $actor->can((string) $definicion['permiso']),
                confidencial: (bool) ($definicion['confidencial'] ?? false),
                explicacion: $explicacion,
                opciones: $this->opcionesDe($campo),
                reglas: $reglas,
            );
        }

        return $lista;
    }

    public function normalizar(Model $sujeto, array $valores, array $opciones, User $actor): array
    {
        $reglas = [];
        $etiquetas = [];

        foreach ($this->definiciones($sujeto) as $campo => $definicion) {
            if (array_key_exists($campo, $valores)) {
                $reglas[$campo] = $definicion['reglas'] ?? ['nullable'];
                $etiquetas[$campo] = mb_strtolower((string) $definicion['etiqueta']);
            }
        }

        // Solo se valida lo que cambia: un dato histórico con formato viejo no
        // impide corregir otro campo.
        $limpios = [];

        foreach ($valores as $campo => $valor) {
            $limpios[$campo] = $this->limpiar($campo, $valor);
        }

        $datos = Validator::make($limpios, $reglas, [], $etiquetas)->validate();
        $resultado = [];

        foreach ($limpios as $campo => $valor) {
            if (array_key_exists($campo, $datos)) {
                $resultado[$campo] = $this->comoTexto($this->canonico($campo, $datos[$campo]));
            }
        }

        return $resultado;
    }

    protected function limpiar(string $campo, mixed $valor): mixed
    {
        if (in_array($campo, ['curp', 'rfc'], true) && is_string($valor)) {
            return mb_strtoupper(trim($valor));
        }

        return is_string($valor) ? trim($valor) : $valor;
    }

    /** Forma canónica para comparar: dinero a 2 decimales, fechas Y-m-d. */
    protected function canonico(string $campo, mixed $valor): mixed
    {
        $tipo = (string) ($this->definicion($campo)['tipo'] ?? 'texto');

        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($tipo) {
            'moneda', 'numero' => number_format((float) $valor, 2, '.', ''),
            'entero' => (string) (int) $valor,
            'fecha' => substr((string) $valor, 0, 10),
            'fecha_hora' => substr(str_replace('T', ' ', (string) $valor), 0, 16),
            default => $valor,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function definicion(string $campo): array
    {
        /** @var array<string, mixed> $definicion */
        $definicion = config(sprintf('correcciones.dominios.%s.campos.%s', $this->clave(), $campo), ['tipo' => 'moneda']);

        return $definicion;
    }

    protected function comoTexto(mixed $valor): ?string
    {
        $texto = match (true) {
            $valor instanceof BackedEnum => (string) $valor->value,
            $valor instanceof DateTimeInterface => $valor->format('H:i:s') === '00:00:00' ? $valor->format('Y-m-d') : $valor->format('Y-m-d H:i'),
            is_scalar($valor) => trim((string) $valor),
            default => '',
        };

        return $texto === '' ? null : $texto;
    }

    protected function legibleDeTipo(string $tipo, string $campo, ?string $valor): ?string
    {
        return $tipo === 'seleccion' ? $this->legible($campo, $valor) : $valor;
    }

    protected function esPropio(User $actor, ?Colaborador $colaborador): bool
    {
        return $colaborador !== null && $actor->colaborador_id === $colaborador->id;
    }

    protected function dinero(float $monto): string
    {
        return sprintf('$%s', number_format($monto, 2));
    }
}
