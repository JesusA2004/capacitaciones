<?php

namespace App\Services\Documentos\Versiones\Fuentes;

use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Policies\GeneratedDocumentPolicy;
use App\Services\Documentos\Versiones\AlEmitirDocumento;
use App\Services\Documentos\Versiones\CampoEditable;
use App\Services\Documentos\Versiones\DatosVolatiles;
use App\Services\Documentos\Versiones\DocumentoRenderizado;
use App\Services\Documentos\Versiones\FuenteDocumento;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use Illuminate\Database\Eloquent\Model;

/**
 * Documentos maestros de Jurídico por proceso: contratación, renovación, carta de
 * renuncia, permisos, préstamos, entrega de activos…. Una plantilla heredada no
 * ofrece campos, pero sí versiones. Delega en DocumentoProcesoService.
 */
final class FuenteMaestro implements FuenteDocumento
{
    /** Datos de la empresa y su firma: salen del catálogo, no se corrigen por documento. */
    public const CAMPOS_NO_EDITABLES = '/representante|patron|razon_social|empresa|firma/i';

    /**
     * @param  array<string, string>  $manuales
     */
    public function __construct(
        private readonly DocumentoProcesoService $proceso,
        private readonly string $procesoClave,
        private readonly Model $registro,
        private readonly string $claveDocumento,
        private readonly array $manuales = [],
        private readonly ?AlEmitirDocumento $alEmitir = null,
    ) {}

    public function colaborador(): Colaborador
    {
        return $this->proceso->colaboradorDelRegistro($this->registro);
    }

    public function registro(): Model
    {
        return $this->registro;
    }

    public function clave(): string
    {
        return $this->claveDocumento;
    }

    public function etiqueta(): string
    {
        return $this->proceso->nombreDocumento($this->claveDocumento);
    }

    public function puedeEditar(User $actor): bool
    {
        return $this->proceso->puedeVer($actor, $this->colaborador()) && $this->proceso->puedeGenerar($actor, $this->claveDocumento, $this->registro);
    }

    public function puedeVerVersiones(User $actor): bool
    {
        if (! $this->proceso->puedeVer($actor, $this->colaborador())) {
            return false;
        }

        return $actor->can('documentos_laborales.ver') || GeneratedDocumentPolicy::puedeVerContratos($actor);
    }

    public function puedeRectificar(User $actor): bool
    {
        return $actor->can('documentos_laborales.revisar_firmado');
    }

    public function campos(User $actor): array
    {
        $valores = $this->proceso->camposDelDocumento($this->procesoClave, $this->registro, $this->claveDocumento, $actor);
        $campos = [];

        foreach ($valores as $clave => $valor) {
            if (preg_match(self::CAMPOS_NO_EDITABLES, $clave) === 1 || $this->esVariante($clave, $valores)) {
                continue;
            }

            $campos[] = new CampoEditable($clave, ucfirst(str_replace('_', ' ', $clave)), mb_strlen($valor) > 80 ? 'area' : 'texto', $valor, $valor);
        }

        return $campos;
    }

    /**
     * Una corrección también debe verse en las variantes del mismo dato dentro
     * del PDF («NOMBRE» y «Nombre»): la variante en mayúsculas se deriva.
     *
     * @param  array<string, string>  $correcciones
     * @return array<string, string>
     */
    public static function conVariantes(array $correcciones): array
    {
        $completas = $correcciones;

        foreach ($correcciones as $clave => $valor) {
            $completas[$clave.'_mayusculas'] = mb_strtoupper($valor);
        }

        return $completas;
    }

    public function valoresHuella(array $correcciones, User $actor): array
    {
        return DatosVolatiles::quitar($this->proceso->valoresEmision($this->procesoClave, $this->registro, $this->claveDocumento, $actor, $this->manuales, self::conVariantes($correcciones)), $correcciones);
    }

    public function vistaPrevia(array $correcciones, User $actor): string
    {
        return $this->proceso->vistaPrevia($this->procesoClave, $this->registro, $this->claveDocumento, $actor, $this->manuales, correcciones: self::conVariantes($correcciones))['pdf'];
    }

    public function renderizar(array $correcciones, User $actor): DocumentoRenderizado
    {
        return $this->proceso->prepararEmision($this->procesoClave, $this->registro, $this->claveDocumento, $actor, $this->manuales, self::conVariantes($correcciones));
    }

    public function despuesDeEmitir(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void
    {
        if ($this->alEmitir !== null) {
            ($this->alEmitir)($documento, $anterior, $correcciones, $motivo, $actor);
        }
    }

    public function bloqueo(?GeneratedDocument $vigente, bool $rectificar): ?string
    {
        return null;
    }

    /**
     * @param  array<string, string>  $valores
     */
    private function esVariante(string $clave, array $valores): bool
    {
        return str_ends_with($clave, '_mayusculas') && array_key_exists(substr($clave, 0, -strlen('_mayusculas')), $valores);
    }
}
