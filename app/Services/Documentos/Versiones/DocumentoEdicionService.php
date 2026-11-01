<?php

namespace App\Services\Documentos\Versiones;

use App\Models\ActaAdministrativa;
use App\Models\Baja;
use App\Models\FiniquitoCalculo;
use App\Models\ReciboNomina;
use App\Models\Responsiva;
use App\Models\User;
use App\Services\Actas\ActaPdfService;
use App\Services\Bajas\BajaDocumentoSalidaService;
use App\Services\Documentos\Versiones\Fuentes\FuenteActa;
use App\Services\Documentos\Versiones\Fuentes\FuenteFiniquito;
use App\Services\Documentos\Versiones\Fuentes\FuenteMaestro;
use App\Services\Documentos\Versiones\Fuentes\FuenteRecibo;
use App\Services\Documentos\Versiones\Fuentes\FuenteResponsiva;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Nomina\ReciboNominaService;
use App\Services\Responsivas\ResponsivaPdfService;
use App\Services\Responsivas\ResponsivaService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lo que comparten «Editar datos» e «Historial de versiones» en web y app. Resuelve
 * el módulo y delega en su propio servicio: las reglas de cada uno no se duplican.
 */
class DocumentoEdicionService
{
    private const TIPOS_PROCESO = ['contrato', 'cierre', 'solicitud', 'prestamo', 'evaluacion', 'entrega_activo', 'acta', 'colaborador'];

    public const TIPOS = [...self::TIPOS_PROCESO, 'salida', 'finiquito', 'responsiva', 'recibo'];

    public function __construct(
        private readonly VersionadoDocumentoService $versionado,
        private readonly DocumentoProcesoService $proceso,
        private readonly BajaDocumentoSalidaService $salida,
    ) {}

    /**
     * @return array{campos: list<array<string, mixed>>, etiqueta: string, vigente: array{id: int, version: int, firmado: bool, desactualizado: bool}|null, puede_rectificar: bool}
     */
    public function campos(string $tipo, int $id, ?string $clave, User $actor): array
    {
        $fuente = $this->fuente($tipo, $id, $clave, $actor);
        $this->exigirEditar($fuente, $actor);
        $vigente = $this->versionado->vigente($fuente);

        return [
            'campos' => array_map(fn (CampoEditable $c): array => $c->aArray(), $this->versionado->camposParaEditar($fuente, $actor)),
            'etiqueta' => $fuente->etiqueta(),
            'vigente' => $vigente !== null ? [
                'id' => $vigente->id,
                'version' => $this->versionado->numeroVersion($vigente),
                'firmado' => $vigente->estado_flujo?->firmaFisicaRegistrada() ?? false,
                'desactualizado' => $this->versionado->estaDesactualizado($fuente, $vigente, $actor),
            ] : null,
            'puede_rectificar' => $fuente->puedeRectificar($actor),
        ];
    }

    /**
     * @param  array<string, mixed>  $campos
     */
    public function vistaPrevia(string $tipo, int $id, ?string $clave, array $campos, User $actor): string
    {
        $fuente = $this->fuente($tipo, $id, $clave, $actor);
        $this->exigirEditar($fuente, $actor);

        return $this->versionado->vistaPrevia($fuente, $campos, $actor);
    }

    /**
     * @param  array<string, mixed>|null  $campos
     */
    public function emitir(string $tipo, int $id, ?string $clave, ?array $campos, ?string $motivo, bool $rectificar, User $actor): ResultadoEmision
    {
        $fuente = $this->fuente($tipo, $id, $clave, $actor);
        $this->exigirEditar($fuente, $actor);
        $anterior = $this->versionado->vigente($fuente);

        $registro = $fuente->registro();

        match (true) {
            $tipo === 'salida' && ! $rectificar => $this->salida->generar(Baja::query()->findOrFail($id), $actor, $motivo, $campos),
            $tipo === 'salida' => $this->versionado->emitir($fuente, $campos, $motivo, $actor, true),
            $fuente instanceof FuenteMaestro || $fuente instanceof FuenteActa => $this->proceso->generar($this->procesoPorDefecto($tipo), $registro, $fuente->clave(), $actor, regenerar: $anterior !== null && ! $rectificar, revision: $rectificar, motivoRevision: $motivo, correcciones: $campos),
            $registro instanceof FiniquitoCalculo => app(FiniquitoService::class)->generarPdf($registro, $actor, $motivo),
            $registro instanceof Responsiva => app(ResponsivaService::class)->regenerar($registro, $actor, $motivo),
            default => throw ValidationException::withMessages(['documento' => (string) $fuente->bloqueo($anterior, $rectificar)]),
        };

        $vigente = $this->versionado->vigente($fuente) ?? throw ValidationException::withMessages(['documento' => 'No se pudo emitir el documento.']);

        return new ResultadoEmision($vigente, $vigente->id !== $anterior?->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versiones(string $tipo, int $id, ?string $clave, User $actor): array
    {
        $fuente = $tipo === 'salida'
            ? $this->fuenteSalida(Baja::query()->findOrFail($id), $actor, true)
            : $this->fuente($tipo, $id, $clave, $actor);

        return $this->versionado->versiones($fuente, $actor);
    }

    private function fuente(string $tipo, int $id, ?string $clave, User $actor): FuenteDocumento
    {
        return match ($tipo) {
            'salida' => $this->fuenteSalida(Baja::query()->findOrFail($id), $actor),
            'finiquito' => new FuenteFiniquito(app(FiniquitoService::class), FiniquitoCalculo::query()->findOrFail($id)),
            'responsiva' => new FuenteResponsiva(app(ResponsivaPdfService::class), Responsiva::query()->findOrFail($id)),
            'recibo' => new FuenteRecibo(app(ReciboNominaService::class), ReciboNomina::query()->findOrFail($id)),
            default => $this->fuenteProceso($tipo, $id, $clave),
        };
    }

    private function fuenteSalida(Baja $baja, User $actor, bool $soloConsulta = false): FuenteDocumento
    {
        if (! $actor->can('documentoSalida', $baja)) {
            throw new AccessDeniedHttpException('No tienes permiso para este documento de salida.');
        }

        return $this->salida->fuenteDe($baja, $actor, $soloConsulta);
    }

    private function fuenteProceso(string $tipo, int $id, ?string $clave): FuenteDocumento
    {
        $registro = $this->proceso->registro($tipo, $id);

        if ($clave === null || $clave === '') {
            throw ValidationException::withMessages(['clave' => 'Indica el documento.']);
        }

        if ($registro instanceof ActaAdministrativa && $this->proceso->esActaConFormatoPropio($clave, $registro)) {
            return new FuenteActa(app(ActaPdfService::class), $registro);
        }

        return new FuenteMaestro($this->proceso, $this->procesoPorDefecto($tipo), $registro, $clave);
    }

    private function procesoPorDefecto(string $tipo): string
    {
        return match ($tipo) {
            'contrato' => 'alta',
            'cierre' => 'baja',
            'solicitud' => 'permiso',
            'entrega_activo' => 'entrega_activo',
            'colaborador' => 'a_demanda',
            default => $tipo,
        };
    }

    private function exigirEditar(FuenteDocumento $fuente, User $actor): void
    {
        if (! $fuente->puedeEditar($actor)) {
            throw new AccessDeniedHttpException('No tienes permiso para editar este documento.');
        }
    }
}
