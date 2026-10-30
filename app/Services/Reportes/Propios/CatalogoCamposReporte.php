<?php

namespace App\Services\Reportes\Propios;

use App\Enums\EstatusBaja;
use App\Models\Baja;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Incapacidades\IncapacidadConsultaService;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Catálogo cerrado de «Mis reportes»: qué fuentes existen, qué campos ofrece
 * cada una, qué permiso exige cada campo y de dónde sale su valor. No hay SQL
 * libre ni expresiones: un reporte solo puede referirse a claves de esta lista.
 * Cada fuente consulta con AlcanceOrganizacionalService, el mismo scoping del
 * resto del sistema.
 *
 * @phpstan-type Campo array{etiqueta: string, categoria: string, tipo: string, permiso: string|null, valor: Closure}
 * @phpstan-type Fuente array{etiqueta: string, permiso: string, campos: array<string, Campo>, consulta: Closure(User): iterable<int, Model>}
 */
class CatalogoCamposReporte
{
    public const PERMISO_SENSIBLE = 'reportes_rh.datos_sensibles';

    public const PERMISO_SALARIO = 'salarios.ver';

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly IncapacidadConsultaService $incapacidades,
    ) {}

    /**
     * @return array<string, Fuente>
     */
    public function fuentes(): array
    {
        return [
            'colaboradores' => [
                'etiqueta' => 'Colaboradores',
                'permiso' => 'reportes_rh.mis_reportes',
                'campos' => $this->camposColaborador(),
                'consulta' => fn (User $u): Collection => $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $u)
                    ->with(['sucursalPrincipal.empresa', 'departamento', 'puesto', 'jefe'])
                    ->get(),
            ],
            'bajas' => [
                'etiqueta' => 'Bajas',
                'permiso' => 'bajas.ver',
                'campos' => $this->camposBaja(),
                'consulta' => fn (User $u): Collection => $this->alcance->limitarPorSucursal(Baja::query(), $u)
                    ->where('estatus', '!=', EstatusBaja::Cancelada->value)
                    ->with(['sucursal', 'puesto', 'causal'])
                    ->get(),
            ],
            'incapacidades' => [
                'etiqueta' => 'Incapacidades',
                'permiso' => 'incapacidades.ver',
                'campos' => $this->camposIncapacidad(),
                'consulta' => fn (User $u): Collection => $this->alcance->limitarPorSucursal(Incapacidad::query(), $u)
                    ->whereNull('cancelada_en')
                    ->with(['colaborador', 'sucursal'])
                    ->get(),
            ],
            'solicitudes' => [
                'etiqueta' => 'Solicitudes',
                'permiso' => 'solicitudes.revisar',
                'campos' => $this->camposSolicitud(),
                'consulta' => fn (User $u): Collection => $this->alcance->limitarPorSucursal(SolicitudInterna::query(), $u)
                    ->with(['usuario', 'sucursal'])
                    ->get(),
            ],
            'candidatos' => [
                'etiqueta' => 'Candidatos',
                'permiso' => 'candidatos.ver',
                'campos' => $this->camposCandidato(),
                'consulta' => fn (User $u): Collection => $this->alcance->limitarPorSucursal(Candidato::query(), $u)
                    ->with(['empresa', 'sucursal', 'departamento', 'puestoObjetivo'])
                    ->get(),
            ],
        ];
    }

    /**
     * Catálogo que ve este usuario: las fuentes que tiene permitidas y, en cada
     * una, solo los campos que puede consultar. Sin los cierres de valor.
     *
     * @return list<array{clave: string, etiqueta: string, campos: list<array{clave: string, etiqueta: string, categoria: string, tipo: string, restringido: bool}>}>
     */
    public function paraUsuario(User $usuario): array
    {
        $resultado = [];

        foreach ($this->fuentes() as $clave => $fuente) {
            if (! $usuario->can($fuente['permiso'])) {
                continue;
            }

            $campos = [];

            foreach ($fuente['campos'] as $claveCampo => $campo) {
                if ($this->puedeCampo($usuario, $campo)) {
                    $campos[] = [
                        'clave' => $claveCampo,
                        'etiqueta' => $campo['etiqueta'],
                        'categoria' => $campo['categoria'],
                        'tipo' => $campo['tipo'],
                        'restringido' => $campo['permiso'] !== null,
                    ];
                }
            }

            $resultado[] = ['clave' => $clave, 'etiqueta' => $fuente['etiqueta'], 'campos' => $campos];
        }

        return $resultado;
    }

    /**
     * @param  Campo  $campo
     */
    public function puedeCampo(User $usuario, array $campo): bool
    {
        return $campo['permiso'] === null || $usuario->can($campo['permiso']);
    }

    /**
     * @return array<string, Campo>
     */
    private function camposColaborador(): array
    {
        $personales = 'Datos personales';
        $puesto = 'Puesto y ubicación';
        $fechas = 'Fechas';
        $contacto = 'Contacto';
        $domicilio = 'Domicilio';
        $laboral = 'Situación laboral';
        $sueldo = 'Sueldo';
        $sensible = self::PERMISO_SENSIBLE;

        return [
            'nombre_completo' => $this->campo('Nombre completo', $personales, fn (Colaborador $c) => $c->nombreCompleto()),
            'nombre' => $this->campo('Nombre(s)', $personales, fn (Colaborador $c) => $c->name),
            'apellidos' => $this->campo('Apellidos', $personales, fn (Colaborador $c) => $c->apellidos),
            'numero_empleado' => $this->campo('Número de empleado', $personales, fn (Colaborador $c) => $c->numero_empleado),
            'rfc' => $this->campo('RFC', $personales, fn (Colaborador $c) => $c->rfc, permiso: $sensible),
            'curp' => $this->campo('CURP', $personales, fn (Colaborador $c) => $c->curp, permiso: $sensible),
            'nss' => $this->campo('NSS', $personales, fn (Colaborador $c) => $c->nss, permiso: $sensible),
            'clave_elector' => $this->campo('Clave de elector', $personales, fn (Colaborador $c) => $c->clave_elector, permiso: $sensible),
            'fecha_nacimiento' => $this->campo('Fecha de nacimiento', $personales, fn (Colaborador $c) => $this->fecha($c->fecha_nacimiento), 'fecha'),
            'sexo' => $this->campo('Sexo', $personales, fn (Colaborador $c) => $c->genero?->etiqueta()),
            'lugar_nacimiento' => $this->campo('Lugar de nacimiento', $personales, fn (Colaborador $c) => $c->lugar_nacimiento),
            'nacionalidad' => $this->campo('Nacionalidad', $personales, fn (Colaborador $c) => $c->nacionalidad),
            'estado_civil' => $this->campo('Estado civil', $personales, fn (Colaborador $c) => $c->estado_civil?->etiqueta()),
            'profesion' => $this->campo('Profesión', $personales, fn (Colaborador $c) => $c->profesion),
            'puesto' => $this->campo('Puesto', $puesto, fn (Colaborador $c) => $c->puesto?->nombre),
            'departamento' => $this->campo('Departamento', $puesto, fn (Colaborador $c) => $c->departamento?->nombre),
            'sucursal' => $this->campo('Sucursal', $puesto, fn (Colaborador $c) => $c->sucursalPrincipal?->nombre),
            'empresa' => $this->campo('Empresa', $puesto, fn (Colaborador $c) => $c->sucursalPrincipal?->empresa?->nombre),
            'registro_patronal' => $this->campo('Registro patronal', $puesto, fn (Colaborador $c) => $c->sucursalPrincipal?->empresa?->registro_patronal),
            'jefe' => $this->campo('Jefe inmediato', $puesto, fn (Colaborador $c) => $c->jefe?->nombreCompleto()),
            'fecha_ingreso' => $this->campo('Fecha de ingreso', $fechas, fn (Colaborador $c) => $this->fecha($c->fecha_ingreso), 'fecha'),
            'fecha_alta_imss' => $this->campo('Fecha de alta IMSS', $fechas, fn (Colaborador $c) => $this->fecha($c->fecha_alta_imss), 'fecha'),
            'periodo_prueba_inicio' => $this->campo('Inicio de periodo de prueba', $fechas, fn (Colaborador $c) => $this->fecha($c->periodo_prueba_inicio), 'fecha'),
            'periodo_prueba_fin' => $this->campo('Fin de periodo de prueba', $fechas, fn (Colaborador $c) => $this->fecha($c->periodo_prueba_fin), 'fecha'),
            'fecha_baja' => $this->campo('Fecha de baja', $fechas, fn (Colaborador $c) => $this->fecha($c->fecha_baja), 'fecha'),
            'telefono' => $this->campo('Teléfono', $contacto, fn (Colaborador $c) => $c->telefono, permiso: $sensible),
            'telefono_corporativo' => $this->campo('Teléfono corporativo', $contacto, fn (Colaborador $c) => $c->telefono_corporativo),
            'correo_corporativo' => $this->campo('Correo corporativo', $contacto, fn (Colaborador $c) => $c->correo_corporativo),
            'correo_personal' => $this->campo('Correo personal', $contacto, fn (Colaborador $c) => $c->correo_personal, permiso: $sensible),
            'contacto_emergencia' => $this->campo('Contacto de emergencia', $contacto, fn (Colaborador $c) => $c->contacto_emergencia_nombre, permiso: $sensible),
            'contacto_emergencia_telefono' => $this->campo('Teléfono de emergencia', $contacto, fn (Colaborador $c) => $c->contacto_emergencia_telefono, permiso: $sensible),
            'beneficiario' => $this->campo('Beneficiario', $contacto, fn (Colaborador $c) => $c->beneficiario_nombre, permiso: $sensible),
            'domicilio' => $this->campo('Domicilio completo', $domicilio, fn (Colaborador $c) => $c->domicilio, permiso: $sensible),
            'domicilio_colonia' => $this->campo('Colonia', $domicilio, fn (Colaborador $c) => $c->domicilio_colonia, permiso: $sensible),
            'domicilio_municipio' => $this->campo('Municipio', $domicilio, fn (Colaborador $c) => $c->domicilio_municipio, permiso: $sensible),
            'domicilio_estado' => $this->campo('Estado', $domicilio, fn (Colaborador $c) => $c->domicilio_estado, permiso: $sensible),
            'domicilio_cp' => $this->campo('Código postal', $domicilio, fn (Colaborador $c) => $c->domicilio_cp, permiso: $sensible),
            'estatus' => $this->campo('Estatus', $laboral, fn (Colaborador $c) => $c->estatus->etiqueta()),
            'estatus_imss' => $this->campo('Estatus IMSS', $laboral, fn (Colaborador $c) => $c->estatus_imss->etiqueta()),
            'tipo_contratacion' => $this->campo('Tipo de contrato', $laboral, fn (Colaborador $c) => $c->tipo_contratacion?->etiqueta()),
            'sueldo_mensual' => $this->campo('Sueldo mensual', $sueldo, fn (Colaborador $c) => $this->numero($c->sueldo_mensual), 'moneda', self::PERMISO_SALARIO),
            'salario_diario' => $this->campo('Salario diario', $sueldo, fn (Colaborador $c) => $this->numero($c->salario_diario), 'moneda', self::PERMISO_SALARIO),
            'salario_diario_integrado' => $this->campo('Salario diario integrado', $sueldo, fn (Colaborador $c) => $this->numero($c->salario_diario_integrado), 'moneda', self::PERMISO_SALARIO),
        ];
    }

    /**
     * @return array<string, Campo>
     */
    private function camposBaja(): array
    {
        $general = 'Colaborador';
        $salida = 'Salida';

        return [
            'folio' => $this->campo('Folio', $salida, fn (Baja $b) => $b->folio),
            'nombre' => $this->campo('Nombre completo', $general, fn (Baja $b) => $b->nombre_colaborador),
            'numero_empleado' => $this->campo('Número de empleado', $general, fn (Baja $b) => $b->numero_empleado),
            'sucursal' => $this->campo('Sucursal', $general, fn (Baja $b) => $b->sucursal?->nombre),
            'puesto' => $this->campo('Puesto', $general, fn (Baja $b) => $b->puesto?->nombre),
            'fecha_ingreso' => $this->campo('Fecha de ingreso', $general, fn (Baja $b) => $this->fecha($b->fecha_ingreso), 'fecha'),
            'fecha_baja' => $this->campo('Último día', $salida, fn (Baja $b) => $this->fecha($b->fecha_baja), 'fecha'),
            'antiguedad_dias' => $this->campo('Antigüedad (días)', $salida, fn (Baja $b) => $b->antiguedad_dias, 'numero'),
            'forma_salida' => $this->campo('Forma de salida', $salida, fn (Baja $b) => $b->forma_salida?->etiqueta()),
            'causal' => $this->campo('Causal', $salida, fn (Baja $b) => $b->causal?->nombre),
            'estatus' => $this->campo('Estatus', $salida, fn (Baja $b) => $b->estatus->etiqueta()),
            'recontratable' => $this->campo('Recontratable', $salida, fn (Baja $b) => $b->recontratable?->etiqueta()),
            'comentarios' => $this->campo('Comentarios', $salida, fn (Baja $b) => $b->comentarios, permiso: self::PERMISO_SENSIBLE),
        ];
    }

    /**
     * @return array<string, Campo>
     */
    private function camposIncapacidad(): array
    {
        $general = 'Colaborador';
        $datos = 'Incapacidad';

        return [
            'colaborador' => $this->campo('Nombre completo', $general, fn (Incapacidad $i) => $i->colaborador->nombreCompleto()),
            'sucursal' => $this->campo('Sucursal', $general, fn (Incapacidad $i) => $i->sucursal?->nombre),
            'tipo' => $this->campo('Tipo', $datos, fn (Incapacidad $i) => $i->tipo->etiqueta()),
            'subtipo' => $this->campo('Subtipo de riesgo', $datos, fn (Incapacidad $i) => $i->subtipo_riesgo?->etiqueta()),
            'secuencia' => $this->campo('Inicial o subsecuente', $datos, fn (Incapacidad $i) => $i->secuencia->etiqueta()),
            'folio' => $this->campo('Serie y folio IMSS', $datos, fn (Incapacidad $i) => $i->serie_folio_imss),
            'fecha_inicio' => $this->campo('Inicio', $datos, fn (Incapacidad $i) => $this->fecha($i->fecha_inicio), 'fecha'),
            'dias' => $this->campo('Días', $datos, fn (Incapacidad $i) => $i->dias, 'numero'),
            'fecha_fin' => $this->campo('Término', $datos, fn (Incapacidad $i) => $this->fecha($i->fecha_fin), 'fecha'),
            'estatus' => $this->campo('Estatus', $datos, fn (Incapacidad $i) => $this->incapacidades->estatus($i)->etiqueta()),
        ];
    }

    /**
     * @return array<string, Campo>
     */
    private function camposSolicitud(): array
    {
        $general = 'Colaborador';
        $datos = 'Solicitud';

        return [
            'folio' => $this->campo('Folio', $datos, fn (SolicitudInterna $s) => $s->folio),
            'colaborador' => $this->campo('Nombre completo', $general, fn (SolicitudInterna $s) => trim(sprintf('%s %s', $s->usuario->name ?? '', $s->usuario->apellidos ?? ''))),
            'sucursal' => $this->campo('Sucursal', $general, fn (SolicitudInterna $s) => $s->sucursal?->nombre),
            'tipo' => $this->campo('Tipo', $datos, fn (SolicitudInterna $s) => $s->tipo->etiqueta()),
            'estado' => $this->campo('Estado', $datos, fn (SolicitudInterna $s) => $s->estado->etiqueta()),
            'fecha_inicio' => $this->campo('Inicio', $datos, fn (SolicitudInterna $s) => $this->fecha($s->fecha_inicio), 'fecha'),
            'fecha_fin' => $this->campo('Fin', $datos, fn (SolicitudInterna $s) => $this->fecha($s->fecha_fin), 'fecha'),
            'dias' => $this->campo('Días solicitados', $datos, fn (SolicitudInterna $s) => $s->dias_solicitados, 'numero'),
            'monto' => $this->campo('Monto solicitado', $datos, fn (SolicitudInterna $s) => $this->numero($s->monto_solicitado), 'moneda', self::PERMISO_SENSIBLE),
            'fecha_captura' => $this->campo('Fecha de captura', $datos, fn (SolicitudInterna $s) => $this->fecha($s->created_at), 'fecha'),
        ];
    }

    /**
     * @return array<string, Campo>
     */
    private function camposCandidato(): array
    {
        $personales = 'Candidato';
        $proceso = 'Proceso';

        return [
            'nombre_completo' => $this->campo('Nombre completo', $personales, fn (Candidato $c) => $c->nombreCompleto()),
            'nombre' => $this->campo('Nombre(s)', $personales, fn (Candidato $c) => $c->nombre),
            'apellidos' => $this->campo('Apellidos', $personales, fn (Candidato $c) => $c->apellidos),
            'telefono' => $this->campo('Teléfono', $personales, fn (Candidato $c) => $c->telefono, permiso: self::PERMISO_SENSIBLE),
            'correo' => $this->campo('Correo', $personales, fn (Candidato $c) => $c->correo, permiso: self::PERMISO_SENSIBLE),
            'empresa' => $this->campo('Empresa', $proceso, fn (Candidato $c) => $c->empresa?->nombre),
            'sucursal' => $this->campo('Sucursal', $proceso, fn (Candidato $c) => $c->sucursal?->nombre),
            'departamento' => $this->campo('Departamento', $proceso, fn (Candidato $c) => $c->departamento?->nombre),
            'puesto' => $this->campo('Puesto objetivo', $proceso, fn (Candidato $c) => $c->puestoObjetivo?->nombre),
            'estado' => $this->campo('Estado', $proceso, fn (Candidato $c) => $c->estado->etiqueta()),
            'fuente' => $this->campo('Fuente', $proceso, fn (Candidato $c) => $c->fuente),
            'fecha_captura' => $this->campo('Fecha de registro', $proceso, fn (Candidato $c) => $this->fecha($c->created_at), 'fecha'),
            'fecha_entrevista' => $this->campo('Fecha de entrevista', $proceso, fn (Candidato $c) => $this->fecha($c->fecha_entrevista), 'fecha'),
            'contratado_en' => $this->campo('Fecha de contratación', $proceso, fn (Candidato $c) => $this->fecha($c->contratado_en), 'fecha'),
        ];
    }

    /**
     * @return Campo
     */
    private function campo(string $etiqueta, string $categoria, Closure $valor, string $tipo = 'texto', ?string $permiso = null): array
    {
        return ['etiqueta' => $etiqueta, 'categoria' => $categoria, 'tipo' => $tipo, 'permiso' => $permiso, 'valor' => $valor];
    }

    private function fecha(?CarbonInterface $fecha): ?string
    {
        return $fecha?->format('Y-m-d');
    }

    private function numero(string|int|float|null $valor): ?float
    {
        return $valor === null ? null : (float) $valor;
    }
}
