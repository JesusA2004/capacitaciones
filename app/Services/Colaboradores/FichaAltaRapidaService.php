<?php

namespace App\Services\Colaboradores;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use App\Support\FechaMexico;
use Carbon\CarbonInterface;

/**
 * «Datos para dar de alta»: la ficha corta que RH copia a otros sistemas
 * (IMSS, nómina, banco…) sin abrir el expediente. Se pide bajo demanda al
 * pasar el mouse sobre una tarjeta de Expedientes o del tablero de
 * Candidatos, así que nunca infla las listas.
 *
 * Solo lectura y solo datos que existen en el modelo: lo vacío se muestra
 * como «Sin capturar» (nunca se infiere). El sueldo no viaja aquí (tiene su
 * propio control de acceso, ver AccesoSueldoService).
 */
class FichaAltaRapidaService
{
    public const SIN_CAPTURAR = 'Sin capturar';

    private const MESES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function __construct(
        private readonly AprobacionOrganizacionalService $aprobaciones,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * @return array{tipo: string, titulo: string, subtitulo: string, origen: string|null, campos: list<array{clave: string, etiqueta: string, valor: string, copiar: string, capturado: bool}>}
     */
    public function deColaborador(Colaborador $colaborador): array
    {
        $colaborador->loadMissing([
            'sucursalPrincipal:id,nombre,empresa_id',
            'sucursalPrincipal.empresa:id,nombre',
            'departamento:id,nombre',
            'puesto:id,nombre',
            'jefe:id,name,apellidos',
        ]);

        // Mismo jefe que muestra el expediente (ExpedienteCentroService):
        // el del organigrama y, si no hay, el registrado.
        $jefe = $this->aprobaciones->jefeOrganizacional($colaborador) ?? $colaborador->jefe;

        $estatus = $colaborador->trashed() ? 'Baja' : $colaborador->estatus->etiqueta();

        return [
            'tipo' => 'colaborador',
            'titulo' => $colaborador->nombreCompleto(),
            'subtitulo' => sprintf('Colaborador · %s', $estatus),
            'origen' => null,
            'campos' => [
                $this->campo('nombre_completo', 'Nombre completo', $colaborador->nombreCompleto()),
                $this->campo('nombre', 'Nombre(s)', $colaborador->name),
                $this->campo('apellidos', 'Apellidos', $colaborador->apellidos),
                $this->fecha('fecha_nacimiento', 'Fecha de nacimiento', $colaborador->fecha_nacimiento),
                $this->campo('genero', 'Género', $colaborador->genero?->etiqueta()),
                $this->campo('curp', 'CURP', $colaborador->curp),
                $this->campo('rfc', 'RFC', $colaborador->rfc),
                $this->campo('nss', 'NSS', $colaborador->nss),
                $this->campo('telefono', 'Teléfono', $colaborador->telefono),
                $this->campo('correo', 'Correo personal', $colaborador->correo_personal),
                $this->campo('domicilio', 'Domicilio', $colaborador->domicilio),
                $this->campo('empresa', 'Empresa', $colaborador->sucursalPrincipal?->empresa?->nombre),
                $this->campo('sucursal', 'Sucursal', $colaborador->sucursalPrincipal?->nombre),
                $this->campo('puesto', 'Puesto', $colaborador->puesto?->nombre),
                $this->campo('departamento', 'Departamento', $colaborador->departamento?->nombre),
                $this->campo('jefe', 'Jefe directo', $jefe?->nombreCompleto()),
                $this->fecha('fecha_ingreso', 'Fecha de ingreso', $colaborador->fecha_ingreso),
                $this->campo('numero_empleado', 'Número de empleado', $colaborador->numero_empleado),
                $this->campo('estatus', 'Estatus', $estatus),
            ],
        ];
    }

    /**
     * Ya contratado (con colaborador) y con acceso a su expediente: la
     * ficha del colaborador, que es la que está completa. Si no, solo lo
     * que se capturó del candidato — lo demás se llena al contratarlo.
     *
     * @return array{tipo: string, titulo: string, subtitulo: string, origen: string|null, campos: list<array{clave: string, etiqueta: string, valor: string, copiar: string, capturado: bool}>}
     */
    public function deCandidato(Candidato $candidato, User $usuario): array
    {
        $colaborador = $candidato->colaborador_id !== null
            ? Colaborador::withTrashed()->where('id', $candidato->colaborador_id)->first()
            : null;

        if ($colaborador !== null && $this->alcance->puedeVerExpediente($usuario, $colaborador)) {
            $ficha = $this->deColaborador($colaborador);
            $ficha['origen'] = 'Datos del expediente del colaborador contratado.';

            return $ficha;
        }

        $candidato->loadMissing([
            'sucursal:id,nombre,empresa_id',
            'sucursal.empresa:id,nombre',
            'empresa:id,nombre',
            'departamento:id,nombre',
            'puestoObjetivo:id,nombre',
        ]);

        return [
            'tipo' => 'candidato',
            'titulo' => $candidato->nombreCompleto(),
            'subtitulo' => sprintf('Candidato · %s', $candidato->etiquetaEstado()),
            'origen' => 'Datos capturados del candidato; CURP, RFC, NSS y fecha de nacimiento se completan al contratarlo.',
            'campos' => [
                $this->campo('nombre_completo', 'Nombre completo', $candidato->nombreCompleto()),
                $this->campo('nombre', 'Nombre(s)', $candidato->nombre),
                $this->campo('apellidos', 'Apellidos', $candidato->apellidos),
                $this->campo('fecha_nacimiento', 'Fecha de nacimiento', null),
                $this->campo('curp', 'CURP', null),
                $this->campo('rfc', 'RFC', null),
                $this->campo('nss', 'NSS', null),
                $this->campo('telefono', 'Teléfono', $candidato->telefono),
                $this->campo('correo', 'Correo', $candidato->correo),
                $this->campo('empresa', 'Empresa', $candidato->empresa->nombre ?? $candidato->sucursal?->empresa?->nombre),
                $this->campo('sucursal', 'Sucursal', $candidato->sucursal?->nombre),
                $this->campo('puesto', 'Puesto', $candidato->puestoObjetivo?->nombre),
                $this->campo('departamento', 'Departamento', $candidato->departamento?->nombre),
                // Timestamp en UTC: se lee como día calendario de México.
                $this->fecha('fecha_ingreso', 'Fecha de ingreso', $candidato->contratado_en?->clone()->setTimezone(FechaMexico::ZONA)),
                $this->campo('estatus', 'Estatus', $candidato->etiquetaEstado()),
            ],
        ];
    }

    /**
     * @return array{clave: string, etiqueta: string, valor: string, copiar: string, capturado: bool}
     */
    private function campo(string $clave, string $etiqueta, ?string $valor): array
    {
        $texto = trim((string) $valor);
        $capturado = $texto !== '';

        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'valor' => $capturado ? $texto : self::SIN_CAPTURAR,
            'copiar' => $texto,
            'capturado' => $capturado,
        ];
    }

    /**
     * «15 de agosto de 1990 · 15/08/1990»; al copiar el campo solo va la
     * numérica (la que piden los formularios de otros sistemas).
     *
     * @return array{clave: string, etiqueta: string, valor: string, copiar: string, capturado: bool}
     */
    private function fecha(string $clave, string $etiqueta, ?CarbonInterface $fecha): array
    {
        if ($fecha === null) {
            return $this->campo($clave, $etiqueta, null);
        }

        $numerica = $fecha->format('d/m/Y');

        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'valor' => sprintf('%d de %s de %d · %s', $fecha->day, self::MESES[$fecha->month - 1], $fecha->year, $numerica),
            'copiar' => $numerica,
            'capturado' => true,
        ];
    }
}
