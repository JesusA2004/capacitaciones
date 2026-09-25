<?php

namespace App\Services\Plantillas;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Prestamo;
use App\Services\Formatos\Variables\ContextoFormato;
use App\Services\Formatos\Variables\FormateadorValores;
use App\Services\Formatos\Variables\ResolvedorVariablesFormato;

/**
 * Unica fuente de verdad de que placeholder mapea a que dato (ver
 * claude/formatos/placeholders/PLACEHOLDERS.md). Si se agrega un placeholder
 * nuevo, debe registrarse aqui primero.
 *
 * El sujeto de un formato es un App\Models\Colaborador (persona/empleo) o un
 * App\Models\Candidato — nunca App\Models\User (cuenta de acceso, sin datos
 * personales/de empleo desde la separacion Usuario/Colaborador, ver
 * docs/ROLES_Y_NAVEGACION.md).
 */
class PlaceholderResolver
{
    public function __construct(
        private readonly ResolvedorVariablesFormato $variables,
        private readonly FormateadorValores $formateador,
    ) {}

    /**
     * @param  array<string, mixed>  $extra  Placeholders adicionales especificos de una solicitud (fecha_inicio_permiso, motivo_permiso, etc.), fusionados sobre los calculados.
     * @return array<string, string>
     */
    public function resolver(Colaborador|Candidato|null $sujeto, array $extra = []): array
    {
        $base = [
            'nombre_colaborador' => '',
            'apellidos_colaborador' => '',
            'nombre_completo' => '',
            'numero_empleado' => '',
            'curp' => '',
            'rfc' => '',
            'nss' => '',
            'domicilio' => '',
            'telefono' => '',
            'telefono_corporativo' => '',
            'correo' => '',
            'correo_personal' => '',
            'correo_corporativo' => '',
            'empresa' => '',
            'sucursal' => '',
            'departamento' => '',
            'puesto' => '',
            'jefe_directo' => '',
            'fecha_ingreso' => '',
            'fecha_actual' => now()->format('d/m/Y'),
            'sueldo_mensual' => '',
            'sueldo_diario' => '',
            'dias_vacaciones' => '',
            'fecha_inicio_permiso' => '',
            'fecha_fin_permiso' => '',
            'motivo_permiso' => '',
            'tipo_solicitud' => '',
            'folio_solicitud' => '',
            'monto_prestamo' => '',
            'plazo_prestamo' => '',
            'pago_prestamo' => '',
            'saldo_prestamo' => '',
            'fecha_inicio_incapacidad' => '',
            'fecha_fin_incapacidad' => '',
            'motivo_solicitud' => '',
            'observaciones' => '',
            // Ciclo contractual (docs/backend-rh-completion.md).
            'gerente' => '',
            'fecha_nacimiento' => '',
            'empresa_razon_social' => '',
            'empresa_rfc' => '',
            'tipo_contratacion' => '',
            'periodo_prueba_inicio' => '',
            'periodo_prueba_fin' => '',
            'fecha_inicio_contrato' => '',
            'fecha_fin_contrato' => '',
            // Préstamo autorizado (PrestamoAutorizacionService).
            'monto_solicitado_prestamo' => '',
            'periodicidad_prestamo' => '',
            'motivo_prestamo' => '',
            // Cierre laboral (CierreLaboralService).
            'tipo_baja' => '',
            'motivo_baja' => '',
            'fecha_baja' => '',
            // Actas (ActaService).
            'folio_acta' => '',
            'tipo_acta' => '',
            'fecha_acta' => '',
            'hora_acta' => '',
            'lugar_acta' => '',
            'hechos_acta' => '',
            'responsable_acta' => '',
            'testigos_acta' => '',
            'declaraciones_acta' => '',
        ];

        if ($sujeto instanceof Colaborador) {
            $base = [
                ...$base,
                ...$this->datosColaborador($sujeto),
                ...$this->placeholdersPrestamo($sujeto),
            ];
        }

        if ($sujeto instanceof Candidato) {
            $base = [
                ...$base,
                'nombre_colaborador' => (string) $sujeto->nombre,
                'apellidos_colaborador' => (string) $sujeto->apellidos,
                'nombre_completo' => $sujeto->nombreCompleto(),
                'telefono' => (string) $sujeto->telefono,
                'correo' => (string) $sujeto->correo,
                'empresa' => (string) $sujeto->empresa?->nombre,
                'sucursal' => (string) $sujeto->sucursal?->nombre,
                'departamento' => (string) $sujeto->departamento?->nombre,
                'puesto' => (string) $sujeto->puestoObjetivo?->nombre,
            ];
        }

        foreach ($extra as $clave => $valor) {
            $base[$clave] = (string) $valor;
        }

        return $base;
    }

    /**
     * Los datos del colaborador salen del resolvedor central de variables
     * (App\Services\Formatos\Variables\ResolvedorVariablesFormato), el mismo
     * que usan las plantillas oficiales: aquí solo se traducen a las claves
     * legacy {{curp}}, {{puesto}}… con su formato histórico (d/m/Y, $0.00).
     *
     * @return array<string, string>
     */
    private function datosColaborador(Colaborador $sujeto): array
    {
        $v = $this->variables->resolver(new ContextoFormato($sujeto));
        $texto = fn (string $clave): string => $this->formateador->formatear($v[$clave] ?? null, 'texto');
        $fecha = fn (string $clave): string => $this->formateador->formatear($v[$clave] ?? null, 'fecha', 'corta');
        $moneda = fn (string $clave): string => $this->formateador->formatear($v[$clave] ?? null, 'moneda', 'moneda');

        return [
            'nombre_colaborador' => $texto('colaborador.nombre'),
            'apellidos_colaborador' => $texto('colaborador.apellidos'),
            'nombre_completo' => $texto('colaborador.nombre_completo'),
            'numero_empleado' => $texto('laboral.numero_empleado'),
            'curp' => $texto('colaborador.curp'),
            'rfc' => $texto('colaborador.rfc'),
            'nss' => $texto('colaborador.nss'),
            'domicilio' => $texto('colaborador.domicilio'),
            'telefono' => $texto('colaborador.telefono'),
            'telefono_corporativo' => (string) $sujeto->telefono_corporativo,
            'correo' => $texto('colaborador.correo'),
            'correo_personal' => $texto('colaborador.correo_personal'),
            'correo_corporativo' => (string) ($sujeto->user->email ?? ''),
            'empresa' => $texto('empresa.nombre'),
            'sucursal' => $texto('laboral.sucursal'),
            'departamento' => $texto('laboral.departamento'),
            'puesto' => $texto('laboral.puesto'),
            'jefe_directo' => $texto('laboral.jefe'),
            'fecha_ingreso' => $fecha('laboral.fecha_ingreso'),
            'sueldo_mensual' => $moneda('laboral.sueldo_mensual'),
            'sueldo_diario' => $moneda('laboral.sueldo_diario'),
            'gerente' => $texto('laboral.gerente'),
            'fecha_nacimiento' => $fecha('colaborador.fecha_nacimiento'),
            'empresa_razon_social' => $texto('empresa.razon_social'),
            'empresa_rfc' => $texto('empresa.rfc'),
            'tipo_contratacion' => $texto('laboral.tipo_contratacion'),
            'periodo_prueba_inicio' => $fecha('laboral.periodo_prueba_inicio'),
            'periodo_prueba_fin' => $fecha('laboral.periodo_prueba_fin'),
        ];
    }

    /**
     * Placeholders del préstamo interno activo del colaborador
     * (monto_prestamo, plazo_prestamo, pago_prestamo, saldo_prestamo). Ver
     * App\Models\Prestamo / App\Services\Nomina\PrestamoService — nace en
     * 'pendiente_entrega', pasa a 'activo' al confirmar la entrega y a
     * 'liquidado' cuando el saldo llega a 0; solo el préstamo 'activo' (si
     * hay más de uno histórico, el más reciente) alimenta el contrato.
     *
     * @return array<string, string>
     */
    private function placeholdersPrestamo(Colaborador $sujeto): array
    {
        $vacio = [
            'monto_prestamo' => '',
            'plazo_prestamo' => '',
            'pago_prestamo' => '',
            'saldo_prestamo' => '',
        ];

        $prestamo = Prestamo::query()
            ->where('colaborador_id', $sujeto->id)
            ->where('estado', 'activo')
            ->latest()
            ->first();

        if ($prestamo === null) {
            return $vacio;
        }

        return [
            'monto_prestamo' => sprintf('$%s', number_format((float) $prestamo->monto_original, 2)),
            'plazo_prestamo' => sprintf('%s', $prestamo->plazo),
            'pago_prestamo' => sprintf('$%s', number_format((float) $prestamo->pago_programado, 2)),
            'saldo_prestamo' => sprintf('$%s', number_format((float) $prestamo->saldo, 2)),
        ];
    }
}
