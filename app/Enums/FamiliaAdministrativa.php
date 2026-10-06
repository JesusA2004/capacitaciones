<?php

namespace App\Enums;

/**
 * Documentos administrativos que el sistema genera en HTML → PDF y cuyo
 * DISEÑO (no sus datos) RH administra en Documentos maestros → Documentos
 * administrativos (App\Services\DocumentosAdministrativos).
 */
enum FamiliaAdministrativa: string
{
    case ReciboNomina = 'recibo_nomina';
    case Finiquito = 'finiquito';
    case ComprobanteSolicitud = 'comprobante_solicitud';
    case ConstanciaLaboral = 'constancia_laboral';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ReciboNomina => 'Recibo de nómina',
            self::Finiquito => 'Finiquito',
            self::ComprobanteSolicitud => 'Comprobante de solicitud',
            self::ConstanciaLaboral => 'Constancia laboral',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::ReciboNomina => 'Recibo quincenal/semanal. Montos, percepciones y deducciones salen del recibo capturado por RH.',
            self::Finiquito => 'Finiquito de baja. Conceptos y montos salen del cálculo de FiniquitoService.',
            self::ComprobanteSolicitud => 'Comprobante de vacaciones y permisos aprobados.',
            self::ConstanciaLaboral => 'Constancia laboral con los datos vigentes del colaborador.',
        };
    }

    /**
     * Secciones que el diseño puede mostrar u ocultar.
     *
     * @return array<string, string>
     */
    public function secciones(): array
    {
        return match ($this) {
            self::ReciboNomina => ['datos_colaborador' => 'Datos del colaborador', 'percepciones' => 'Percepciones', 'deducciones' => 'Deducciones', 'neto' => 'Neto a pagar', 'observaciones' => 'Observaciones', 'leyenda' => 'Leyenda'],
            self::Finiquito => ['datos_colaborador' => 'Datos del colaborador', 'percepciones' => 'Percepciones', 'deducciones' => 'Deducciones', 'neto' => 'Neto a pagar', 'observaciones' => 'Comentarios del ajuste', 'notas' => 'Notas', 'leyenda' => 'Leyenda'],
            self::ComprobanteSolicitud => ['datos_colaborador' => 'Datos del colaborador', 'detalle' => 'Detalle de la solicitud', 'observaciones' => 'Observaciones', 'leyenda' => 'Leyenda'],
            self::ConstanciaLaboral => ['datos_colaborador' => 'Datos del colaborador', 'notas' => 'Nota', 'leyenda' => 'Leyenda'],
        };
    }

    /**
     * Campos que se pueden usar dentro de los textos editables (título,
     * subtítulo, leyenda, nota, pie). Las tablas de conceptos son bloques
     * fijos, nunca sustitución de texto.
     *
     * @return list<string>
     */
    public function campos(): array
    {
        $colaborador = ['colaborador.nombre', 'colaborador.numero_empleado', 'colaborador.puesto', 'colaborador.sucursal'];

        return match ($this) {
            self::ReciboNomina => [...$colaborador, 'periodo.inicio', 'periodo.fin', 'fecha_pago', 'folio', 'total_percepciones', 'total_deducciones', 'neto', 'observaciones'],
            self::Finiquito => [...$colaborador, 'folio', 'fecha_ingreso', 'fecha_baja', 'antiguedad', 'total_percepciones', 'total_deducciones', 'neto'],
            self::ComprobanteSolicitud => [...$colaborador, 'folio', 'tipo', 'periodo', 'dias'],
            self::ConstanciaLaboral => [...$colaborador, 'empresa', 'fecha_ingreso', 'lugar_fecha'],
        };
    }

    /**
     * Textos por defecto (pueden llevar {{ campo }}).
     *
     * @return array{titulo: string, subtitulo: string, leyenda: string, nota: string}
     */
    public function contenidoPorDefecto(): array
    {
        return match ($this) {
            self::ReciboNomina => [
                'titulo' => 'RECIBO DE NÓMINA',
                'subtitulo' => 'Folio {{ folio }} · Periodo {{ periodo.inicio }} — {{ periodo.fin }} · Fecha de pago {{ fecha_pago }}',
                'leyenda' => 'Recibo administrativo interno de MR. LANA PEOPLE.',
                'nota' => '',
            ],
            self::Finiquito => [
                'titulo' => 'Cálculo de finiquito',
                'subtitulo' => 'Folio {{ folio }} · Baja {{ fecha_baja }}',
                'leyenda' => 'Cálculo sujeto a validación de RH/contabilidad. Este documento no sustituye una revisión legal o contable formal antes de su entrega y pago.',
                'nota' => '',
            ],
            self::ComprobanteSolicitud => [
                'titulo' => 'Comprobante de {{ tipo }}',
                'subtitulo' => 'Folio {{ folio }}',
                'leyenda' => 'Comprobante generado por MR. LANA PEOPLE al aprobarse la solicitud.',
                'nota' => '',
            ],
            self::ConstanciaLaboral => [
                'titulo' => 'CONSTANCIA LABORAL',
                'subtitulo' => '',
                'leyenda' => '',
                'nota' => 'Se extiende la presente a petición del interesado para los fines que a éste convengan.',
            ],
        };
    }

    /**
     * Bloques de firma por defecto.
     *
     * @return list<array{label: string}>
     */
    public function firmasPorDefecto(): array
    {
        return match ($this) {
            self::ReciboNomina => [['label' => 'Firma de recibido del colaborador']],
            self::Finiquito => [['label' => 'Recursos Humanos'], ['label' => 'Firma de recibido del colaborador']],
            self::ComprobanteSolicitud => [],
            self::ConstanciaLaboral => [['label' => 'Recursos Humanos']],
        };
    }
}
