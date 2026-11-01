<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\EstadoFiniquito;
use App\Enums\ModoCorreccion;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Finiquitos\FiniquitoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Delega en FiniquitoService::corregir(). Firmado o pagado no se toca: su
 * rectificación está pendiente de definirse con Jurídico.
 */
class FiniquitoDominio extends DominioBase
{
    public function __construct(
        private readonly FiniquitoService $finiquitos,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function clave(): string
    {
        return 'finiquito';
    }

    public function sujeto(int $id, bool $bloquear = false): ?Model
    {
        return FiniquitoCalculo::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function etiquetaSujeto(Model $sujeto): string
    {
        return $sujeto instanceof FiniquitoCalculo ? sprintf('Finiquito de %s', $sujeto->colaborador->nombreCompleto()) : '';
    }

    public function colaboradorDe(Model $sujeto): ?Colaborador
    {
        return $sujeto instanceof FiniquitoCalculo ? $sujeto->colaborador : null;
    }

    public function puedeLeer(User $actor, Model $sujeto): bool
    {
        return $actor->can('ver', $sujeto);
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        $colaborador = $this->colaboradorDe($sujeto);

        return $colaborador !== null && ! $this->esPropio($actor, $colaborador)
            && $actor->can('finiquitos.calcular') && $this->alcance->puedeVerExpediente($actor, $colaborador);
    }

    protected function definiciones(Model $sujeto): array
    {
        $definiciones = parent::definiciones($sujeto);

        if (! $sujeto instanceof FiniquitoCalculo) {
            return $definiciones;
        }

        foreach ($this->finiquitos->desglose($sujeto) as $renglon) {
            $clave = $this->claveRenglon($renglon);

            if ($clave !== null) {
                $definiciones[$clave] = [
                    'etiqueta' => (string) $renglon['concepto'],
                    'tipo' => 'moneda',
                    'modo' => ModoCorreccion::Directo->value,
                    'permiso' => 'finiquitos.calcular',
                    'reglas' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
                ];
            }
        }

        return $definiciones;
    }

    protected function valor(Model $sujeto, string $campo): mixed
    {
        if ($sujeto instanceof FiniquitoCalculo && $campo !== 'comentarios_ajuste') {
            foreach ($this->finiquitos->desglose($sujeto) as $renglon) {
                if ($this->claveRenglon($renglon) === $campo) {
                    return number_format((float) $renglon['importe'], 2, '.', '');
                }
            }

            return null;
        }

        return parent::valor($sujeto, $campo);
    }

    protected function restriccion(Model $sujeto, string $campo): ?array
    {
        if ($sujeto instanceof FiniquitoCalculo && in_array($sujeto->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)) {
            return [ModoCorreccion::NoCorregible, 'Este finiquito ya está firmado o pagado y no cambia. Solo admite rectificación, y su procedimiento está pendiente de validación por Jurídico.'];
        }

        return null;
    }

    public function normalizar(Model $sujeto, array $valores, array $opciones, User $actor): array
    {
        $comentarios = array_intersect_key($valores, ['comentarios_ajuste' => true]);
        $resultado = $comentarios !== [] ? parent::normalizar($sujeto, $comentarios, $opciones, $actor) : [];

        foreach ($valores as $campo => $valor) {
            if ($campo === 'comentarios_ajuste') {
                continue;
            }

            if (! is_numeric($valor) || (float) $valor < 0) {
                throw ValidationException::withMessages(['valores.'.$campo => 'El importe debe ser un número mayor o igual a cero.']);
            }

            $resultado[$campo] = number_format((float) $valor, 2, '.', '');
        }

        return $resultado;
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        return [[
            'tipo' => 'finiquito',
            'etiqueta' => 'Revisión y PDF del finiquito',
            'detalle' => 'Regresa a revisión y conserva el valor calculado. Si ya tenía PDF sin firmar, vuelve a generarlo.',
            'accion' => 'se_actualiza',
        ]];
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof FiniquitoCalculo) {
            throw ValidationException::withMessages(['valores' => 'Registro no válido.']);
        }

        $cambios = ['conceptos' => [], 'manuales' => []];

        foreach ($valores as $campo => $valor) {
            if (str_starts_with($campo, 'auto_')) {
                $cambios['conceptos'][] = ['concepto_clave' => substr($campo, 5), 'importe' => (float) $valor];
            } elseif (str_starts_with($campo, 'manual_')) {
                $cambios['manuales'][] = ['id' => (int) substr($campo, 7), 'importe' => (float) $valor];
            }
        }

        if ($cambios['conceptos'] !== [] || $cambios['manuales'] !== []) {
            $this->finiquitos->corregir($sujeto, $cambios, $motivo, $actor);
        }

        if (array_key_exists('comentarios_ajuste', $valores)) {
            $this->finiquitos->actualizarAjustes($sujeto->refresh(), $actor, ['comentarios_ajuste' => $valores['comentarios_ajuste']]);
        }

        return ['modo' => ModoCorreccion::Directo->value];
    }

    /**
     * @param  array<string, mixed>  $renglon
     */
    private function claveRenglon(array $renglon): ?string
    {
        if (($renglon['concepto_clave'] ?? null) !== null) {
            return sprintf('auto_%s', (string) $renglon['concepto_clave']);
        }

        return ($renglon['id'] ?? null) !== null ? sprintf('manual_%d', (int) $renglon['id']) : null;
    }
}
