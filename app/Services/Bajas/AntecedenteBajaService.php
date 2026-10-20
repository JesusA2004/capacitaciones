<?php

namespace App\Services\Bajas;

use App\Enums\EstatusBaja;
use App\Enums\Recontratable;
use App\Models\Baja;
use App\Models\Candidato;
use Illuminate\Support\Str;

/**
 * RN-09: al dar de alta a un candidato con una baja previa «No
 * recontratable» (o «Con reservas»), Reclutamiento muestra una alerta. El
 * candidato no tiene CURP en People, así que se compara contra las bajas
 * cerradas por nombre completo normalizado (sin acentos ni mayúsculas) o por
 * teléfono / correo de la persona dada de baja.
 */
class AntecedenteBajaService
{
    /**
     * @return array{folio: string, nombre: string, fecha_baja: string|null, recontratable: string, recontratable_etiqueta: string, causal: string|null, sucursal: string|null, coincidencia: string}|null
     */
    public function deCandidato(Candidato $candidato): ?array
    {
        $nombre = $this->normalizar(trim(sprintf('%s %s', $candidato->nombre, $candidato->apellidos)));
        $telefono = preg_replace('/\D+/', '', (string) $candidato->telefono) ?: null;
        $correo = $candidato->correo !== null ? mb_strtolower(trim($candidato->correo)) : null;

        $bajas = Baja::query()
            ->with(['causal', 'sucursal:id,nombre', 'colaborador:id,telefono,correo_personal'])
            ->where('estatus', EstatusBaja::Cerrada->value)
            ->whereIn('recontratable', [Recontratable::No->value, Recontratable::ConReservas->value])
            ->orderByRaw("case when recontratable = 'no' then 0 else 1 end")
            ->orderByDesc('fecha_baja')
            ->get();

        foreach ($bajas as $baja) {
            $coincidencia = match (true) {
                $nombre !== '' && $this->normalizar($baja->nombre_colaborador) === $nombre => 'nombre',
                $telefono !== null && strlen($telefono) >= 10 && preg_replace('/\D+/', '', (string) $baja->colaborador?->telefono) === $telefono => 'teléfono',
                $correo !== null && $correo !== '' && mb_strtolower(trim((string) $baja->colaborador?->correo_personal)) === $correo => 'correo',
                default => null,
            };

            if ($coincidencia === null || $baja->recontratable === null) {
                continue;
            }

            return [
                'folio' => $baja->folio,
                'nombre' => $baja->nombre_colaborador,
                'fecha_baja' => $baja->fecha_baja?->toDateString(),
                'recontratable' => $baja->recontratable->value,
                'recontratable_etiqueta' => $baja->recontratable->etiqueta(),
                'causal' => $baja->causal?->etiquetaConClave(),
                'sucursal' => $baja->sucursal?->nombre,
                'coincidencia' => $coincidencia,
            ];
        }

        return null;
    }

    private function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtoupper(Str::ascii($texto))));
    }
}
