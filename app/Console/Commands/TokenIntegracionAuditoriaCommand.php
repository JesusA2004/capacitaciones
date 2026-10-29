<?php

namespace App\Console\Commands;

use App\Models\ClienteIntegracion;
use App\Services\Integraciones\TokenIntegracionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * php artisan integraciones:token-auditoria {emitir|rotar|revocar|estado}
 *     [--empresa=ID ...] [--todas-las-empresas]
 *
 * Token servidor-a-servidor del sistema Auditoría (habilidad
 * «auditoria:leer»). emitir/rotar muestran el token UNA sola vez (en la base
 * sólo queda su SHA-256); rotar invalida el anterior al instante. Hay que
 * indicar de forma explícita qué empresas puede leer.
 */
class TokenIntegracionAuditoriaCommand extends Command
{
    protected $signature = 'integraciones:token-auditoria
        {accion=estado : emitir | rotar | revocar | estado}
        {--empresa=* : Id de empresa permitida (repetible)}
        {--todas-las-empresas : Permite leer todas las empresas (decisión explícita)}';

    protected $description = 'Emite, rota, revoca o consulta el token servidor-a-servidor del sistema Auditoría';

    public function handle(TokenIntegracionService $tokens): int
    {
        $accion = (string) $this->argument('accion');
        $actual = ClienteIntegracion::query()->where('clave', TokenIntegracionService::CLAVE_AUDITORIA)->first();

        return match ($accion) {
            'estado' => $this->estado($actual),
            'revocar' => $this->revocar($tokens),
            'emitir', 'rotar' => $this->emitir($tokens, $actual, $accion),
            default => $this->invalida($accion),
        };
    }

    private function emitir(TokenIntegracionService $tokens, ?ClienteIntegracion $actual, string $accion): int
    {
        if ($accion === 'emitir' && $actual !== null && $actual->activo) {
            $this->error('Ya existe un token activo para Auditoría. Usa «rotar» para reemplazarlo o «revocar» para anularlo.');

            return self::FAILURE;
        }

        /** @var list<string> $opcion */
        $opcion = (array) $this->option('empresa');
        $todas = (bool) $this->option('todas-las-empresas');
        $empresas = [];

        foreach ($opcion as $valor) {
            if (preg_match('/^\d+$/', (string) $valor) !== 1) {
                $this->error(sprintf('Empresa no válida: «%s». Usa el id numérico.', $valor));

                return self::FAILURE;
            }

            $empresas[] = (int) $valor;
        }

        // Al rotar sin opciones se conservan las empresas ya permitidas.
        if ($accion === 'rotar' && $actual !== null && $empresas === [] && ! $todas) {
            $todas = $actual->todas_las_empresas;
            $empresas = $actual->empresasPermitidas() ?? [];
        }

        try {
            $resultado = $tokens->emitir(
                TokenIntegracionService::CLAVE_AUDITORIA,
                'Sistema Auditoría MR LANA',
                [TokenIntegracionService::HABILIDAD_AUDITORIA_LEER],
                $todas,
                $empresas,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($actual !== null ? 'Token de Auditoría rotado: el anterior ya no funciona.' : 'Token de Auditoría emitido.');
        $this->line('Cópialo ahora; no se vuelve a mostrar (en la base sólo queda su huella):');
        $this->newLine();
        $this->line($resultado['token']);
        $this->newLine();
        $this->line('En Auditoría va en PEOPLE_API_TOKEN (sólo en el .env del servidor; nunca en navegador ni app).');
        $this->estado($resultado['cliente']);

        return self::SUCCESS;
    }

    private function revocar(TokenIntegracionService $tokens): int
    {
        $cliente = $tokens->revocar(TokenIntegracionService::CLAVE_AUDITORIA);

        if ($cliente === null) {
            $this->warn('No hay token de Auditoría que revocar.');

            return self::SUCCESS;
        }

        $this->info('Token de Auditoría revocado: deja de funcionar de inmediato.');

        return self::SUCCESS;
    }

    private function estado(?ClienteIntegracion $cliente): int
    {
        if ($cliente === null) {
            $this->line('Sin token de Auditoría emitido.');

            return self::SUCCESS;
        }

        $this->table(['Campo', 'Valor'], [
            ['Activo', $cliente->activo ? 'sí' : 'no'],
            ['Prefijo', $cliente->token_prefijo !== null && $cliente->activo ? sprintf('%s…', $cliente->token_prefijo) : '—'],
            ['Habilidades', implode(', ', $cliente->habilidades)],
            ['Empresas', $cliente->todas_las_empresas ? 'todas' : implode(', ', $cliente->empresasPermitidas() ?? [])],
            ['Emitido', $cliente->emitido_en?->toIso8601String() ?? '—'],
            ['Revocado', $cliente->revocado_en?->toIso8601String() ?? '—'],
            ['Último uso', $cliente->ultimo_uso_en !== null ? sprintf('%s (%s)', $cliente->ultimo_uso_en->toIso8601String(), $cliente->ultimo_uso_ip ?? '—') : '—'],
        ]);

        return self::SUCCESS;
    }

    private function invalida(string $accion): int
    {
        $this->error(sprintf('Acción no válida: «%s». Usa emitir, rotar, revocar o estado.', $accion));

        return self::FAILURE;
    }
}
