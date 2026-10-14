<?php

namespace App\Services\ImportadorTrabajadores;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\IdentidadColaboradorService;
use App\Services\Compensacion\CompensacionService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Crea un colaborador HISTÓRICO desde una fila del importador de
 * trabajadores que no tuvo match (CLAUDE.md §74-81, cambio de regla de
 * negocio: antes el importador NUNCA creaba colaboradores).
 *
 * A propósito NO reutiliza App\Services\Colaboradores\AltaColaboradorService::registrar():
 * esa clase es para un ALTA NUEVA (dispara contrato, onboarding, checklist
 * documental y tareas) — aquí la persona YA trabaja en la empresa desde
 * antes de PEOPLE, el Excel solo lo confirma. Crea identidad + acceso,
 * nunca contrato ni onboarding ficticio.
 */
class ImportacionColaboradorService
{
    public function __construct(
        private readonly IdentidadColaboradorService $identidad,
        private readonly DocumentoStorageService $storage,
        private readonly CompensacionService $compensacion,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  name, apellidos?, fecha_ingreso, salario_diario, sucursal_principal_id?, puesto_id, departamento_id?
     */
    public function crear(array $datos, User $actor): Colaborador
    {
        $this->identidad->validarNoDuplicado($datos);

        return DB::transaction(function () use ($datos, $actor): Colaborador {
            $colaborador = Colaborador::query()->create([
                'name' => $datos['name'],
                'apellidos' => $datos['apellidos'] ?? null,
                'sucursal_principal_id' => $datos['sucursal_principal_id'] ?? null,
                'departamento_id' => $datos['departamento_id'] ?? null,
                'puesto_id' => $datos['puesto_id'],
                'fecha_ingreso' => $datos['fecha_ingreso'],
                'estatus' => EstadoUsuario::Activo,
                'alta_registrada_por' => $actor->id,
            ]);

            $colaborador->load('sucursalPrincipal.empresa');
            // Expediente: fija la carpeta del colaborador en el NAS desde el
            // primer momento, igual que un alta normal — degrada a "SIN
            // SUCURSAL" en el nombre de ruta si no tiene (remoto/corporativo,
            // CLAUDE.md §66-73), nunca falla por eso.
            $this->storage->asignarRutaBaseColaborador($colaborador);

            $this->compensacion->actualizarSalario(
                $colaborador,
                (float) $datos['salario_diario'],
                null,
                $actor,
                ['fuente' => 'importacion_excel_alta', 'motivo' => 'Salario inicial al crear el colaborador desde el importador.'],
            );

            $usuario = User::query()->create([
                'colaborador_id' => $colaborador->id,
                'name' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'email' => null,
                'password' => Hash::make(Str::random(40)),
            ]);
            $usuario->assignRole('colaborador');
            $colaborador->setRelation('user', $usuario);

            try {
                $this->auditoria->registrar('colaborador_creado_desde_importacion', $colaborador, $actor, [
                    'colaborador_id' => $colaborador->id,
                    'fecha_ingreso' => (string) $datos['fecha_ingreso'],
                    'sucursal_id' => $colaborador->sucursal_principal_id,
                    'puesto_id' => $colaborador->puesto_id,
                ]);
            } catch (Throwable $e) {
                // La auditoría nunca deshace la creación; solo se registra.
                report($e);
            }

            return $colaborador->refresh();
        });
    }
}
