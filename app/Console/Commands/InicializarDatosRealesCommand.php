<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Services\DocumentosMaestros\CoberturaDocumentalService;
use Database\Seeders\PreguntasFiltroCandidatoSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquesta, DESPUÉS de importar MR_LANA_PEOPLE_CARGA_INICIAL.xlsx, los
 * comandos existentes que dejan los datos reales listos para operar.
 * Idempotente: cada paso ya lo es por sí mismo, así que repetirlo no
 * duplica. Nunca corre migrate:fresh ni DemoSeeder, nunca borra registros y
 * nunca mueve archivos del NAS (solo escribe y borra un archivo de prueba
 * para confirmar que el disco responde).
 *
 * Semántica de fallos:
 *   - pasos CRÍTICOS (NAS escribible, organigrama): si fallan se aborta, porque
 *     los siguientes dependen de ellos;
 *   - el resto: si falla se reporta y se CONTINÚA con el siguiente; al final
 *     el comando sale con error y lista qué repetir.
 *
 *   php artisan people:inicializar-datos-reales
 *   php artisan people:inicializar-datos-reales --omitir=descriptivos --omitir=formatos
 */
class InicializarDatosRealesCommand extends Command
{
    protected $signature = 'people:inicializar-datos-reales
        {--omitir=* : Clave(s) de paso a omitir: nas, organigrama, jefes, descriptivos, formatos, catalogos, validar-maestros, permisos, vacantes}
        {--ruta-descriptivos= : Carpeta de los PDF de descriptivos (por defecto config descriptivos.carpeta = docs/descriptivos)}
        {--ruta-formatos= : Carpeta de los formatos jurídicos originales (por defecto config documentos_maestros.carpeta_fuente = docs/formatos_fuente)}
        {--sin-carga-inicial : Correr aunque todavía no haya colaboradores importados}';

    protected $description = 'Tras la carga inicial: NAS, organigrama, jefes, descriptivos, formatos jurídicos, catálogos, permisos y vacantes (idempotente, sin borrar nada).';

    /**
     * Clave => [título, crítico].
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const PASOS = [
        'nas' => ['Verificar NAS (escritura de prueba + expedientes:verificar-storage)', true],
        'organigrama' => ['Sincronizar organigrama (people:sincronizar-organigrama)', true],
        'jefes' => ['Recalcular jefes directos (organigrama:sincronizar-jefes)', false],
        'descriptivos' => ['Importar descriptivos de puesto (people:importar-descriptivos)', false],
        'formatos' => ['Importar formatos jurídicos (people:importar-formatos-juridicos)', false],
        'catalogos' => ['Catálogos técnicos que dependen de puestos', false],
        'validar-maestros' => ['Validar documentos maestros (people:validar-documentos-maestros)', false],
        'permisos' => ['Sincronizar permisos (people:sincronizar-permisos)', false],
        'vacantes' => ['Sincronizar vacantes (people:sincronizar-vacantes)', false],
    ];

    public function handle(): int
    {
        $omitir = array_map(
            fn (mixed $clave): string => Str::lower(trim((string) $clave)),
            (array) $this->option('omitir'),
        );
        $desconocidas = array_diff($omitir, array_keys(self::PASOS));

        if ($desconocidas !== []) {
            $this->components->error(sprintf(
                'Paso(s) desconocido(s) en --omitir: %s. Válidos: %s.',
                implode(', ', $desconocidas),
                implode(', ', array_keys(self::PASOS)),
            ));

            return self::FAILURE;
        }

        if (! $this->option('sin-carga-inicial') && ! Colaborador::query()->exists()) {
            $this->components->error('No hay colaboradores en la base: primero sube MR_LANA_PEOPLE_CARGA_INICIAL.xlsx en Configuración → Importar datos → Carga inicial PEOPLE.');
            $this->line('  Si de verdad quieres correrlo sin la carga, repite con <options=bold>--sin-carga-inicial</>.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Inicialización de datos reales de PEOPLE.');
        $this->line('  Pasos críticos (NAS, organigrama): si fallan se aborta. El resto: si falla se reporta y se continúa.');

        $total = count(self::PASOS);
        $numero = 0;
        $fallidos = [];
        $inicioTotal = microtime(true);

        foreach (self::PASOS as $clave => [$titulo, $critico]) {
            $numero++;
            $this->newLine();
            $this->line(sprintf('<fg=blue;options=bold>Paso %d/%d</> · %s <fg=gray>[%s]</>', $numero, $total, $titulo, $clave));

            if (in_array($clave, $omitir, true)) {
                $this->components->twoColumnDetail($titulo, '<fg=yellow>OMITIDO</>');

                continue;
            }

            $inicio = microtime(true);
            $error = null;

            try {
                $exito = $this->ejecutar($clave);
            } catch (Throwable $e) {
                $exito = false;
                $error = $e->getMessage();
            }

            $duracion = sprintf('%.1f s', microtime(true) - $inicio);

            if ($exito) {
                $this->components->twoColumnDetail($titulo, sprintf('<fg=gray>%s</> <fg=green;options=bold>OK</>', $duracion));

                continue;
            }

            $this->components->twoColumnDetail($titulo, sprintf('<fg=gray>%s</> <fg=red;options=bold>FALLÓ</>', $duracion));

            if ($error !== null) {
                $this->components->error($error);
            }

            $fallidos[] = $clave;

            if ($critico) {
                $this->components->error(sprintf('El paso «%s» es crítico: se aborta. Corrige la causa y repite el comando (es seguro repetirlo).', $clave));

                return self::FAILURE;
            }

            $this->components->warn('Se continúa con el siguiente paso.');
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Tiempo total</>', sprintf('%.1f s', microtime(true) - $inicioTotal));

        if ($fallidos !== []) {
            $this->components->warn(sprintf(
                'Terminó con %d paso(s) fallido(s): %s. Revisa el detalle arriba; para repetir solo esos, usa --omitir con los demás.',
                count($fallidos),
                implode(', ', $fallidos),
            ));

            return self::FAILURE;
        }

        $this->components->info('Datos reales inicializados.');

        return self::SUCCESS;
    }

    private function ejecutar(string $clave): bool
    {
        return match ($clave) {
            'nas' => $this->verificarNas(),
            'organigrama' => $this->call('people:sincronizar-organigrama') === self::SUCCESS,
            'jefes' => $this->call('organigrama:sincronizar-jefes') === self::SUCCESS,
            'descriptivos' => $this->call('people:importar-descriptivos', array_filter([
                '--ruta' => (string) ($this->option('ruta-descriptivos') ?: config('descriptivos.carpeta')),
            ])) === self::SUCCESS,
            'formatos' => $this->call('people:importar-formatos-juridicos', array_filter([
                '--ruta' => (string) ($this->option('ruta-formatos') ?: config('documentos_maestros.carpeta_fuente')),
            ])) === self::SUCCESS,
            'catalogos' => $this->catalogosDependientesDePuestos(),
            'validar-maestros' => $this->call('people:validar-documentos-maestros') === self::SUCCESS,
            'permisos' => $this->call('people:sincronizar-permisos') === self::SUCCESS,
            'vacantes' => $this->call('people:sincronizar-vacantes') === self::SUCCESS,
            default => false,
        };
    }

    /**
     * El disco de expedientes debe aceptar una escritura (se escribe, se
     * verifica y se borra un archivo de prueba — nada más). Después se
     * compara lo registrado en BD contra el NAS: esas inconsistencias se
     * reportan pero no abortan (no impiden los pasos siguientes).
     */
    private function verificarNas(): bool
    {
        $nombreDisco = (string) config('expedientes.disk');
        $disco = Storage::disk($nombreDisco);
        $ruta = sprintf('.people-inicializar-%s.txt', Str::random(12));

        try {
            $escrito = $disco->put($ruta, 'ok');
            $confirmado = $escrito !== false && $disco->exists($ruta);
        } catch (Throwable $e) {
            $this->components->error(sprintf('El disco «%s» no es escribible: %s', $nombreDisco, $e->getMessage()));

            return false;
        } finally {
            try {
                $disco->delete($ruta);
            } catch (Throwable) {
                // Si ni siquiera se pudo borrar, el error ya se reportó arriba.
            }
        }

        if (! $confirmado) {
            $this->components->error(sprintf('El disco «%s» no confirmó la escritura de prueba: revisa montaje, permisos y espacio del NAS.', $nombreDisco));

            return false;
        }

        $this->components->twoColumnDetail(sprintf('Disco «%s»', $nombreDisco), '<fg=green>escribible</>');

        if ($this->call('expedientes:verificar-storage') !== self::SUCCESS) {
            $this->components->warn('expedientes:verificar-storage reportó inconsistencias (ver arriba): no se aborta, pero revísalas.');
        }

        return true;
    }

    /**
     * Catálogos técnicos que necesitan los puestos finales de la carga
     * inicial (no son personas ni demo): preguntas de filtro de candidatos
     * (por puestos de gestor) y grupo documental inicial de los puestos que
     * aún no lo tienen (nunca pisa lo que RH ya decidió).
     */
    private function catalogosDependientesDePuestos(): bool
    {
        $seeder = $this->laravel->make(PreguntasFiltroCandidatoSeeder::class);
        $seeder->setContainer($this->laravel)->setCommand($this);
        Model::withoutEvents(fn () => $seeder->__invoke());
        $this->components->twoColumnDetail('Preguntas de filtro de candidatos', '<fg=green>listas</>');

        $aplicados = app(CoberturaDocumentalService::class)->aplicarGruposIniciales();
        $this->components->twoColumnDetail('Puestos con grupo documental inicial asignado', (string) $aplicados);

        return true;
    }
}
