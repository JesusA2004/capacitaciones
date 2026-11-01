<?php

namespace App\Services\Documentos\Versiones;

use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que un módulo aporta al flujo uniforme de documentos. El módulo conserva su
 * motor de render y sus reglas; VersionadoDocumentoService pone el orden seguro.
 */
interface FuenteDocumento
{
    public function colaborador(): Colaborador;

    /** Registro al que se liga cada versión (documentable). */
    public function registro(): Model;

    public function clave(): string;

    public function etiqueta(): string;

    public function puedeEditar(User $actor): bool;

    public function puedeVerVersiones(User $actor): bool;

    public function puedeRectificar(User $actor): bool;

    /**
     * Lista blanca: lo único que se acepta corregir en este documento.
     *
     * @return list<CampoEditable>
     */
    public function campos(User $actor): array;

    /**
     * Datos que determinan el contenido, sin fecha de emisión, sello ni
     * referencias aleatorias. No renderiza: es barato y es la base de la huella.
     *
     * @param  array<string, string>  $correcciones
     * @return array<string, mixed>
     */
    public function valoresHuella(array $correcciones, User $actor): array;

    /**
     * PDF con marca de vista previa. No persiste nada.
     *
     * @param  array<string, string>  $correcciones
     */
    public function vistaPrevia(array $correcciones, User $actor): string;

    /**
     * PDF definitivo en memoria. No persiste el documento.
     *
     * @param  array<string, string>  $correcciones
     */
    public function renderizar(array $correcciones, User $actor): DocumentoRenderizado;

    /**
     * Dentro de la transacción de la emisión: si falla, no queda la versión.
     *
     * @param  array<string, string>  $correcciones
     */
    public function despuesDeEmitir(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void;

    /**
     * Regla propia del módulo que impide emitir. Null si no la hay.
     */
    public function bloqueo(?GeneratedDocument $vigente, bool $rectificar): ?string;
}
