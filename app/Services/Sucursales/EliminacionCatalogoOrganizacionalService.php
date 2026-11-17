<?php

namespace App\Services\Sucursales;

use App\Enums\EstadoVacante;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use Illuminate\Validation\ValidationException;

/**
 * Borrado (lógico) de Sucursales y Departamentos con revisión de
 * dependencias, igual que ya hacen Empresas (con sucursales) y Puestos: no
 * se elimina un catálogo que todavía sostiene personas, plantilla
 * autorizada o vacantes abiertas — se dejaría a colaboradores y vacantes
 * apuntando a algo «eliminado». Primero hay que mover o cerrar lo que
 * depende de él.
 */
class EliminacionCatalogoOrganizacionalService
{
    /**
     * @return list<string> Qué impide borrarla (vacío = se puede).
     */
    public function dependenciasDeSucursal(Sucursal $sucursal): array
    {
        return $this->describir([
            'colaborador(es) con esta sucursal principal' => Colaborador::query()->where('sucursal_principal_id', $sucursal->id)->count(),
            'colaborador(es) con esta sucursal adicional' => $sucursal->usuariosAdicionales()->count(),
            'plaza(s) autorizada(s) en headcount' => HeadcountTarget::query()->where('sucursal_id', $sucursal->id)->where('plantilla_autorizada', '>', 0)->count(),
            'vacante(s) abierta(s)' => Vacante::query()->where('sucursal_id', $sucursal->id)->whereIn('estado', EstadoVacante::valoresAbiertos())->count(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function dependenciasDeDepartamento(Departamento $departamento): array
    {
        return $this->describir([
            'colaborador(es)' => Colaborador::query()->where('departamento_id', $departamento->id)->count(),
            'puesto(s)' => Puesto::query()->where('departamento_id', $departamento->id)->count(),
            'plaza(s) autorizada(s) en headcount' => HeadcountTarget::query()->where('departamento_id', $departamento->id)->where('plantilla_autorizada', '>', 0)->count(),
            'vacante(s) abierta(s)' => Vacante::query()->where('departamento_id', $departamento->id)->whereIn('estado', EstadoVacante::valoresAbiertos())->count(),
        ]);
    }

    public function eliminarSucursal(Sucursal $sucursal): void
    {
        $this->bloquearSiHay($this->dependenciasDeSucursal($sucursal), 'la sucursal');
        $sucursal->delete();
    }

    public function eliminarDepartamento(Departamento $departamento): void
    {
        $this->bloquearSiHay($this->dependenciasDeDepartamento($departamento), 'el departamento');
        $departamento->delete();
    }

    /**
     * @param  list<string>  $dependencias
     */
    private function bloquearSiHay(array $dependencias, string $que): void
    {
        if ($dependencias !== []) {
            throw ValidationException::withMessages([
                'eliminar' => sprintf('No se puede eliminar %s: todavía tiene %s. Muévelos o ciérralos primero.', $que, implode(', ', $dependencias)),
            ]);
        }
    }

    /**
     * @param  array<string, int>  $conteos
     * @return list<string>
     */
    private function describir(array $conteos): array
    {
        $lista = [];

        foreach ($conteos as $etiqueta => $total) {
            if ($total > 0) {
                $lista[] = sprintf('%d %s', $total, $etiqueta);
            }
        }

        return $lista;
    }
}
