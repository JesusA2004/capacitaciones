<?php

namespace App\Services\Sucursales;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Services\Colaboradores\FotoColaboradorService;

/**
 * Datos del detalle de una sucursal que no son headcount (ese vive en
 * HeadcountService): quién la dirige y cómo se reparte su gente.
 *
 * No existe "responsable por sucursal" como figura aparte: quien responde
 * por la sucursal es su Gerente de Sucursal (puesto real del organigrama).
 */
class SucursalDetalleService
{
    public const PUESTO_GERENTE = 'Gerente de Sucursal';

    public function __construct(private readonly FotoColaboradorService $fotos) {}

    /**
     * @return array{id: int, nombre: string, puesto: string, foto_url: string|null}|null
     */
    public function gerente(Sucursal $sucursal): ?array
    {
        $gerente = Colaborador::query()
            ->where('sucursal_principal_id', $sucursal->id)
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereHas('puesto', fn ($q) => $q->where('nombre', self::PUESTO_GERENTE))
            ->orderBy('fecha_ingreso')
            ->first();

        if ($gerente === null) {
            return null;
        }

        return [
            'id' => $gerente->id,
            'nombre' => $gerente->nombreCompleto(),
            'puesto' => self::PUESTO_GERENTE,
            'foto_url' => $this->fotos->url($gerente),
        ];
    }

    /**
     * Colaboradores activos por departamento (para la gráfica de dona).
     *
     * @return list<array{etiqueta: string, valor: int}>
     */
    public function porDepartamento(Sucursal $sucursal): array
    {
        $conteo = [];

        $colaboradores = Colaborador::query()
            ->where('sucursal_principal_id', $sucursal->id)
            ->where('estatus', EstadoUsuario::Activo->value)
            ->with('departamento:id,nombre')
            ->get(['id', 'departamento_id']);

        foreach ($colaboradores as $colaborador) {
            $nombre = $colaborador->departamento !== null ? $colaborador->departamento->nombre : 'Sin departamento';
            $conteo[$nombre] = ($conteo[$nombre] ?? 0) + 1;
        }

        arsort($conteo);

        $filas = [];

        foreach ($conteo as $nombre => $valor) {
            $filas[] = ['etiqueta' => (string) $nombre, 'valor' => $valor];
        }

        return $filas;
    }
}
