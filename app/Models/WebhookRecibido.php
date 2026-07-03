<?php

namespace App\Models;

use App\Enums\EstadoWebhook;
use App\Enums\ProveedorSesion;
use Database\Factories\WebhookRecibidoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Todo webhook entrante, con índice único (proveedor, identificador_evento)
 * que impide procesar el mismo evento dos veces. Ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 3.
 *
 * @property int $id
 * @property ProveedorSesion $proveedor
 * @property string $identificador_evento
 * @property string $tipo
 * @property string $hash_payload
 * @property array<string, mixed>|null $payload_normalizado
 * @property bool $firma_valida
 * @property EstadoWebhook $estado
 * @property Carbon|null $procesado_en
 * @property int $intentos
 * @property string|null $error
 */
class WebhookRecibido extends Model
{
    /** @use HasFactory<WebhookRecibidoFactory> */
    use HasFactory;

    protected $table = 'webhooks_recibidos';

    protected $fillable = [
        'proveedor', 'identificador_evento', 'tipo', 'hash_payload', 'payload_normalizado',
        'firma_valida', 'estado', 'procesado_en', 'intentos', 'error',
    ];

    protected function casts(): array
    {
        return [
            'proveedor' => ProveedorSesion::class,
            'payload_normalizado' => 'array',
            'firma_valida' => 'boolean',
            'estado' => EstadoWebhook::class,
            'procesado_en' => 'datetime',
        ];
    }
}
