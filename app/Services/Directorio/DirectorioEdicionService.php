<?php

namespace App\Services\Directorio;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DatosExpedienteService;
use Illuminate\Support\Facades\DB;

/**
 * Edición rápida del Directorio (por fila, sin abrir el expediente): correo
 * y teléfono corporativos, puesto, sucursal y departamento. El jefe directo
 * NO se edita: sale del organigrama (JefeDirectoService).
 *
 * Reglas (backend, web y cualquier cliente futuro):
 *  - permiso `directorio.editar` (sembrado solo para super_admin);
 *  - nadie edita su propia fila, ni super_admin («Mi expediente: solo
 *    solicitar corrección»);
 *  - alcance del módulo «Expedientes» sobre ese colaborador.
 *
 * La escritura es la MISMA del expediente (DatosExpedienteService): mismo
 * movimiento laboral, jefe derivado y bitácora del modelo.
 */
class DirectorioEdicionService
{
    /** Campos de contacto que el directorio edita (pestaña «Datos personales»). */
    public const CAMPOS_CONTACTO = ['correo_corporativo', 'telefono_corporativo'];

    /** Campos laborales que el directorio edita (pestaña «Datos laborales»), sin sueldo. */
    public const CAMPOS_LABORALES = ['sucursal_principal_id', 'departamento_id', 'puesto_id', 'motivo'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly DatosExpedienteService $datosExpediente,
    ) {}

    /** ¿Ve los controles de edición en el directorio? */
    public function puedeEditarDirectorio(User $usuario): bool
    {
        return $usuario->can('directorio.editar');
    }

    public function puedeEditar(User $usuario, Colaborador $colaborador): bool
    {
        return $this->puedeEditarDirectorio($usuario)
            && $usuario->colaborador_id !== $colaborador->id
            && $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    /**
     * Ids de las filas que este usuario puede editar (para mostrar el botón
     * solo donde aplica; el backend vuelve a validar al guardar).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function idsEditables(User $usuario, array $ids): array
    {
        if (! $this->puedeEditarDirectorio($usuario) || $ids === []) {
            return [];
        }

        $editables = [];

        foreach (Colaborador::query()->whereKey($ids)->get() as $colaborador) {
            if ($this->puedeEditar($usuario, $colaborador)) {
                $editables[] = (int) $colaborador->id;
            }
        }

        return $editables;
    }

    /**
     * @param  array<string, mixed>  $datos  validados por ActualizarFilaDirectorioRequest
     */
    public function actualizar(Colaborador $colaborador, array $datos, User $actor): void
    {
        $contacto = array_intersect_key($datos, array_flip(self::CAMPOS_CONTACTO));
        $laborales = array_intersect_key($datos, array_flip(self::CAMPOS_LABORALES));

        DB::transaction(function () use ($colaborador, $contacto, $laborales, $actor): void {
            $this->datosExpediente->actualizarPersonales($colaborador, $contacto);

            // Sin `sueldo_mensual`: el directorio nunca toca la compensación.
            if (array_diff(array_keys($laborales), ['motivo']) !== []) {
                $this->datosExpediente->actualizarLaborales($colaborador, [
                    ...$laborales,
                    'motivo' => $laborales['motivo'] ?? 'Edición rápida desde el Directorio.',
                ], $actor);
            }
        });
    }
}
