<?php

namespace App\Services\Documentos\Versiones\Fuentes;

use App\Enums\CategoriaDocumento;
use App\Enums\DocumentoSalida;
use App\Enums\FamiliaAdministrativa;
use App\Models\Baja;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Documentos\Versiones\AlEmitirDocumento;
use App\Services\Documentos\Versiones\CampoEditable;
use App\Services\Documentos\Versiones\DatosVolatiles;
use App\Services\Documentos\Versiones\DocumentoRenderizado;
use App\Services\Documentos\Versiones\FuenteDocumento;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use Illuminate\Database\Eloquent\Model;

/**
 * Convenio, aviso de rescisión y constancia de una baja, con el diseño que RH
 * administra en Documentos administrativos.
 */
final class FuenteSalidaAdministrativa implements FuenteDocumento
{
    /**
     * @param  list<CampoEditable>  $camposEditables
     */
    public function __construct(
        private readonly DocumentoAdministrativoService $administrativos,
        private readonly DatosDocumentoAdministrativo $datos,
        private readonly Baja $baja,
        private readonly DocumentoSalida $documento,
        private readonly FamiliaAdministrativa $familia,
        private readonly CierreLaboral $cierre,
        private readonly Colaborador $persona,
        private readonly array $camposEditables,
        private readonly ?AlEmitirDocumento $alEmitir = null,
    ) {}

    public function colaborador(): Colaborador
    {
        return $this->persona;
    }

    public function registro(): Model
    {
        return $this->cierre;
    }

    public function clave(): string
    {
        return $this->documento->value;
    }

    public function etiqueta(): string
    {
        return $this->documento->etiqueta();
    }

    public function puedeEditar(User $actor): bool
    {
        return $actor->can('documentoSalida', $this->baja);
    }

    public function puedeVerVersiones(User $actor): bool
    {
        return $actor->can('documentoSalida', $this->baja);
    }

    public function puedeRectificar(User $actor): bool
    {
        return $actor->can('documentos_laborales.revisar_firmado');
    }

    public function campos(User $actor): array
    {
        return $this->camposEditables;
    }

    public function valoresHuella(array $correcciones, User $actor): array
    {
        return [
            ...DatosVolatiles::quitar([...$this->datos->salida($this->baja, $this->persona), ...$correcciones], $correcciones),
            '_diseno' => $this->administrativos->huellaDisenoVigente($this->familia),
        ];
    }

    public function vistaPrevia(array $correcciones, User $actor): string
    {
        return $this->administrativos->renderizarVigente($this->familia, [...$this->datos->salida($this->baja, $this->persona), ...$correcciones], DocumentoAdministrativoService::MARCA_VISTA_PREVIA);
    }

    public function renderizar(array $correcciones, User $actor): DocumentoRenderizado
    {
        $render = $this->administrativos->generar($this->familia, [...$this->datos->salida($this->baja, $this->persona), ...$correcciones], $actor);

        return new DocumentoRenderizado($render['pdf'], $this->documento->etiqueta(), CategoriaDocumento::BajaFiniquito, [
            ...$render['opciones_registro'],
            'categoria' => CategoriaDocumento::BajaFiniquito,
            'proceso' => 'baja',
            'requiere_impresion' => true,
            'requiere_firma_fisica' => true,
        ]);
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
}
