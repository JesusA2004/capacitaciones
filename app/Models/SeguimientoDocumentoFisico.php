<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Control del original físico de un documento laboral (impresión, firma
 * física + huella, envío por paquetería a corporativo, recepción y
 * escaneo). 1:1 con GeneratedDocument. Ver
 * App\Services\DocumentosLaborales\FlujoDocumentalService.
 *
 * @property int $id
 * @property int $generated_document_id
 * @property Carbon|null $impreso_en
 * @property int|null $impreso_por
 * @property Carbon|null $firmado_fisico_en
 * @property int|null $firma_fisica_registrada_por
 * @property bool $huella_registrada
 * @property array<int, array{nombre: string, puesto?: string|null}>|null $testigos
 * @property int|null $sucursal_origen_id
 * @property Carbon|null $enviado_en
 * @property int|null $enviado_por
 * @property string|null $paqueteria
 * @property string|null $numero_guia
 * @property string|null $comprobante_disk
 * @property string|null $comprobante_path
 * @property string|null $comprobante_nombre
 * @property Carbon|null $recibido_en
 * @property int|null $recibido_por
 * @property Carbon|null $escaneado_en
 * @property int|null $escaneado_por
 * @property string|null $observaciones
 */
class SeguimientoDocumentoFisico extends Model
{
    protected $table = 'seguimientos_documento_fisico';

    protected $hidden = ['comprobante_disk', 'comprobante_path'];

    protected $fillable = [
        'generated_document_id', 'impreso_en', 'impreso_por', 'firmado_fisico_en',
        'firma_fisica_registrada_por', 'huella_registrada', 'testigos', 'sucursal_origen_id',
        'enviado_en', 'enviado_por', 'paqueteria', 'numero_guia', 'comprobante_disk',
        'comprobante_path', 'comprobante_nombre', 'recibido_en', 'recibido_por',
        'escaneado_en', 'escaneado_por', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'impreso_en' => 'datetime',
            'firmado_fisico_en' => 'datetime',
            'enviado_en' => 'datetime',
            'recibido_en' => 'datetime',
            'escaneado_en' => 'datetime',
            'huella_registrada' => 'boolean',
            'testigos' => 'array',
        ];
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalOrigen(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_origen_id');
    }
}
