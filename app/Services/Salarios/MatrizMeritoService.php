<?php

namespace App\Services\Salarios;

use App\Enums\BandaSalarial;
use App\Enums\NivelDesempeno;
use App\Models\MatrizMerito;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matriz de mérito: desempeño × posición en la banda = % de incremento SUGERIDO.
 * Nada aquí aplica aumentos; un cambio real pasa por CompensacionService.
 */
class MatrizMeritoService
{
    public const BONO = 'bono';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    public function vigente(): ?MatrizMerito
    {
        return MatrizMerito::query()->whereNull('vigente_hasta')->latest('id')->first();
    }

    /** @return Collection<int, MatrizMerito> */
    public function historial(int $limite = 10): Collection
    {
        return MatrizMerito::query()->with('usuario:id,name')->latest('id')->limit($limite)->get();
    }

    /** @param  array<mixed>  $celdas */
    public function guardar(array $celdas, ?string $motivo, User $usuario): MatrizMerito
    {
        $limpias = $this->validar($celdas);

        return DB::transaction(function () use ($limpias, $motivo, $usuario): MatrizMerito {
            MatrizMerito::query()->whereNull('vigente_hasta')->lockForUpdate()->update(['vigente_hasta' => now()->toDateString()]);

            $nueva = MatrizMerito::query()->create([
                'celdas' => $limpias,
                'vigente_desde' => now()->toDateString(),
                'motivo' => $motivo,
                'usuario_id' => $usuario->id,
            ]);

            $this->auditoria->registrar('salarios.matriz_actualizada', $nueva, $usuario);

            return $nueva;
        });
    }

    /**
     * @param  array<mixed>|null  $celdas
     * @return float|string|null porcentaje, 'bono' o null si la matriz no lo define
     */
    public function sugerencia(?array $celdas, NivelDesempeno $nivel, BandaSalarial $banda): float|string|null
    {
        $renglon = $celdas[$nivel->value] ?? null;
        $valor = is_array($renglon) ? ($renglon[$banda->value] ?? null) : null;

        return match (true) {
            $valor === self::BONO => self::BONO,
            is_numeric($valor) => (float) $valor,
            default => null,
        };
    }

    /**
     * @param  array<mixed>  $celdas
     * @return array<string, array<string, float|string>>
     */
    private function validar(array $celdas): array
    {
        $limpias = [];

        foreach (NivelDesempeno::cases() as $nivel) {
            $renglon = $celdas[$nivel->value] ?? null;

            foreach (BandaSalarial::cases() as $banda) {
                $valor = is_array($renglon) ? ($renglon[$banda->value] ?? null) : null;

                if ($valor === self::BONO) {
                    $limpias[$nivel->value][$banda->value] = self::BONO;
                } elseif (is_numeric($valor) && (float) $valor >= 0 && (float) $valor <= 100) {
                    $limpias[$nivel->value][$banda->value] = (float) $valor;
                } else {
                    throw ValidationException::withMessages([
                        'celdas' => sprintf('%s / %s debe ser un porcentaje de 0 a 100 o «Bono».', $nivel->etiqueta(), $banda->etiqueta()),
                    ]);
                }
            }
        }

        return $limpias;
    }
}
