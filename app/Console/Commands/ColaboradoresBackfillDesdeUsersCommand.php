<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill de la separación Usuario/Colaborador (ver
 * database/migrations/2026_09_17_09*): crea un Colaborador por cada cuenta
 * de acceso existente que todavía no tenga uno enlazado, copiando sus
 * columnas de persona/empleo, resuelve `jefe_id` (antes apuntaba a
 * `users.id`, ahora a `colaboradores.id`), migra `sucursal_user` a
 * `sucursal_colaborador` y rellena `colaborador_id` en las tablas de la
 * Parte A (employee_documents, movimientos_laborales, birthday_greetings,
 * nodos_comerciales, user_nodo_comercial).
 *
 * Idempotente: cada paso solo toca filas que aún no tienen su columna nueva
 * llena, así que correrlo varias veces (o después de un backfill parcial) es
 * seguro. Transaccional: todo o nada. No borra ni modifica ninguna columna
 * legacy — ver el docblock de create_colaboradores_table.
 */
class ColaboradoresBackfillDesdeUsersCommand extends Command
{
    protected $signature = 'people:backfill-colaboradores';

    protected $description = 'Crea un Colaborador por cada cuenta de acceso existente sin colaborador_id y rellena las columnas colaborador_id pendientes (separación Usuario/Colaborador)';

    /**
     * Columnas de persona/empleo que hoy siguen en `users` y se copian tal
     * cual a `colaboradores` (mismos nombres en ambas tablas — ver
     * create_colaboradores_table). `jefe_id` se excluye a propósito: se
     * resuelve aparte porque apuntaba a `users.id` y ahora debe apuntar a
     * `colaboradores.id`.
     *
     * @var array<int, string>
     */
    private const COLUMNAS_PERSONA = [
        'name', 'apellidos', 'genero', 'numero_empleado', 'telefono', 'foto_path',
        'expediente_storage_path', 'sucursal_principal_id', 'departamento_id', 'puesto_id',
        'fecha_ingreso', 'estatus', 'estatus_imss', 'fecha_alta_imss',
        'periodo_prueba_inicio', 'periodo_prueba_fin',
        'incorporacion_decision', 'incorporacion_decidida_por', 'incorporacion_decidida_en', 'incorporacion_motivo_rechazo',
        'fecha_nacimiento', 'curp', 'rfc', 'nss', 'domicilio', 'correo_personal',
        'contacto_emergencia_nombre', 'contacto_emergencia_telefono',
        'aviso_privacidad_aceptado', 'aviso_privacidad_aceptado_en',
        'consentimiento_datos_aceptado', 'consentimiento_datos_aceptado_en', 'avisos_registrado_por_id',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('colaboradores') || ! Schema::hasColumn('users', 'colaborador_id')) {
            $this->error('Faltan las migraciones de la separación Usuario/Colaborador — corre `php artisan migrate` primero.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            $this->crearColaboradoresFaltantes();
            $this->migrarSucursalesAdicionales();
            $this->rellenarColaboradorIdEnTablasPersona();
        });

        $this->info('Backfill completado.');

        return self::SUCCESS;
    }

    private function crearColaboradoresFaltantes(): void
    {
        $usuarios = User::withTrashed()->whereNull('colaborador_id')->get();

        if ($usuarios->isEmpty()) {
            $this->info('Colaboradores: nada que hacer (todas las cuentas ya tienen colaborador_id).');

            return;
        }

        $this->info("Colaboradores: creando {$usuarios->count()} colaborador(es) desde users...");

        /** @var array<int, int> $mapaUserAColaborador clave: users.id original, valor: colaboradores.id nuevo */
        $mapaUserAColaborador = [];

        foreach ($usuarios as $usuario) {
            $datos = collect($usuario->getAttributes())->only(self::COLUMNAS_PERSONA)->all();
            $datos['deleted_at'] = $usuario->deleted_at;

            $colaborador = Colaborador::create($datos);

            $usuario->forceFill(['colaborador_id' => $colaborador->id])->saveQuietly();

            $mapaUserAColaborador[$usuario->id] = $colaborador->id;
        }

        // Segunda pasada: jefe_id apuntaba a users.id, ahora debe apuntar a
        // colaboradores.id. Se resuelve después de crear todos los
        // colaboradores porque un jefe puede aparecer más adelante en la
        // misma colección que su subordinado.
        foreach ($usuarios as $usuario) {
            $jefeUserIdOriginal = $usuario->getAttributes()['jefe_id'] ?? null;

            if ($jefeUserIdOriginal === null) {
                continue;
            }

            $jefeColaboradorId = $mapaUserAColaborador[$jefeUserIdOriginal]
                ?? User::withTrashed()->where('id', $jefeUserIdOriginal)->value('colaborador_id');

            if ($jefeColaboradorId === null) {
                $this->warn("  Colaborador de users.id={$usuario->id}: no se pudo resolver su jefe (users.id={$jefeUserIdOriginal} sin colaborador).");

                continue;
            }

            Colaborador::whereKey($mapaUserAColaborador[$usuario->id])->update(['jefe_id' => $jefeColaboradorId]);
        }

        $this->info('Colaboradores: listo.');
    }

    /**
     * `sucursal_user` (sucursales adicionales autorizadas) se conserva sin
     * borrar pero deja de ser la fuente real — se migra a
     * `sucursal_colaborador`. No depende de qué usuarios se hayan procesado
     * en esta corrida: resuelve el colaborador de cada fila directamente
     * contra `users.colaborador_id` ya actualizado arriba.
     */
    private function migrarSucursalesAdicionales(): void
    {
        if (! Schema::hasTable('sucursal_user')) {
            return;
        }

        $filas = DB::table('sucursal_user')->get();

        if ($filas->isEmpty()) {
            $this->info('Sucursales adicionales: nada que migrar (sucursal_user vacía).');

            return;
        }

        $migradas = 0;

        foreach ($filas as $fila) {
            $colaboradorId = User::withTrashed()->whereKey($fila->user_id)->value('colaborador_id');

            if ($colaboradorId === null) {
                continue;
            }

            DB::table('sucursal_colaborador')->updateOrInsert(
                ['colaborador_id' => $colaboradorId, 'sucursal_id' => $fila->sucursal_id],
                ['created_at' => $fila->created_at ?? now(), 'updated_at' => now()],
            );
            $migradas++;
        }

        $this->info("Sucursales adicionales: {$migradas} de {$filas->count()} fila(s) migrada(s) a sucursal_colaborador.");
    }

    /**
     * Rellena `colaborador_id` (o `responsable_colaborador_id`) en las
     * tablas de la Parte A a partir del `user_id` legacy, uniendo contra
     * `users.colaborador_id` ya resuelto. UPDATE...JOIN es idempotente por
     * construcción: solo toca filas donde la columna nueva sigue nula.
     */
    private function rellenarColaboradorIdEnTablasPersona(): void
    {
        $tablas = [
            // Parte A.
            ['tabla' => 'employee_documents', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'movimientos_laborales', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'birthday_greetings', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'nodos_comerciales', 'columna_user' => 'responsable_user_id', 'columna_colaborador' => 'responsable_colaborador_id'],
            ['tabla' => 'user_nodo_comercial', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            // Parte B (ver 2026_09_18_090000_add_colaborador_id_a_tablas_persona_parte_b.php).
            ['tabla' => 'solicitudes_internas', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'solicitudes_internas', 'columna_user' => 'colaborador_objetivo_id', 'columna_colaborador' => 'objetivo_colaborador_id'],
            ['tabla' => 'solicitudes_vacaciones', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'altas_digitales', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'incorporacion_invitaciones', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'generated_documents', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'official_format_generations', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
            ['tabla' => 'document_extractions', 'columna_user' => 'user_id', 'columna_colaborador' => 'colaborador_id'],
        ];

        foreach ($tablas as $definicion) {
            $tabla = $definicion['tabla'];
            $columnaUser = $definicion['columna_user'];
            $columnaColaborador = $definicion['columna_colaborador'];

            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columnaColaborador)) {
                $this->warn("  {$tabla}.{$columnaColaborador}: tabla/columna no existe todavía, se omite.");

                continue;
            }

            $actualizadas = DB::table($tabla)
                ->join('users', 'users.id', '=', "{$tabla}.{$columnaUser}")
                ->whereNull("{$tabla}.{$columnaColaborador}")
                ->whereNotNull('users.colaborador_id')
                ->update(["{$tabla}.{$columnaColaborador}" => DB::raw('users.colaborador_id')]);

            $this->info("  {$tabla}.{$columnaColaborador}: {$actualizadas} fila(s) rellenada(s).");
        }
    }
}
