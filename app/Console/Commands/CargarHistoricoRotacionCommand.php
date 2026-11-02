<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Rotacion\CargaHistoricoRotacionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Carga el histórico individual de bajas desde un archivo VERSIONADO (por
 * defecto el anexo del Reporte de Rotación de Personal 2026 de RH,
 * claude/rotacion/bajas_historicas_2026.csv) con la misma regla que la carga
 * desde la pantalla (BajasHistoricasImportService). Ver docs/ROTACION.md.
 *
 * Sin --aplicar es una vista previa: no escribe nada. Con --aplicar guarda
 * todo o nada, auditado en importaciones_excel (tipo bajas_historicas: quién,
 * cuándo, motivo, servidor). Es idempotente: una segunda corrida no encuentra
 * nada nuevo y no escribe. Nunca borra ni modifica bajas.
 *
 *   php artisan rotacion:cargar-historico                      # vista previa
 *   php artisan rotacion:cargar-historico --aplicar --force    # en producción
 */
class CargarHistoricoRotacionCommand extends Command
{
    protected $signature = 'rotacion:cargar-historico
        {archivo? : CSV o Excel con el formato del histórico (por defecto el de config rotacion.historico_2026.archivo)}
        {--esperado= : JSON con los totales publicados para conciliar (por defecto el de config rotacion.historico_2026.esperado)}
        {--aplicar : Guardar las bajas nuevas (sin esto solo es vista previa)}
        {--motivo=Histórico de bajas 2026 (Reporte de Rotación de Personal 2026 de RH) : Motivo que queda en la auditoría}
        {--usuario= : Correo o id del usuario que firma la carga (por defecto el primer super_admin)}
        {--force : Confirmar la carga en producción sin preguntar}';

    /** @var list<string> */
    protected $aliases = ['rotacion:cargar-historico-2026'];

    protected $description = 'Carga (o solo revisa) el histórico individual de bajas de rotación y lo concilia con los totales publicados por RH.';

    public function handle(CargaHistoricoRotacionService $carga): int
    {
        $ruta = $this->ruta($this->argument('archivo'), 'rotacion.historico_2026.archivo');

        if ($ruta === null || ! is_file($ruta)) {
            $this->error(sprintf('No existe el archivo %s.', $ruta ?? '(sin archivo)'));

            return self::FAILURE;
        }

        try {
            $lectura = $carga->leer($ruta);
        } catch (ValidationException $e) {
            $this->error(implode(' ', $e->validator->errors()->all()));

            return self::FAILURE;
        }

        $filas = $lectura['filas'];
        $estados = $carga->porEstado($filas);
        $this->info(sprintf('Archivo: %s (%d filas)', $ruta, count($filas)));
        $this->table(['Nuevas', 'Sin cambio (ya cargadas)', 'Duplicado con baja del sistema', 'Errores'], [[$estados['nuevo'], $estados['sin_cambio'], $estados['duplicado_sistema'], $estados['error']]]);
        $this->hallazgos($filas);

        $esperado = $this->esperado();
        $periodo = $esperado !== null && is_string($esperado['desde'] ?? null) && is_string($esperado['hasta'] ?? null)
            ? [CarbonImmutable::parse($esperado['desde']), CarbonImmutable::parse($esperado['hasta'])]
            : null;

        if ($esperado !== null && $periodo !== null) {
            $this->line('');
            $this->info('Conciliación antes de cargar (Esperado = totales publicados; Archivo = filas sin error; Rotación = lo que hoy cuenta el tablero, solo el histórico individual o todas las fuentes):');
            $this->conciliar($carga, $esperado, [
                'Archivo' => $carga->totalesArchivo($filas),
                'Rotación (solo histórico)' => $carga->totalesRotacion($periodo[0], $periodo[1], 'historico'),
                'Rotación (todas las fuentes)' => $carga->totalesRotacion($periodo[0], $periodo[1], 'todas'),
            ]);
        }

        if (! $this->option('aplicar')) {
            $this->line('');
            $this->comment('Vista previa: no se escribió nada. Para cargar, agrega --aplicar.');

            return $estados['error'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($estados['error'] > 0) {
            $this->error('Hay filas con error: no se carga nada.');

            return self::FAILURE;
        }

        if ($estados['nuevo'] + $estados['duplicado_sistema'] === 0) {
            $this->info('Sin cambios: todas las bajas del archivo ya estaban cargadas.');

            return self::SUCCESS;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Estás en producción: confirma la carga agregando --force.');

            return self::FAILURE;
        }

        $usuario = $this->usuario();

        if ($usuario === null) {
            $this->error('No encontré al usuario que firma la carga: indica --usuario=correo@empresa (o crea el super_admin).');

            return self::FAILURE;
        }

        try {
            $importacion = $carga->aplicar($ruta, $lectura, $usuario, (string) $this->option('motivo'), [
                'origen' => 'comando rotacion:cargar-historico',
                'archivo' => $this->relativa($ruta),
                'servidor' => (string) gethostname(),
                'entorno' => (string) app()->environment(),
            ]);
        } catch (ValidationException $e) {
            $this->error(implode(' ', $e->validator->errors()->all()));

            return self::FAILURE;
        }

        if ($importacion === null) {
            $this->info('Sin cambios: todas las bajas del archivo ya estaban cargadas.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Cargadas %d bajas históricas (importación #%d, firmada por %s).', $importacion->filas_aplicadas, $importacion->id, $usuario->email));

        if ($esperado !== null && $periodo !== null) {
            $this->line('');
            $this->info('Conciliación después de cargar:');
            $this->conciliar($carga, $esperado, [
                'Rotación (solo histórico)' => $carga->totalesRotacion($periodo[0], $periodo[1], 'historico'),
                'Rotación (todas las fuentes)' => $carga->totalesRotacion($periodo[0], $periodo[1], 'todas'),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Errores (bloquean) y avisos agrupados; con -v, cada aviso.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    private function hallazgos(array $filas): void
    {
        $avisos = [];

        foreach ($filas as $fila) {
            foreach ((array) ($fila['errores'] ?? []) as $error) {
                $this->error(sprintf('Fila %d (%s): %s', (int) $fila['fila'], (string) $fila['nombre'], (string) $error));
            }

            foreach ((array) ($fila['avisos'] ?? []) as $aviso) {
                $tipo = (string) preg_replace('/«[^»]*»/u', '«…»', (string) $aviso);
                $avisos[$tipo] = ($avisos[$tipo] ?? 0) + 1;

                if ($this->output->isVerbose()) {
                    $this->line(sprintf('  Fila %d (%s): %s', (int) $fila['fila'], (string) $fila['nombre'], (string) $aviso));
                }
            }

            if ($fila['estado'] === 'duplicado_sistema') {
                $this->warn(sprintf('Fila %d (%s, %s): ya existe como baja del sistema; se liga y cuenta la del sistema (con SU causal).', (int) $fila['fila'], (string) $fila['nombre'], (string) $fila['fecha_baja']));
            }
        }

        if ($avisos !== []) {
            $this->table(['Aviso (se carga y queda para revisión)', 'Filas'], array_map(fn (string $a, int $n): array => [$a, $n], array_keys($avisos), $avisos));
        }
    }

    /**
     * @param  array<string, mixed>  $esperado
     * @param  array<string, array<string, mixed>>  $obtenidos
     */
    private function conciliar(CargaHistoricoRotacionService $carga, array $esperado, array $obtenidos): void
    {
        $filas = $carga->comparar($esperado, $obtenidos);
        $this->table(['Indicador', 'Esperado', ...array_keys($obtenidos), '¿Cuadra?'], array_map(fn (array $f): array => [$f[0], $f[1], ...$f[2], $f[3] ? 'Sí' : 'NO'], $filas));
        $fallas = count(array_filter($filas, fn (array $f): bool => ! $f[3]));
        $fallas === 0 ? $this->info('Todos los indicadores cuadran.') : $this->warn(sprintf('%d indicador(es) no cuadran (ver «NO»).', $fallas));

        // Los descuadres con el Excel de totales son los mismos para cualquier
        // fuente: se listan una vez.
        $descuadres = array_unique(array_merge(...array_values(array_map(fn (array $t): array => array_map('strval', (array) ($t['discrepancias_excel'] ?? [])), $obtenidos))));

        foreach ($descuadres as $d) {
            $this->line(sprintf('  Calidad del dato: descuadre con el Excel de totales (NO se suma, solo se informa): %s', $d));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function esperado(): ?array
    {
        $ruta = $this->ruta($this->option('esperado'), 'rotacion.historico_2026.esperado');

        if ($ruta === null || ! is_file($ruta)) {
            $this->comment('Sin archivo de totales esperados: no se concilia.');

            return null;
        }

        $datos = json_decode((string) file_get_contents($ruta), true);

        return is_array($datos) ? $datos : null;
    }

    private function ruta(mixed $valor, string $config): ?string
    {
        $ruta = is_string($valor) && $valor !== '' ? $valor : config($config);

        if (! is_string($ruta) || $ruta === '') {
            return null;
        }

        return preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $ruta) === 1 ? $ruta : base_path($ruta);
    }

    private function relativa(string $ruta): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $normal = str_replace('\\', '/', $ruta);

        return str_starts_with($normal, $base) ? substr($normal, strlen($base)) : $normal;
    }

    private function usuario(): ?User
    {
        $valor = $this->option('usuario');

        if (is_string($valor) && $valor !== '') {
            return User::query()->where(is_numeric($valor) ? 'id' : 'email', $valor)->first();
        }

        return User::role('super_admin')->orderBy('id')->first();
    }
}
