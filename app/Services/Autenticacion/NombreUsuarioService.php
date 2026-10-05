<?php

namespace App\Services\Autenticacion;

use App\Models\Colaborador;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Nombre de usuario de MR. LANA PEOPLE (docs/AUTENTICACION.md): el
 * identificador oficial para iniciar sesión en web y app, en lugar del
 * correo (muchos colaboradores, sobre todo gestores, no tienen correo).
 *
 *   Base = primer nombre + apellido paterno COMPLETO, en «Tipo Título» y sin
 *   acentos: JESUS ENRIQUE / ARIZMENDI → «Jesus Arizmendi»; DE LA CRUZ como
 *   paterno → «Juan De la Cruz». El materno nunca se usa.
 *   Colisión = sufijo numérico determinístico: «Jesus Arizmendi2», «…3».
 *
 * La comparación siempre es sin distinguir mayúsculas/acentos y con espacios
 * colapsados; el índice UNIQUE de `users.username` es la garantía final
 * contra carreras (crearConUsuario() reintenta con el siguiente sufijo).
 */
class NombreUsuarioService
{
    /** Partículas de apellidos compuestos: van en minúscula salvo al inicio. */
    private const PARTICULAS = ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y', 'SAN', 'SANTA', 'VAN', 'VON', 'DA', 'DI', 'DOS'];

    private const INTENTOS = 5;

    /**
     * Username base a partir de los campos YA SEPARADOS (Excel: «Nombre» y
     * «Apellido paterno»). No adivina apellidos a partir de un nombre completo.
     */
    public function base(?string $nombre, ?string $apellidoPaterno): string
    {
        $primerNombre = array_slice($this->palabras($nombre), 0, 1);
        $partes = array_filter([$this->titulo($primerNombre), $this->titulo($this->palabras($apellidoPaterno))]);

        return $partes !== [] ? implode(' ', $partes) : 'Usuario';
    }

    /**
     * Para cuentas creadas fuera del Excel (alta digital, administración,
     * QR…), donde solo existe `apellidos` combinado: el paterno es la
     * primera palabra más las partículas que la anteceden («De la Cruz
     * Hernández» → «De la Cruz»).
     */
    public function baseDesdeApellidos(?string $nombre, ?string $apellidos): string
    {
        return $this->base($nombre, $this->apellidoPaternoDe($apellidos));
    }

    public function apellidoPaternoDe(?string $apellidos): string
    {
        $paterno = [];

        foreach ($this->palabras($apellidos) as $palabra) {
            $paterno[] = $palabra;

            if (! in_array(Str::upper($palabra), self::PARTICULAS, true)) {
                break;
            }
        }

        return implode(' ', $paterno);
    }

    /** Lo que el usuario escribió: sin espacios a los lados ni dobles. */
    public function normalizar(string $entrada): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $entrada)));
    }

    /** Llave de comparación: normalizado, sin acentos, en minúsculas. */
    public function clave(string $entrada): string
    {
        return Str::lower(Str::ascii($this->normalizar($entrada)));
    }

    public function buscar(string $entrada): ?User
    {
        $clave = $this->clave($entrada);

        return $clave === '' ? null : User::query()->whereRaw('LOWER(username) = ?', [$clave])->first();
    }

    /**
     * Primer username libre para esa base: «base», «base2», «base3»…
     * Determinístico (nunca aleatorio). `$reservados` son usernames ya
     * propuestos en memoria (dry-run) que todavía no existen en BD.
     *
     * @param  array<string, true>  $reservados  clave() => true
     */
    public function disponible(string $base, array $reservados = []): string
    {
        $ocupados = User::withTrashed()
            ->whereRaw('LOWER(username) LIKE ?', [$this->clave($base).'%'])
            ->pluck('username')
            ->mapWithKeys(fn ($u) => [$this->clave((string) $u) => true])
            ->all() + $reservados;

        for ($n = 1; ; $n++) {
            $candidato = $n === 1 ? $base : $base.$n;

            if (! isset($ocupados[$this->clave($candidato)])) {
                return $candidato;
            }
        }
    }

    public function ocupado(string $username): bool
    {
        return User::withTrashed()->whereRaw('LOWER(username) = ?', [$this->clave($username)])->exists();
    }

    /**
     * Crea la cuenta asignando el primer username libre dentro de una
     * transacción. Si otra petición tomó ese username entre la consulta y
     * el INSERT (índice UNIQUE), reintenta con el siguiente sufijo.
     *
     * @template T of User
     *
     * @param  callable(string): T  $crear  recibe el username a usar
     * @return T
     */
    public function crearConUsuario(string $base, callable $crear): User
    {
        for ($intento = 1; $intento <= self::INTENTOS; $intento++) {
            $username = $this->disponible($base);

            try {
                return DB::transaction(fn () => $crear($username));
            } catch (Throwable $e) {
                // Solo se reintenta si el choque fue justo por el username.
                if (! $this->ocupado($username)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException(sprintf('No se pudo asignar un nombre de usuario libre para «%s».', $base));
    }

    /**
     * Username para una cuenta nueva que no lo trae explícito (hook
     * `creating` de User): sale del colaborador enlazado (fuente
     * autoritativa del nombre) o, sin colaborador, de la propia cuenta.
     */
    public function paraCuenta(User $usuario): string
    {
        $colaborador = $usuario->colaborador_id !== null
            ? Colaborador::withTrashed()->where('id', $usuario->colaborador_id)->first(['id', 'name', 'apellidos'])
            : null;

        $base = $colaborador !== null
            ? $this->baseDesdeApellidos($colaborador->name, $colaborador->apellidos)
            : $this->baseDesdeApellidos($usuario->name, $usuario->apellidos);

        return $this->disponible($base);
    }

    /**
     * Palabras en ASCII (sin acentos, «Ñ» → «N») y solo letras: el login
     * compara igual, así «Jesús» y «Jesus» son la misma cuenta.
     *
     * @return list<string>
     */
    private function palabras(?string $valor): array
    {
        $limpio = (string) preg_replace('/[^A-Za-z]+/', ' ', Str::ascii((string) $valor));

        return array_values(array_filter(explode(' ', $limpio), fn (string $p) => $p !== ''));
    }

    /**
     * @param  list<string>  $palabras
     */
    private function titulo(array $palabras): string
    {
        $resultado = [];

        foreach ($palabras as $i => $palabra) {
            $minuscula = Str::lower($palabra);
            $resultado[] = $i > 0 && in_array(Str::upper($palabra), self::PARTICULAS, true) ? $minuscula : ucfirst($minuscula);
        }

        return implode(' ', $resultado);
    }
}
