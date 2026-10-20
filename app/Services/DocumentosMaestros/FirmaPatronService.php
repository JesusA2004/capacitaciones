<?php

namespace App\Services\DocumentosMaestros;

use App\Models\Empresa;

/**
 * Razón social y cargo que firman por el patrón en los documentos legales
 * generados por PEOPLE (responsivas, actas, evaluación de inducción).
 *
 * El formato oficial de Jurídico pone ARRIBA de la línea la razón social
 * legal (PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. DE C.V.) y ABAJO el cargo
 * (Representante legal). El catálogo de empresas guarda además un nombre
 * corto/comercial («Mr. Lana», «Sistemas Mr Lana»…) que NO es la razón
 * social, así que para las empresas del grupo MR. LANA siempre se imprime
 * la razón social de config('documentos_maestros.firma_patron'). Una
 * empresa de otro grupo conserva la suya (o su nombre si no la capturaron).
 */
class FirmaPatronService
{
    /**
     * Mismo criterio que people:adoptar-datos-patron para reconocer a las
     * empresas de MR. LANA en el catálogo.
     */
    public static function esGrupoMrLana(?string $nombre, ?string $razonSocial): bool
    {
        return str_contains(mb_strtolower(sprintf('%s %s', $nombre ?? '', $razonSocial ?? ''), 'UTF-8'), 'lana');
    }

    public function razonSocial(?string $nombre, ?string $razonSocial): string
    {
        $nombre = trim((string) $nombre);
        $razonSocial = trim((string) $razonSocial);

        if (($nombre === '' && $razonSocial === '') || self::esGrupoMrLana($nombre, $razonSocial)) {
            return $this->razonSocialOficial();
        }

        return mb_strtoupper($razonSocial !== '' ? $razonSocial : $nombre, 'UTF-8');
    }

    public function razonSocialDeEmpresa(?Empresa $empresa): string
    {
        return $this->razonSocial($empresa?->nombre, $empresa?->razon_social);
    }

    public function razonSocialOficial(): string
    {
        return (string) config('documentos_maestros.firma_patron.razon_social', 'PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. DE C.V.');
    }

    /** Lo que va debajo de la línea de firma del patrón. */
    public function cargo(): string
    {
        return (string) config('documentos_maestros.firma_patron.cargo', 'Representante legal');
    }
}
