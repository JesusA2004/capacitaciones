<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\EstadoContratoLaboral;
use App\Enums\ModoCorreccion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Salarios\AccesoSueldoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Registro del contrato. Solo vigente y sin firmar; el firmado requiere una
 * modificación contractual, que el sistema no genera sola.
 */
class ContratacionDominio extends DominioBase
{
    public function __construct(
        private readonly ContratoLaboralService $contratos,
        private readonly AccesoSueldoService $acceso,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function clave(): string
    {
        return 'contratacion';
    }

    public function sujeto(int $id, bool $bloquear = false): ?Model
    {
        return ContratoLaboral::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function etiquetaSujeto(Model $sujeto): string
    {
        return $sujeto instanceof ContratoLaboral ? sprintf('Contrato %s', $sujeto->tipo->etiqueta()) : '';
    }

    public function colaboradorDe(Model $sujeto): ?Colaborador
    {
        return $sujeto instanceof ContratoLaboral ? $sujeto->colaborador : null;
    }

    public function puedeLeer(User $actor, Model $sujeto): bool
    {
        return $actor->can('ver', $sujeto);
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        $colaborador = $this->colaboradorDe($sujeto);

        return $colaborador !== null && ! $this->esPropio($actor, $colaborador)
            && $actor->can('contratos.generar') && $this->alcance->alcanzaColaboradorEnModulo($actor, $colaborador, 'contratos');
    }

    protected function esVisible(Model $sujeto, string $campo, User $actor): bool
    {
        return $campo !== 'sueldo_mensual' || $this->acceso->puedeVer($actor);
    }

    protected function restriccion(Model $sujeto, string $campo): ?array
    {
        if (! $sujeto instanceof ContratoLaboral || $campo === 'tipo') {
            return null;
        }

        if ($sujeto->estado !== EstadoContratoLaboral::Vigente) {
            return [ModoCorreccion::NoCorregible, 'Este contrato ya no está vigente: se conserva como quedó.'];
        }

        if ($this->contratos->contratoFirmado($sujeto)) {
            return [ModoCorreccion::NuevaVersion, 'Este contrato está firmado: requiere modificación contractual. El sistema no genera un anexo automático (pendiente de validación por Jurídico).'];
        }

        return null;
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        return $sujeto->getAttribute('generated_document_id') === null ? [] : [[
            'tipo' => 'documento',
            'etiqueta' => 'Documento del contrato sin firmar',
            'detalle' => 'No se regenera solo: vuelve a generarlo para que tome el dato corregido.',
            'accion' => 'revisar',
        ]];
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof ContratoLaboral) {
            throw ValidationException::withMessages(['valores' => 'Registro no válido.']);
        }

        $this->contratos->corregirRegistro($sujeto, $valores);

        return ['modo' => ModoCorreccion::Directo->value];
    }
}
