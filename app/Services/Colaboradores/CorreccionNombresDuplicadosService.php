<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Support\NombrePersona;
use Illuminate\Support\Facades\DB;

/**
 * Corrige colaboradores cuyo «nombre» trae también los apellidos al final
 * («NANCY NALLELY LOPEZ GARCIA» + apellidos «Lopez Garcia»), capturados así
 * antes de que el modelo lo impidiera (ver Concerns\NombreSinApellidos).
 * Sincroniza la cuenta (users.name) enlazada y deja cada cambio en la
 * bitácora de auditoría. Nunca toca apellidos ni otros datos.
 */
class CorreccionNombresDuplicadosService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * @return list<array{id: int, numero_empleado: string|null, nombre_actual: string, nombre_nuevo: string, apellidos: string, cuenta_actual: string|null, cuenta_nueva: string|null}>
     */
    public function detectar(): array
    {
        $filas = [];

        Colaborador::withTrashed()
            ->with('user:id,colaborador_id,name,apellidos')
            ->select(['id', 'numero_empleado', 'name', 'apellidos'])
            ->orderBy('id')
            ->chunkById(500, function ($colaboradores) use (&$filas): void {
                foreach ($colaboradores as $colaborador) {
                    $nombre = (string) $colaborador->name;
                    $apellidos = (string) $colaborador->apellidos;

                    if (! NombrePersona::terminaConApellidos($nombre, $apellidos)) {
                        continue;
                    }

                    $nuevo = NombrePersona::sinApellidos($nombre, $apellidos);
                    $cuenta = $colaborador->user;
                    $cuentaNueva = $cuenta !== null && $this->cuentaRequiereCorreccion($cuenta, $nombre) ? $nuevo : null;

                    $filas[] = [
                        'id' => $colaborador->id,
                        'numero_empleado' => $colaborador->numero_empleado,
                        'nombre_actual' => $nombre,
                        'nombre_nuevo' => $nuevo,
                        'apellidos' => $apellidos,
                        'cuenta_actual' => $cuenta?->name,
                        'cuenta_nueva' => $cuentaNueva,
                    ];
                }
            });

        return $filas;
    }

    /**
     * Aplica la corrección de una fila detectada (idempotente).
     *
     * @param  array{id: int, numero_empleado: string|null, nombre_actual: string, nombre_nuevo: string, apellidos: string, cuenta_actual: string|null, cuenta_nueva: string|null}  $fila
     */
    public function corregir(array $fila): void
    {
        DB::transaction(function () use ($fila): void {
            $colaborador = Colaborador::withTrashed()->where('id', $fila['id'])->first();

            if ($colaborador === null || ! NombrePersona::terminaConApellidos($colaborador->name, $colaborador->apellidos)) {
                return;
            }

            $colaborador->forceFill(['name' => $fila['nombre_nuevo']])->save();

            $cuenta = User::withTrashed()->where('colaborador_id', $colaborador->id)->first();

            if ($cuenta !== null && $this->cuentaRequiereCorreccion($cuenta, $fila['nombre_actual'])) {
                $cuenta->forceFill(['name' => $fila['nombre_nuevo']])->save();
            }

            $this->auditoria->registrar('nombre_duplicado_corregido', $colaborador, null, [
                'nombre_anterior' => $fila['nombre_actual'],
                'nombre_nuevo' => $fila['nombre_nuevo'],
                'apellidos' => $fila['apellidos'],
                'cuenta_sincronizada' => $cuenta !== null && $fila['cuenta_nueva'] !== null,
                'comando' => 'colaboradores:corregir-nombres-duplicados',
            ]);
        });
    }

    /**
     * La cuenta se sincroniza si repite el mismo nombre con apellidos del
     * colaborador o si ella misma trae sus apellidos dentro del nombre.
     */
    private function cuentaRequiereCorreccion(User $cuenta, string $nombreColaborador): bool
    {
        return NombrePersona::normalizar($cuenta->name) === NombrePersona::normalizar($nombreColaborador)
            || NombrePersona::terminaConApellidos($cuenta->name, $cuenta->apellidos);
    }
}
