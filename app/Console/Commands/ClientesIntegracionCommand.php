<?php

namespace App\Console\Commands;

use App\Enums\HabilidadIntegracion;
use App\Models\ClienteIntegracion;
use App\Models\ConsultaIntegracion;
use App\Services\Integraciones\TokenIntegracionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * php artisan integraciones:clientes {listar|habilidades|estado|emitir|rotar|revocar} [clave]
 *     [--nombre=] [--descripcion=] [--habilidad=* | --perfil=reportes]
 *     [--empresa=* | --todas-las-empresas] [--limite=]
 *
 * Administra los clientes de integración servidor-a-servidor de CUALQUIER
 * sistema (Reportes, Auditoría, los que sigan). Sólo desde el servidor
 * (acceso de super administrador); no hay pantalla web a propósito. El token
 * se muestra UNA vez (en la base sólo queda su SHA-256); rotar invalida el
 * anterior al instante. Las habilidades sensibles (identificación, salario)
 * sólo se conceden nombrándolas una por una, nunca con un perfil.
 */
class ClientesIntegracionCommand extends Command
{
    protected $signature = 'integraciones:clientes
        {accion=listar : listar | habilidades | estado | emitir | rotar | revocar}
        {clave? : Clave del sistema (p. ej. reportes)}
        {--nombre= : Nombre visible del sistema}
        {--descripcion= : Para qué lo usa (soporte)}
        {--habilidad=* : Habilidad concedida (repetible); ver «habilidades»}
        {--perfil= : Conjunto predefinido de habilidades: reportes}
        {--empresa=* : Id de empresa permitida (repetible)}
        {--todas-las-empresas : Permite leer todas las empresas (decisión explícita)}
        {--limite= : Solicitudes por minuto propias (0 = el general de config)}';

    protected $description = 'Emite, rota, revoca o consulta los tokens de integración servidor-a-servidor por sistema';

    public function handle(TokenIntegracionService $tokens): int
    {
        $accion = (string) $this->argument('accion');
        $clave = $this->argument('clave');
        $clave = is_string($clave) && $clave !== '' ? $clave : null;

        if (in_array($accion, ['estado', 'emitir', 'rotar', 'revocar'], true) && $clave === null) {
            $this->error('Indica la clave del sistema (p. ej. «reportes»).');

            return self::FAILURE;
        }

        return match ($accion) {
            'listar' => $this->listar(),
            'habilidades' => $this->habilidades(),
            'estado' => $this->estado(ClienteIntegracion::query()->where('clave', $clave)->first(), (string) $clave),
            'revocar' => $this->revocar($tokens, (string) $clave),
            'emitir', 'rotar' => $this->emitir($tokens, (string) $clave, $accion),
            default => $this->invalida($accion),
        };
    }

    private function emitir(TokenIntegracionService $tokens, string $clave, string $accion): int
    {
        $actual = ClienteIntegracion::query()->where('clave', $clave)->first();

        if ($accion === 'emitir' && $actual !== null && $actual->activo) {
            $this->error(sprintf('Ya existe un token activo para «%s». Usa «rotar» para reemplazarlo o «revocar» para anularlo.', $clave));

            return self::FAILURE;
        }

        if ($accion === 'rotar' && $actual === null) {
            $this->error(sprintf('No existe el cliente «%s»; usa «emitir».', $clave));

            return self::FAILURE;
        }

        $habilidades = $this->habilidadesPedidas();

        if ($habilidades === null) {
            return self::FAILURE;
        }

        $empresas = $this->empresasPedidas();

        if ($empresas === null) {
            return self::FAILURE;
        }

        $todas = (bool) $this->option('todas-las-empresas');
        $limite = $this->option('limite');

        if ($limite !== null && (preg_match('/^\d{1,6}$/', $limite) !== 1)) {
            $this->error('--limite debe ser un número entero de solicitudes por minuto (0 = el general).');

            return self::FAILURE;
        }

        // Al rotar sin opciones se conserva lo ya concedido.
        if ($actual !== null && $accion === 'rotar') {
            $habilidades = $habilidades === [] ? $actual->habilidades : $habilidades;

            if ($empresas === [] && ! $todas) {
                $todas = $actual->todas_las_empresas;
                $empresas = $actual->empresasPermitidas() ?? [];
            }
        }

        if ($habilidades === []) {
            $this->error('Indica al menos una --habilidad o un --perfil (ver «integraciones:clientes habilidades»).');

            return self::FAILURE;
        }

        $nombre = $this->option('nombre');
        $descripcion = $this->option('descripcion');

        try {
            $resultado = $tokens->emitir(
                $clave,
                is_string($nombre) && $nombre !== '' ? $nombre : ($actual->nombre ?? sprintf('Sistema %s', $clave)),
                $habilidades,
                $todas,
                $empresas,
                $limite !== null ? (int) $limite : null,
                is_string($descripcion) ? $descripcion : null,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($actual !== null ? sprintf('Token de «%s» rotado: el anterior ya no funciona.', $clave) : sprintf('Token de «%s» emitido.', $clave));
        $this->line('Cópialo ahora; no se vuelve a mostrar (en la base sólo queda su huella):');
        $this->newLine();
        $this->line($resultado['token']);
        $this->newLine();
        $this->line('Va sólo en el .env del servidor del otro sistema (nunca en navegador ni app), como «Authorization: Bearer <token>».');
        $this->estado($resultado['cliente'], $clave);

        return self::SUCCESS;
    }

    private function revocar(TokenIntegracionService $tokens, string $clave): int
    {
        if ($tokens->revocar($clave) === null) {
            $this->warn(sprintf('No hay cliente «%s» que revocar.', $clave));

            return self::SUCCESS;
        }

        $this->info(sprintf('Token de «%s» revocado: deja de funcionar de inmediato. Su bitácora se conserva.', $clave));

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $clientes = ClienteIntegracion::query()->orderBy('clave')->get();

        if ($clientes->isEmpty()) {
            $this->line('Sin clientes de integración.');

            return self::SUCCESS;
        }

        $this->table(['Clave', 'Nombre', 'Activo', 'Límite/min', 'Habilidades', 'Último uso'], $clientes->map(fn (ClienteIntegracion $c): array => [
            $c->clave,
            $c->nombre,
            $c->activo ? 'sí' : 'no',
            (string) $c->limitePorMinuto(),
            implode(', ', $c->habilidades),
            $c->ultimo_uso_en?->toIso8601String() ?? '—',
        ])->all());

        return self::SUCCESS;
    }

    private function habilidades(): int
    {
        $this->table(['Habilidad', 'Qué permite', 'Sensible'], array_map(fn (HabilidadIntegracion $h): array => [
            $h->value,
            $h->etiqueta(),
            $h->esSensible() ? 'sí (sólo explícita)' : 'no',
        ], HabilidadIntegracion::cases()));
        $this->line(sprintf('Perfil «reportes»: %s', implode(', ', HabilidadIntegracion::paraReportes())));

        return self::SUCCESS;
    }

    private function estado(?ClienteIntegracion $cliente, string $clave): int
    {
        if ($cliente === null) {
            $this->line(sprintf('Sin cliente «%s».', $clave));

            return self::SUCCESS;
        }

        $this->table(['Campo', 'Valor'], [
            ['Clave', $cliente->clave],
            ['Nombre', $cliente->nombre],
            ['Descripción', $cliente->descripcion ?? '—'],
            ['Activo', $cliente->activo ? 'sí' : 'no'],
            ['Prefijo', $cliente->token_prefijo !== null && $cliente->activo ? sprintf('%s…', $cliente->token_prefijo) : '—'],
            ['Habilidades', implode(', ', $cliente->habilidades)],
            ['Empresas', $cliente->todas_las_empresas ? 'todas' : implode(', ', $cliente->empresasPermitidas() ?? [])],
            ['Límite por minuto', sprintf('%d%s', $cliente->limitePorMinuto(), $cliente->limite_por_minuto === null ? ' (general)' : '')],
            ['Emitido', $cliente->emitido_en?->toIso8601String() ?? '—'],
            ['Revocado', $cliente->revocado_en?->toIso8601String() ?? '—'],
            ['Último uso', $cliente->ultimo_uso_en !== null ? sprintf('%s (%s)', $cliente->ultimo_uso_en->toIso8601String(), $cliente->ultimo_uso_ip ?? '—') : '—'],
            ['Consultas últimas 24 h', (string) ConsultaIntegracion::query()->where('cliente_integracion_id', $cliente->id)->where('created_at', '>=', now()->subDay())->count()],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null null si alguna no existe
     */
    private function habilidadesPedidas(): ?array
    {
        /** @var list<string> $opcion */
        $opcion = (array) $this->option('habilidad');
        $perfil = $this->option('perfil');
        $habilidades = [];

        if ($perfil !== null && $perfil !== '') {
            if ($perfil !== 'reportes') {
                $this->error(sprintf('Perfil no válido: «%s». Disponible: reportes.', (string) $perfil));

                return null;
            }

            $habilidades = HabilidadIntegracion::paraReportes();
        }

        foreach ($opcion as $valor) {
            if (HabilidadIntegracion::tryFrom((string) $valor) === null) {
                $this->error(sprintf('Habilidad no válida: «%s». Consulta «integraciones:clientes habilidades».', $valor));

                return null;
            }

            $habilidades[] = (string) $valor;
        }

        return array_values(array_unique($habilidades));
    }

    /**
     * @return list<int>|null null si alguna no es numérica
     */
    private function empresasPedidas(): ?array
    {
        /** @var list<string> $opcion */
        $opcion = (array) $this->option('empresa');
        $empresas = [];

        foreach ($opcion as $valor) {
            if (preg_match('/^\d+$/', (string) $valor) !== 1) {
                $this->error(sprintf('Empresa no válida: «%s». Usa el id numérico.', $valor));

                return null;
            }

            $empresas[] = (int) $valor;
        }

        return $empresas;
    }

    private function invalida(string $accion): int
    {
        $this->error(sprintf('Acción no válida: «%s». Usa listar, habilidades, estado, emitir, rotar o revocar.', $accion));

        return self::FAILURE;
    }
}
