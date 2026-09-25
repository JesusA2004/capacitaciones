<?php

namespace App\Services\Formatos\Variables;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Finiquitos\FiniquitoService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Calcula el valor CRUDO de cada variable de CatalogoVariablesFormato para
 * un ContextoFormato (fechas como Carbon, montos como float, el resto
 * texto). El formato de impresión (fecha larga, monto en letra…) lo aplica
 * FormateadorValores campo por campo.
 *
 * Única implementación de "de dónde sale cada dato": la usan las plantillas
 * oficiales (overlay y DOCX) y App\Services\Plantillas\PlaceholderResolver
 * (placeholders legacy {{curp}}, {{puesto}}…) para no duplicar la lógica.
 */
class ResolvedorVariablesFormato
{
    public function __construct(
        private readonly DocumentoStorageService $expediente,
    ) {}

    /**
     * @return array<string, string|int|float|CarbonInterface|null>
     */
    public function resolver(ContextoFormato $contexto): array
    {
        $hoy = $contexto->fecha ?? now('America/Mexico_City');

        return [
            ...$this->sujeto($contexto, $hoy),
            ...$this->solicitud($contexto),
            ...$this->prestamo($contexto),
            ...$this->contrato($contexto),
            ...$this->finiquito($contexto),
            'fecha.actual' => $hoy,
            'otros.generado_por' => $contexto->generador?->colaborador?->nombreCompleto() ?? $contexto->generador?->name,
            'otros.referencia' => $contexto->referencia !== '' ? $contexto->referencia : null,
        ];
    }

    /**
     * Bytes de una variable de tipo imagen (foto del colaborador, logo de la
     * empresa), o null si no existe. Nunca expone la ruta.
     */
    public function imagen(string $clave, ContextoFormato $contexto): ?string
    {
        try {
            if ($clave === 'colaborador.foto') {
                $ruta = $contexto->colaborador()?->foto_path;

                return $ruta !== null && $this->expediente->existe($ruta) ? $this->expediente->disco()->get($ruta) : null;
            }

            if ($clave === 'empresa.logo') {
                $ruta = $this->empresa($contexto)?->logo_path;

                return $ruta !== null && Storage::disk('public')->exists($ruta) ? Storage::disk('public')->get($ruta) : null;
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo leer la imagen de una variable de formato.', ['clave' => $clave, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @return array<string, string|int|float|CarbonInterface|null>
     */
    private function sujeto(ContextoFormato $contexto, CarbonInterface $hoy): array
    {
        $sujeto = $contexto->sujeto;
        $empresa = $this->empresa($contexto);

        $base = [
            'empresa.nombre' => $empresa?->nombre,
            'empresa.razon_social' => $empresa->razon_social ?? $empresa?->nombre,
            'empresa.rfc' => $empresa?->rfc,
            'empresa.logo' => $empresa?->logo_path !== null ? 'imagen' : null,
        ];

        if ($sujeto instanceof Candidato) {
            return [
                ...$base,
                ...array_fill_keys($this->clavesSoloColaborador(), null),
                'colaborador.nombre_completo' => $sujeto->nombreCompleto(),
                'colaborador.nombre' => $sujeto->nombre,
                'colaborador.apellidos' => $sujeto->apellidos,
                'colaborador.telefono' => $sujeto->telefono,
                'colaborador.correo' => $sujeto->correo,
                'laboral.puesto' => $sujeto->puestoObjetivo?->nombre,
                'laboral.departamento' => $sujeto->departamento?->nombre,
                'laboral.sucursal' => $sujeto->sucursal?->nombre,
                ...$this->sucursal($sujeto->sucursal?->direccion, $sujeto->sucursal?->ciudad, $sujeto->sucursal?->estado),
            ];
        }

        if (! $sujeto instanceof Colaborador) {
            return [
                ...$base,
                ...array_fill_keys($this->clavesSoloColaborador(), null),
                'colaborador.nombre_completo' => null,
                'colaborador.nombre' => null,
                'colaborador.apellidos' => null,
                'colaborador.telefono' => null,
                'colaborador.correo' => null,
                'laboral.puesto' => null,
                'laboral.departamento' => null,
                'laboral.sucursal' => null,
                ...$this->sucursal(null, null, null),
            ];
        }

        $sucursal = $sujeto->sucursalPrincipal;
        $sueldo = $sujeto->sueldo_mensual !== null ? (float) $sujeto->sueldo_mensual : null;

        return [
            ...$base,
            'colaborador.nombre_completo' => $sujeto->nombreCompleto(),
            'colaborador.nombre' => $sujeto->name,
            'colaborador.apellidos' => $sujeto->apellidos,
            'colaborador.curp' => $sujeto->curp,
            'colaborador.rfc' => $sujeto->rfc,
            'colaborador.nss' => $sujeto->nss,
            'colaborador.fecha_nacimiento' => $sujeto->fecha_nacimiento,
            // Diferencia exacta de calendario (no días/365).
            'colaborador.edad' => $sujeto->fecha_nacimiento !== null ? (int) $sujeto->fecha_nacimiento->diffInYears($hoy) : null,
            'colaborador.genero' => $sujeto->genero?->etiqueta(),
            'colaborador.domicilio' => $sujeto->domicilio,
            'colaborador.telefono' => $sujeto->telefono,
            'colaborador.correo' => $sujeto->user->email ?? $sujeto->correo_personal,
            'colaborador.correo_personal' => $sujeto->correo_personal,
            'colaborador.contacto_emergencia' => $sujeto->contacto_emergencia_nombre,
            'colaborador.contacto_emergencia_telefono' => $sujeto->contacto_emergencia_telefono,
            'colaborador.foto' => $sujeto->foto_path !== null ? 'imagen' : null,
            'laboral.numero_empleado' => $sujeto->numero_empleado,
            'laboral.fecha_ingreso' => $sujeto->fecha_ingreso,
            'laboral.antiguedad' => $sujeto->fecha_ingreso !== null ? $this->antiguedad($sujeto->fecha_ingreso, $hoy) : null,
            'laboral.puesto' => $sujeto->puesto?->nombre,
            'laboral.departamento' => $sujeto->departamento?->nombre,
            'laboral.sucursal' => $sucursal?->nombre,
            ...$this->sucursal($sucursal?->direccion, $sucursal?->ciudad, $sucursal?->estado),
            'laboral.jefe' => $sujeto->jefe?->nombreCompleto(),
            'laboral.gerente' => ($sujeto->gerente ?? $sujeto->jefe?->jefe)?->nombreCompleto(),
            'laboral.tipo_contratacion' => $sujeto->tipo_contratacion?->etiqueta(),
            'laboral.periodo_prueba_inicio' => $sujeto->periodo_prueba_inicio,
            'laboral.periodo_prueba_fin' => $sujeto->periodo_prueba_fin,
            'laboral.fecha_alta_imss' => $sujeto->fecha_alta_imss,
            'laboral.sueldo_mensual' => $sueldo,
            'laboral.sueldo_diario' => $sueldo !== null ? round($sueldo / 30, 2) : null,
        ];
    }

    /**
     * "2 años, 3 meses" por calendario exacto; "menos de un mes" si aún no
     * cumple uno.
     */
    public function antiguedad(CarbonInterface $ingreso, CarbonInterface $hoy): string
    {
        if ($ingreso->greaterThan($hoy)) {
            return '';
        }

        $diferencia = $ingreso->diff($hoy);
        $partes = [];

        if ($diferencia->y > 0) {
            $partes[] = sprintf('%d %s', $diferencia->y, $diferencia->y === 1 ? 'año' : 'años');
        }

        if ($diferencia->m > 0) {
            $partes[] = sprintf('%d %s', $diferencia->m, $diferencia->m === 1 ? 'mes' : 'meses');
        }

        return $partes === [] ? 'menos de un mes' : implode(', ', $partes);
    }

    /**
     * @return array<string, string|null>
     */
    private function sucursal(?string $direccion, ?string $ciudad, ?string $estado): array
    {
        $ciudadEstado = implode(', ', array_filter([$ciudad, $estado], fn (?string $v) => $v !== null && trim($v) !== ''));

        return [
            'laboral.sucursal_domicilio' => $direccion,
            'laboral.sucursal_ciudad' => $ciudad,
            'laboral.sucursal_estado' => $estado,
            'laboral.sucursal_ciudad_estado' => $ciudadEstado !== '' ? $ciudadEstado : null,
        ];
    }

    /**
     * @return array<string, string|int|float|CarbonInterface|null>
     */
    private function solicitud(ContextoFormato $contexto): array
    {
        $s = $contexto->solicitud;

        return [
            'solicitud.folio' => $s?->folio,
            'solicitud.tipo' => $s?->tipo->etiqueta(),
            'solicitud.estado' => $s?->estado->etiqueta(),
            'solicitud.fecha' => $s?->created_at,
            'solicitud.fecha_inicio' => $s?->fecha_inicio,
            'solicitud.fecha_fin' => $s?->fecha_fin,
            'solicitud.dias' => $s?->dias_solicitados,
            'solicitud.motivo' => $s?->motivo,
            'solicitud.observaciones' => $s?->observaciones,
            'solicitud.monto' => $s?->monto_solicitado,
            'solicitud.plazo_meses' => $s?->plazo_meses,
            'solicitud.fecha_resolucion' => $s?->revisado_en,
            'solicitud.autorizo' => $s?->revisadoPor?->colaborador?->nombreCompleto() ?? $s?->revisadoPor?->name,
        ];
    }

    /**
     * @return array<string, string|int|float|CarbonInterface|null>
     */
    private function prestamo(ContextoFormato $contexto): array
    {
        $p = $contexto->prestamo;

        return [
            'prestamo.monto_solicitado' => $p?->monto_solicitado !== null ? (float) $p->monto_solicitado : null,
            'prestamo.monto_autorizado' => $p !== null ? (float) $p->monto_original : null,
            'prestamo.plazo' => $p?->plazo,
            'prestamo.periodicidad' => $p !== null ? ucfirst($p->periodicidad) : null,
            'prestamo.pago' => $p !== null ? (float) $p->pago_programado : null,
            'prestamo.saldo' => $p !== null ? (float) $p->saldo : null,
            'prestamo.fecha_solicitud' => $p?->fecha_solicitud,
            'prestamo.fecha_otorgamiento' => $p?->fecha_otorgamiento,
            'prestamo.fecha_primer_descuento' => $p?->fecha_primer_descuento,
            'prestamo.motivo' => $p?->motivo,
        ];
    }

    /**
     * @return array<string, string|int|float|CarbonInterface|null>
     */
    private function contrato(ContextoFormato $contexto): array
    {
        $c = $contexto->contrato;

        return [
            'contrato.tipo' => $c?->tipo->etiqueta(),
            'contrato.fecha_inicio' => $c?->fecha_inicio,
            'contrato.fecha_fin' => $c?->fecha_fin,
            'contrato.puesto' => $c?->puesto?->nombre,
            'contrato.sucursal' => $c?->sucursal?->nombre,
            'contrato.sueldo_mensual' => $c?->sueldo_mensual !== null ? (float) $c->sueldo_mensual : null,
        ];
    }

    /**
     * @return array<string, string|int|float|CarbonInterface|null>
     */
    private function finiquito(ContextoFormato $contexto): array
    {
        $f = $contexto->finiquito;
        $monto = fn (?string $valor): ?float => $f !== null && $valor !== null ? (float) $valor : null;

        return [
            'finiquito.fecha_baja' => $f?->fecha_baja,
            'finiquito.antiguedad' => $f !== null ? sprintf(
                '%d %s, %d %s',
                $f->antiguedad_anios,
                $f->antiguedad_anios === 1 ? 'año' : 'años',
                $f->antiguedad_meses,
                $f->antiguedad_meses === 1 ? 'mes' : 'meses',
            ) : null,
            'finiquito.sueldo_diario' => $monto($f?->sueldo_diario),
            'finiquito.sueldo_mensual' => $monto($f?->sueldo_mensual),
            'finiquito.sueldo_pendiente' => $monto($f?->sueldo_pendiente),
            'finiquito.vacaciones_pendientes' => $f?->vacaciones_pendientes,
            'finiquito.prima_vacacional' => $monto($f?->prima_vacacional),
            'finiquito.aguinaldo_proporcional' => $monto($f?->aguinaldo_proporcional),
            'finiquito.indemnizacion' => $monto($f?->indemnizacion),
            'finiquito.bonos_extra' => $monto($f?->bonos_extra),
            'finiquito.descuentos' => $monto($f?->descuentos),
            'finiquito.adeudos' => $monto($f?->adeudos),
            'finiquito.otros_conceptos' => $f !== null ? FiniquitoService::sumaOtrosConceptos($f->otros_conceptos) : null,
            'finiquito.total_percepciones' => $monto($f?->total_percepciones),
            'finiquito.total_deducciones' => $monto($f?->total_deducciones),
            'finiquito.total' => $monto($f?->total_ajustado),
        ];
    }

    private function empresa(ContextoFormato $contexto): ?Empresa
    {
        $sujeto = $contexto->sujeto;

        if ($sujeto instanceof Colaborador) {
            return $sujeto->empresa();
        }

        return $sujeto instanceof Candidato ? $sujeto->empresa : null;
    }

    /**
     * @return list<string>
     */
    private function clavesSoloColaborador(): array
    {
        return [
            'colaborador.curp', 'colaborador.rfc', 'colaborador.nss', 'colaborador.fecha_nacimiento',
            'colaborador.edad', 'colaborador.genero', 'colaborador.domicilio', 'colaborador.correo_personal',
            'colaborador.contacto_emergencia', 'colaborador.contacto_emergencia_telefono', 'colaborador.foto',
            'laboral.numero_empleado', 'laboral.fecha_ingreso', 'laboral.antiguedad', 'laboral.jefe',
            'laboral.gerente', 'laboral.tipo_contratacion', 'laboral.periodo_prueba_inicio',
            'laboral.periodo_prueba_fin', 'laboral.fecha_alta_imss', 'laboral.sueldo_mensual', 'laboral.sueldo_diario',
        ];
    }
}
