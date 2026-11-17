<?php

namespace App\Services\NineBox;

use App\Models\NineBoxConfiguracion;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configuración de la Nine Box (pesos, metas, cortes): la vigente es la
 * versión más reciente de `nine_box_configuraciones`; sin versiones se usan
 * los valores de config/nine_box.php. Guardar SIEMPRE inserta una versión
 * nueva con quién y por qué (nunca se edita ni se borra una versión).
 *
 * @phpstan-type ValoresNineBox array{
 *     desempeno: array{
 *         pesos: array{colocacion: float, recuperacion: float, mora: float},
 *         meta_colocacion_mensual: float|null,
 *         meta_recuperacion_mensual: float|null,
 *         mora_excelente: float,
 *         mora_critica: float,
 *     },
 *     potencial: array{
 *         pesos: array{evaluaciones: float, calibracion: float},
 *         ventana_evaluaciones_meses: int,
 *         valores_calibracion: array{bajo: float, medio: float, alto: float},
 *     },
 *     cortes: array{
 *         desempeno: array{medio: float, alto: float},
 *         potencial: array{medio: float, alto: float},
 *     },
 * }
 */
class ConfiguracionNineBoxService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * @return ValoresNineBox
     */
    public function valores(): array
    {
        $version = $this->versionVigente();

        return $this->normalizar($version !== null ? $version->valores : []);
    }

    /**
     * Valores vigentes + datos de la versión (para la pantalla).
     *
     * @return array{valores: ValoresNineBox, version: int|null, guardada_por: string|null, guardada_en: string|null, motivo: string|null, historial: list<array{id: int, guardada_por: string|null, guardada_en: string|null, motivo: string|null}>}
     */
    public function vigente(): array
    {
        $version = $this->versionVigente();
        $historial = NineBoxConfiguracion::query()->with('guardadaPor:id,name')
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn (NineBoxConfiguracion $c): array => [
                'id' => $c->id,
                'guardada_por' => $c->guardadaPor?->name,
                'guardada_en' => $c->created_at?->toIso8601String(),
                'motivo' => $c->motivo,
            ])->values()->all();

        return [
            'valores' => $this->normalizar($version !== null ? $version->valores : []),
            'version' => $version?->id,
            'guardada_por' => $version?->guardadaPor?->name,
            'guardada_en' => $version?->created_at?->toIso8601String(),
            'motivo' => $version?->motivo,
            'historial' => array_values($historial),
        ];
    }

    /**
     * Guarda una versión nueva (ya validada en forma por el FormRequest;
     * aquí se valida la coherencia entre campos).
     *
     * @param  array<string, mixed>  $valores
     *
     * @throws ValidationException
     */
    public function guardar(array $valores, string $motivo, User $usuario): NineBoxConfiguracion
    {
        $normalizados = $this->normalizar($valores);
        $errores = [];

        if (array_sum($normalizados['desempeno']['pesos']) <= 0) {
            $errores['valores.desempeno.pesos'] = 'Al menos un indicador de desempeño debe tener peso mayor a cero.';
        }

        if (array_sum($normalizados['potencial']['pesos']) <= 0) {
            $errores['valores.potencial.pesos'] = 'Las evaluaciones técnicas o la calibración deben tener peso mayor a cero.';
        }

        if ($normalizados['desempeno']['mora_excelente'] >= $normalizados['desempeno']['mora_critica']) {
            $errores['valores.desempeno.mora_critica'] = 'La mora crítica debe ser mayor que la mora excelente.';
        }

        foreach (['desempeno' => 'desempeño', 'potencial' => 'potencial'] as $eje => $nombre) {
            if ($normalizados['cortes'][$eje]['medio'] >= $normalizados['cortes'][$eje]['alto']) {
                $errores[sprintf('valores.cortes.%s.alto', $eje)] = sprintf('En %s, el corte «Alto» debe ser mayor que el corte «Medio».', $nombre);
            }
        }

        $calibracion = $normalizados['potencial']['valores_calibracion'];

        if (! ($calibracion['bajo'] < $calibracion['medio'] && $calibracion['medio'] < $calibracion['alto'])) {
            $errores['valores.potencial.valores_calibracion'] = 'Los puntajes de calibración deben ir de menor a mayor (bajo < medio < alto).';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $version = DB::transaction(fn (): NineBoxConfiguracion => NineBoxConfiguracion::query()->create([
            'valores' => $normalizados,
            'motivo' => $motivo,
            'guardada_por' => $usuario->id,
        ]));

        // La bitácora ya atrapa sus propias fallas: nunca deshace el guardado.
        $this->auditoria->registrar('nine_box_configurada', $version, $usuario, ['motivo' => $motivo]);

        return $version;
    }

    private function versionVigente(): ?NineBoxConfiguracion
    {
        return NineBoxConfiguracion::query()->with('guardadaPor:id,name')->orderByDesc('id')->first();
    }

    /**
     * Mezcla lo guardado con los valores por defecto y fuerza los tipos.
     *
     * @param  array<mixed>  $guardado
     * @return ValoresNineBox
     */
    public function normalizar(array $guardado): array
    {
        $base = (array) config('nine_box.valores', []);
        $valor = function (string $ruta, float $defecto) use ($guardado, $base): float {
            $dato = data_get($guardado, $ruta, data_get($base, $ruta, $defecto));

            return is_numeric($dato) ? max(0.0, (float) $dato) : $defecto;
        };
        $opcional = function (string $ruta) use ($guardado, $base): ?float {
            $dato = array_key_exists('desempeno', $guardado) ? data_get($guardado, $ruta) : data_get($base, $ruta);

            return is_numeric($dato) && (float) $dato > 0 ? (float) $dato : null;
        };

        return [
            'desempeno' => [
                'pesos' => [
                    'colocacion' => $valor('desempeno.pesos.colocacion', 40),
                    'recuperacion' => $valor('desempeno.pesos.recuperacion', 35),
                    'mora' => $valor('desempeno.pesos.mora', 25),
                ],
                'meta_colocacion_mensual' => $opcional('desempeno.meta_colocacion_mensual'),
                'meta_recuperacion_mensual' => $opcional('desempeno.meta_recuperacion_mensual'),
                'mora_excelente' => min(100.0, $valor('desempeno.mora_excelente', 5)),
                'mora_critica' => min(100.0, $valor('desempeno.mora_critica', 25)),
            ],
            'potencial' => [
                'pesos' => [
                    'evaluaciones' => $valor('potencial.pesos.evaluaciones', 60),
                    'calibracion' => $valor('potencial.pesos.calibracion', 40),
                ],
                'ventana_evaluaciones_meses' => max(1, min(24, (int) $valor('potencial.ventana_evaluaciones_meses', 6))),
                'valores_calibracion' => [
                    'bajo' => min(100.0, $valor('potencial.valores_calibracion.bajo', 25)),
                    'medio' => min(100.0, $valor('potencial.valores_calibracion.medio', 60)),
                    'alto' => min(100.0, $valor('potencial.valores_calibracion.alto', 90)),
                ],
            ],
            'cortes' => [
                'desempeno' => [
                    'medio' => min(100.0, $valor('cortes.desempeno.medio', 40)),
                    'alto' => min(100.0, $valor('cortes.desempeno.alto', 70)),
                ],
                'potencial' => [
                    'medio' => min(100.0, $valor('cortes.potencial.medio', 40)),
                    'alto' => min(100.0, $valor('cortes.potencial.alto', 70)),
                ],
            ],
        ];
    }
}
