<?php

use App\Services\Autenticacion\NombreUsuarioService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El login deja de ser por correo (docs/AUTENTICACION.md):
 *  - `username` («Jesus Arizmendi») UNIQUE, identificador oficial;
 *  - `email` pasa a ser opcional (NULL permitido; el UNIQUE se conserva y
 *    MySQL/MariaDB/SQLite admiten varios NULL);
 *  - `debe_cambiar_contrasena`: contraseña temporal → cambio obligatorio.
 * Las cuentas existentes reciben su username con la misma regla que las
 * nuevas (primer nombre + apellido paterno, sufijo 2, 3… si se repite), en
 * orden de id para que el resultado sea determinístico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->after('colaborador_id');
            $table->boolean('debe_cambiar_contrasena')->default(false)->after('password');
        });

        $servicio = new NombreUsuarioService;
        $usados = [];
        $cuentas = DB::table('users')
            ->leftJoin('colaboradores', 'colaboradores.id', '=', 'users.colaborador_id')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.apellidos', 'colaboradores.name as c_name', 'colaboradores.apellidos as c_apellidos']);

        foreach ($cuentas as $cuenta) {
            $base = $cuenta->c_name !== null
                ? $servicio->baseDesdeApellidos($cuenta->c_name, $cuenta->c_apellidos)
                : $servicio->baseDesdeApellidos($cuenta->name, $cuenta->apellidos);

            $n = 1;

            while (isset($usados[$servicio->clave($n === 1 ? $base : $base.$n)])) {
                $n++;
            }

            $username = $n === 1 ? $base : $base.$n;
            $usados[$servicio->clave($username)] = true;
            DB::table('users')->where('id', $cuenta->id)->update(['username' => $username]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->nullable(false)->change();
            $table->unique('username', 'users_username_unique');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->dropColumn(['username', 'debe_cambiar_contrasena']);
            // `email` se deja nullable: volverlo NOT NULL fallaría con las
            // cuentas que ya se crearon sin correo.
        });
    }
};
