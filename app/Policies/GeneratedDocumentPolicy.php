<?php

namespace App\Policies;

use App\Enums\EstadoFlujoDocumento;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Documentos laborales emitidos por el motor documental. El colaborador ve,
 * descarga y firma SOLO los suyos; RH/Jurídico/Dirección los consultan
 * dentro de su alcance; la operación del original físico requiere
 * documentos_laborales.operar_fisico. Nunca se entrega una URL pública al
 * NAS: la descarga siempre pasa por aquí.
 */
class GeneratedDocumentPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, GeneratedDocument $documento): bool
    {
        if ($this->esPropio($usuario, $documento)) {
            return $documento->estado_flujo !== null && $documento->estado_flujo !== EstadoFlujoDocumento::Borrador;
        }

        return $usuario->can('documentos_laborales.ver') && $this->enAlcance($usuario, $documento);
    }

    public function descargar(User $usuario, GeneratedDocument $documento): bool
    {
        return $this->ver($usuario, $documento);
    }

    /**
     * Word llenado: solo quien opera el documento en su proceso (RH, gerente,
     * regional dentro de su alcance). El colaborador recibe el PDF, no el
     * Word editable.
     */
    public function descargarWord(User $usuario, GeneratedDocument $documento): bool
    {
        return $documento->docx_path !== null
            && ! $this->esPropio($usuario, $documento)
            && $usuario->can('documentos_laborales.ver')
            && ($usuario->can('documentos_laborales.generar') || $usuario->can('documentos_laborales.operar_fisico'))
            && $this->enAlcance($usuario, $documento);
    }

    public function firmar(User $usuario, GeneratedDocument $documento): bool
    {
        return $this->esPropio($usuario, $documento) && $documento->estado_flujo === EstadoFlujoDocumento::PendienteFirmaColaborador;
    }

    public function operar(User $usuario, GeneratedDocument $documento): bool
    {
        return $usuario->can('documentos_laborales.operar_fisico')
            && ! $this->esPropio($usuario, $documento)
            && $this->enAlcance($usuario, $documento);
    }

    public function cancelar(User $usuario, GeneratedDocument $documento): bool
    {
        return $usuario->can('documentos_laborales.cancelar')
            && ! $this->esPropio($usuario, $documento)
            && $this->enAlcance($usuario, $documento);
    }

    private function esPropio(User $usuario, GeneratedDocument $documento): bool
    {
        return $usuario->colaborador_id !== null && $documento->colaborador_id === $usuario->colaborador_id;
    }

    private function enAlcance(User $usuario, GeneratedDocument $documento): bool
    {
        $colaborador = $documento->colaborador()->withTrashed()->first();

        return $colaborador !== null && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }
}
