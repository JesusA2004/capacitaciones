<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Perfilado\PerfiladorConsultas;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Perfilado de pantallas web (solo local/testing, ver docs/RENDIMIENTO.md):
 * ejecuta cada ruta GET como un usuario real, dentro del mismo proceso, y
 * mide tiempo total, número de consultas, tiempo SQL, consultas repetidas y
 * tamaño del payload Inertia. Reporta de la más lenta a la más rápida.
 *
 *   php artisan people:perfilar --email=admin@mrlana.test
 *   php artisan people:perfilar --ruta=dashboard --ruta=administracion.usuarios.index --detalle
 */
class PerfilarRutasCommand extends Command
{
    protected $signature = 'people:perfilar
        {--email= : Cuenta con la que se abren las pantallas (por defecto, el primer super_admin)}
        {--ruta=* : Nombre de ruta GET a medir (se puede repetir)}
        {--repeticiones=3 : Corridas por ruta; se reporta la mediana}
        {--detalle : Muestra las consultas más repetidas de cada ruta}';

    protected $description = 'Mide tiempo, consultas SQL, duplicadas y payload Inertia de las pantallas principales (solo local/testing)';

    private const RUTAS = [
        'dashboard',
        'administracion.empresas.index',
        'administracion.usuarios.index',
        'administracion.sucursales.index',
        'administracion.departamentos.index',
        'administracion.puestos.index',
        'administracion.roles.index',
        'administracion.jerarquia-puestos.index',
        'administracion.app-releases.index',
        'rh.expedientes.index',
        'rh.solicitudes.index',
        'rh.vacantes.index',
        'rh.candidatos.index',
        'rh.campanas.index',
        'rh.incorporacion.invitaciones.index',
        'rh.formatos.index',
        'rh.plantillas.index',
        'rh.formatos.catalogo.index',
        'rh.cumpleanos.index',
        'rh.aniversarios.index',
        'reportes.index',
    ];

    public function handle(Kernel $kernel, PerfiladorConsultas $perfilador): int
    {
        if (app()->isProduction()) {
            $this->error('people:perfilar no se ejecuta en producción.');

            return self::FAILURE;
        }

        $usuario = $this->usuario();

        if ($usuario === null) {
            $this->error('No se encontró la cuenta a usar (usa --email=).');

            return self::FAILURE;
        }

        $rutas = $this->option('ruta') ?: self::RUTAS;
        $repeticiones = max(1, (int) $this->option('repeticiones'));
        $filas = [];

        $this->info(sprintf('Perfilando %d ruta(s) como %s (%d corrida(s) c/u)…', count($rutas), $usuario->email, $repeticiones));

        foreach ($rutas as $nombreRuta) {
            $mediciones = [];

            for ($i = 0; $i < $repeticiones; $i++) {
                $mediciones[] = $this->medir($kernel, $perfilador, $usuario, (string) $nombreRuta);
            }

            usort($mediciones, fn (array $a, array $b) => $a['ms'] <=> $b['ms']);
            $mediana = $mediciones[intdiv(count($mediciones), 2)];
            $filas[] = ['ruta' => $nombreRuta, ...$mediana];
        }

        usort($filas, fn (array $a, array $b) => $b['ms'] <=> $a['ms']);

        $this->table(
            ['Ruta', 'HTTP', 'ms', 'Consultas', 'SQL ms', 'Duplicadas', 'Payload KB'],
            array_map(fn (array $f) => [$f['ruta'], $f['status'], $f['ms'], $f['consultas'], $f['sql_ms'], $f['duplicadas'], $f['payload_kb']], $filas),
        );

        if ($this->option('detalle')) {
            foreach ($filas as $fila) {
                if ($fila['top_duplicadas'] === []) {
                    continue;
                }

                $this->newLine();
                $this->line("<fg=blue>{$fila['ruta']}</> — consultas más repetidas:");
                foreach ($fila['top_duplicadas'] as $sql => $veces) {
                    $this->line(sprintf('  %3dx  %s', $veces, mb_strimwidth($sql, 0, 160, '…')));
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{status: int|string, ms: float, consultas: int, sql_ms: float, duplicadas: int, payload_kb: float, top_duplicadas: array<string, int>}
     */
    private function medir(Kernel $kernel, PerfiladorConsultas $perfilador, User $usuario, string $nombreRuta): array
    {
        try {
            $peticion = Request::create(route($nombreRuta, [], false), 'GET');
        } catch (Throwable $e) {
            return ['status' => 'ruta?', 'ms' => 0.0, 'consultas' => 0, 'sql_ms' => 0.0, 'duplicadas' => 0, 'payload_kb' => 0.0, 'top_duplicadas' => []];
        }

        Auth::guard('web')->setUser($usuario->fresh() ?? $usuario);

        $perfilador->iniciar();
        $inicio = hrtime(true);
        $respuesta = $kernel->handle($peticion);
        $ms = round((hrtime(true) - $inicio) / 1e6, 1);
        $perfilador->detener();
        $kernel->terminate($peticion, $respuesta);

        return [
            'status' => $respuesta->getStatusCode(),
            'ms' => $ms,
            ...$perfilador->resumen(),
            'payload_kb' => round($this->tamanoPayload((string) $respuesta->getContent()) / 1024, 1),
        ];
    }

    /**
     * Inertia 3 embebe el objeto de página como JSON en
     * <script data-page="app" type="application/json">…</script>.
     */
    private function tamanoPayload(string $html): int
    {
        if (preg_match('#<script[^>]*data-page="app"[^>]*>(.*?)</script>#s', $html, $m) === 1) {
            return strlen($m[1]);
        }

        return strlen($html);
    }

    private function usuario(): ?User
    {
        $email = $this->option('email');

        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        return User::query()->role('super_admin')->whereNotNull('colaborador_id')->orderBy('id')->first();
    }
}
