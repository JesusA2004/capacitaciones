<?php

namespace App\Services\Contratos;

use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Expedientes\CartaCompromisoService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * «Subir contrato firmado»: el original firmado físicamente (escaneo/foto)
 * entra al expediente como documento EN REVISIÓN del tipo que cubre ese
 * contrato (config contratos.regularizacion.tipos_expediente) y RH lo valida
 * en «Validación de documentos». Mismo servicio para web y API móvil.
 *
 * Quién puede subirlo:
 *  - RH: el permiso general documentos.subir (sube cualquier documento del
 *    expediente dentro de su alcance);
 *  - gerentes: la acción específica contratos.subir_firmado, solo para la
 *    gente de su alcance del módulo «Contratos» y nunca el propio. Subir el
 *    firmado NO deja ver, descargar ni generar el contrato del sistema.
 *  - Contrato de un Gerente de Sucursal (puesto de
 *    ciclo_laboral.organizacion.puestos_gerencia_sucursal): solo la Gerente
 *    Regional de su zona (matriz comercial, OrganizacionJerarquiaService::
 *    regionalesDe), no otro gerente de sucursal. RH conserva el general.
 */
class ContratoFirmadoService
{
    public const PERMISO = 'contratos.subir_firmado';

    /** Permiso general de RH: subir cualquier documento del expediente. */
    public const PERMISO_GENERAL = 'documentos.subir';

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly DocumentoStorageService $storage,
        private readonly CartaCompromisoService $cartas,
    ) {}

    /**
     * Tipo de documento del expediente (document_types.clave) que guarda el
     * firmado de una clave de contrato, o null si esa clave no admite carga.
     */
    public function tipoParaClave(string $claveContrato): ?string
    {
        $tipos = config("contratos.regularizacion.tipos_expediente.{$claveContrato}", []);

        foreach ((array) $tipos as $tipo) {
            if (is_string($tipo) && $tipo !== '') {
                return $tipo;
            }
        }

        return null;
    }

    /**
     * Claves de contrato que admiten carga del firmado.
     *
     * @return list<string>
     */
    public function clavesAdmitidas(): array
    {
        return array_values(array_filter(
            array_map(fn ($clave): string => (string) $clave, array_keys((array) config('contratos.regularizacion.tipos_expediente', []))),
            fn (string $clave): bool => $this->tipoParaClave($clave) !== null,
        ));
    }

    public function puedeSubir(User $usuario, Colaborador $colaborador): bool
    {
        return $this->motivoNoPuede($usuario, $colaborador) === null;
    }

    /** null si puede subir el firmado; si no, el motivo en español. */
    public function motivoNoPuede(User $usuario, Colaborador $colaborador): ?string
    {
        if ($usuario->can(self::PERMISO_GENERAL)) {
            return $this->alcance->puedeVerExpediente($usuario, $colaborador)
                ? null
                : 'Este colaborador está fuera de tu alcance.';
        }

        if (! $usuario->can(self::PERMISO)) {
            return 'No tienes permiso para subir contratos firmados.';
        }

        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id) {
            return 'No puedes subir tu propio contrato firmado: lo sube tu jefe o RH.';
        }

        if (! $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, 'contratos')) {
            return 'Este colaborador está fuera de tu alcance.';
        }

        if ($this->jerarquia->esPuestoDeGerenciaSucursal($colaborador->puesto_id)) {
            $regionales = $this->jerarquia->regionalesDe($colaborador->sucursal_principal_id, $colaborador);

            if (! $regionales->contains(fn (User $regional): bool => $regional->id === $usuario->id)) {
                return 'El contrato de un Gerente de Sucursal lo sube la Gerente Regional de su zona (o RH).';
            }
        }

        return null;
    }

    /**
     * Guarda el firmado como nueva versión EN REVISIÓN del tipo que cubre el
     * contrato (la versión anterior se archiva, no se borra).
     */
    public function subir(Colaborador $colaborador, string $claveContrato, UploadedFile $archivo, User $actor): EmployeeDocument
    {
        $motivo = $this->motivoNoPuede($actor, $colaborador);

        if ($motivo !== null) {
            throw new AuthorizationException($motivo);
        }

        $claveTipo = $this->tipoParaClave($claveContrato);
        $tipo = $claveTipo !== null ? DocumentType::query()->where('clave', $claveTipo)->first() : null;

        if ($tipo === null) {
            throw ValidationException::withMessages(['clave' => 'Ese documento no admite la carga del contrato firmado.']);
        }

        $documento = $this->storage->subirVersion($colaborador, $tipo, $archivo, $actor->id);
        $this->cartas->sincronizarSinFallar($colaborador);

        return $documento;
    }
}
