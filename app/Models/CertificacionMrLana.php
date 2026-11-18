<?php

namespace App\Models;

use App\Enums\EstadoCertificacionMrLana;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Certificación Mr. Lana de un colaborador por el formato oficial
 * RH-CC-R-001: cuándo se descargó el formato con el encabezado lleno, el
 * escaneo firmado (documento del expediente), el promedio que trae el
 * formato y el certificado emitido con su folio verificable.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property string $formato_clave
 * @property CarbonImmutable|null $formato_descargado_en
 * @property int|null $formato_descargado_por
 * @property int $descargas
 * @property int|null $firmado_document_id
 * @property string|null $promedio
 * @property string|null $folio
 * @property CarbonImmutable|null $certificado_en
 * @property int|null $emitido_por
 * @property string|null $certificado_disk
 * @property string|null $certificado_ruta
 * @property-read Colaborador $colaborador
 * @property-read EmployeeDocument|null $documentoFirmado
 */
class CertificacionMrLana extends Model
{
    protected $table = 'certificaciones_mr_lana';

    protected $fillable = [
        'colaborador_id', 'formato_clave', 'formato_descargado_en', 'formato_descargado_por', 'descargas',
        'firmado_document_id', 'promedio', 'folio', 'certificado_en', 'emitido_por', 'certificado_disk', 'certificado_ruta',
    ];

    protected function casts(): array
    {
        return [
            'formato_descargado_en' => 'datetime',
            'certificado_en' => 'datetime',
            'descargas' => 'integer',
            'promedio' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<EmployeeDocument, $this>
     */
    public function documentoFirmado(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'firmado_document_id');
    }

    public function certificada(): bool
    {
        return $this->certificado_en !== null;
    }

    public function estado(): EstadoCertificacionMrLana
    {
        if ($this->certificada()) {
            return EstadoCertificacionMrLana::Certificado;
        }

        return $this->formato_descargado_en !== null
            ? EstadoCertificacionMrLana::FormatoDescargado
            : EstadoCertificacionMrLana::SinFormato;
    }
}
