<?php

namespace App\Services\Plantillas;

use App\Models\Candidato;
use App\Models\Colaborador;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
     * @return array<string, string>
     */
    private function datosColaborador(Colaborador $sujeto): array
    {
        $correoCorporativo = $sujeto->user?->email;
        $sueldoMensual = $sujeto->sueldo_mensual !== null ? (float) $sujeto->sueldo_mensual : null;

        return [
            'nombre_colaborador' => (string) $sujeto->name,
            'apellidos_colaborador' => (string) $sujeto->apellidos,
            'nombre_completo' => $sujeto->nombreCompleto(),
            'numero_empleado' => (string) $sujeto->numero_empleado,
            'curp' => (string) $sujeto->curp,
            'rfc' => (string) $sujeto->rfc,
            'nss' => (string) $sujeto->nss,
            'domicilio' => (string) $sujeto->domicilio,
            'telefono' => (string) $sujeto->telefono,
            'telefono_corporativo' => (string) $sujeto->telefono_corporativo,
            'correo' => (string) ($correoCorporativo ?? $sujeto->correo_personal ?? ''),
            'correo_personal' => (string) $sujeto->correo_personal,
            'correo_corporativo' => (string) ($correoCorporativo ?? ''),
            'empresa' => (string) $sujeto->empresa()?->nombre,
            'sucursal' => (string) $sujeto->sucursalPrincipal?->nombre,
            'departamento' => (string) $sujeto->departamento?->nombre,
            'puesto' => (string) $sujeto->puesto?->nombre,
            'jefe_directo' => $sujeto->jefe?->nombreCompleto() ?? '',
            'fecha_ingreso' => $sujeto->fecha_ingreso?->format('d/m/Y') ?? '',
            'sueldo_mensual' => $sueldoMensual !== null ? sprintf('$%s', number_format($sueldoMensual, 2)) : '',
            'sueldo_diario' => $sueldoMensual !== null ? sprintf('$%s', number_format($sueldoMensual / 30, 2)) : '',
        ];
    }

    /**
     * Placeholders del prestamo interno activo del colaborador
     * (monto_prestamo, plazo_prestamo, pago_prestamo, saldo_prestamo).
     * App\Models\Prestamo se esta construyendo en paralelo a este cambio y
     * puede no existir todavia cuando esto se ejecute: se protege con
     * class_exists() y nunca lanza — sin clase, sin prestamo activo o con
     * columnas distintas a las esperadas, cada placeholder resuelve a cadena
     * vacia en vez de tronar la generacion del documento.
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

        if (! class_exists(\App\Models\Prestamo::class)) {
            return $vacio;
        }

        try {
            $modelo = new \App\Models\Prestamo;
            $tabla = $modelo->getTable();

            $query = \App\Models\Prestamo::query()->where('colaborador_id', $sujeto->id);

            if (Schema::hasColumn($tabla, 'activo')) {
                $query->where('activo', true);
            } elseif (Schema::hasColumn($tabla, 'estatus')) {
                $query->where('estatus', 'activo');
            }

            $prestamo = $query->latest()->first();
        } catch (Throwable) {
            return $vacio;
        }

        if ($prestamo === null) {
            return $vacio;
        }

        $tabla = $prestamo->getTable();

        return [
            'monto_prestamo' => Schema::hasColumn($tabla, 'monto') && $prestamo->getAttribute('monto') !== null
                ? sprintf('$%s', number_format((float) $prestamo->getAttribute('monto'), 2))
                : '',
            'plazo_prestamo' => Schema::hasColumn($tabla, 'plazo_meses') && $prestamo->getAttribute('plazo_meses') !== null
                ? sprintf('%s', $prestamo->getAttribute('plazo_meses'))
                : '',
            'pago_prestamo' => Schema::hasColumn($tabla, 'pago_mensual') && $prestamo->getAttribute('pago_mensual') !== null
                ? sprintf('$%s', number_format((float) $prestamo->getAttribute('pago_mensual'), 2))
                : '',
            'saldo_prestamo' => Schema::hasColumn($tabla, 'saldo') && $prestamo->getAttribute('saldo') !== null
                ? sprintf('$%s', number_format((float) $prestamo->getAttribute('saldo'), 2))
                : '',
        ];
    }
}
