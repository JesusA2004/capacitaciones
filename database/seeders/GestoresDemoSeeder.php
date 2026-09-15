<?php

namespace Database\Seeders;

use App\Enums\EstadoUsuario;
use App\Enums\TipoNodoComercial;
use App\Models\NodoComercial;
use App\Models\User;
use App\Services\MatrizComercial\MatrizComercialService;
use Illuminate\Database\Seeder;

/**
 * Asigna gestores DEMO a la mitad de las rutas activas de la matriz
 * comercial sin responsable (idempotente: nunca toca una ruta que ya
 * tiene `responsable_user_id`) — la otra mitad se deja sin cubrir a
 * propósito para poder demostrar las alertas de cobertura del tablero.
 *
 * EXCLUSIVO de desarrollo: en producción la asignación de responsables la
 * hace RH explícitamente, nunca un seeder. Requiere que ya existan
 * colaboradores activos con puesto (ver UsuarioDemoSeeder), por eso corre
 * después de él dentro de DemoSeeder.
 */
class GestoresDemoSeeder extends Seeder
{
    public function run(): void
    {
        $colaboradores = User::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereNotNull('puesto_id')
            ->orderBy('id')
            ->get(['id']);

        if ($colaboradores->isEmpty()) {
            return;
        }

        $rutasSinGestor = NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Ruta->value)
            ->where('activa', true)
            ->whereNull('responsable_user_id')
            ->orderBy('id')
            ->get();

        $matriz = app(MatrizComercialService::class);

        foreach ($rutasSinGestor as $indice => $ruta) {
            if ($indice % 2 !== 0) {
                continue;
            }

            $colaborador = $colaboradores[$indice % $colaboradores->count()];
            $matriz->asignarResponsable($ruta, $colaborador);
        }
    }
}
