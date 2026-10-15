<?php

namespace App\Models;

use App\Enums\CondicionActivoResponsiva;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un equipo descrito en una responsiva (lo que el gerente o RH capturó).
 *
 * @property int $id
 * @property int $responsiva_id
 * @property int $orden
 * @property string $descripcion
 * @property string|null $marca
 * @property string|null $modelo
 * @property string|null $numero_serie
 * @property string|null $color
 * @property int $cantidad
 * @property CondicionActivoResponsiva $estado
 * @property string|null $caracteristicas
 * @property string|null $accesorios
 */
class ResponsivaActivo extends Model
{
    protected $table = 'responsiva_activos';

    protected $fillable = [
        'responsiva_id', 'orden', 'descripcion', 'marca', 'modelo', 'numero_serie', 'color', 'cantidad', 'estado', 'caracteristicas', 'accesorios',
    ];

    protected function casts(): array
    {
        return [
            'estado' => CondicionActivoResponsiva::class,
            'orden' => 'integer',
            'cantidad' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Responsiva, $this>
     */
    public function responsiva(): BelongsTo
    {
        return $this->belongsTo(Responsiva::class);
    }

    /**
     * Características capturadas como «Nombre: valor», una por renglón.
     * Un renglón sin «:» se imprime completo como descripción.
     *
     * @return list<array{nombre: string, valor: string}>
     */
    public function caracteristicasLista(): array
    {
        $lineas = preg_split('/\R/u', (string) $this->caracteristicas) ?: [];
        $resultado = [];

        foreach ($lineas as $linea) {
            $linea = trim($linea);

            if ($linea === '') {
                continue;
            }

            $partes = explode(':', $linea, 2);
            $resultado[] = count($partes) === 2 && trim($partes[0]) !== '' && trim($partes[1]) !== ''
                ? ['nombre' => trim($partes[0]), 'valor' => trim($partes[1])]
                : ['nombre' => 'Característica', 'valor' => $linea];
        }

        return $resultado;
    }
}
