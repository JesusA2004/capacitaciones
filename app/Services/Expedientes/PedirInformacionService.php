<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\Incorporacion\SolicitudEmpleo\SolicitudEmpleoRegistroService;
use App\Services\Tareas\NotificadorRhService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Pedir información al colaborador» (expediente, ficha del candidato y
 * ciclo laboral): junta TODO lo que le falta a la persona — datos personales,
 * documentos por subir, solicitud de empleo + estudio socioeconómico,
 * documentos por firmar y foto — y se lo pide en un solo aviso (campana web +
 * push de la app). Lo que solo RH puede completar (datos laborales, datos de
 * la sucursal para el contrato) se le muestra a RH, nunca se le pide a ella.
 *
 * @phpstan-type Rubro array{clave: string, titulo: string, cantidad: int, detalle: list<string>}
 */
class PedirInformacionService
{
    private const MINUTOS_ENTRE_AVISOS = 60;

    public function __construct(
        private readonly DatosFaltantesService $datos,
        private readonly DocumentosPendientesService $documentos,
        private readonly SolicitudEmpleoRegistroService $solicitud,
        private readonly DocumentoProcesoService $documentoProceso,
        private readonly NotificadorRhService $notificador,
    ) {}

    /**
     * @return array{persona: list<Rubro>, rh: list<Rubro>, total_persona: int, tiene_cuenta: bool}
     */
    public function resumen(Colaborador $colaborador): array
    {
        $faltantes = $this->datos->faltantes($colaborador);
        $persona = [];

        $personales = array_column($faltantes['personales'], 'etiqueta');
        $this->agregar($persona, 'datos_personales', 'Datos personales', $personales);

        // Sin los que cubre la foto de perfil (misma regla que Mi expediente).
        $acciones = app(IncorporacionService::class)->accionesPropias($colaborador);
        $documentos = array_column(array_filter(
            $this->documentos->pendientes($colaborador),
            fn (array $d): bool => ! ($acciones[$d['document_type_id']]['cubierto_por_foto'] ?? false),
        ), 'nombre');
        $this->agregar($persona, 'documentos', 'Documentos por subir', array_map('strval', $documentos));

        $porLlenar = $this->solicitud->datosPorLlenar($colaborador);

        if ($porLlenar > 0) {
            $persona[] = ['clave' => 'solicitud_empleo', 'titulo' => 'Solicitud de empleo y estudio socioeconómico', 'cantidad' => $porLlenar, 'detalle' => []];
        }

        $porFirmar = GeneratedDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado_flujo', EstadoFlujoDocumento::PendienteFirmaColaborador->value)
            ->pluck('titulo')
            ->map(fn ($t): string => (string) ($t ?? 'Documento'))
            ->values()
            ->all();
        $this->agregar($persona, 'firmas', 'Documentos por firmar', array_values($porFirmar));

        if ($colaborador->foto_path === null) {
            $persona[] = ['clave' => 'foto', 'titulo' => 'Fotografía para expediente', 'cantidad' => 1, 'detalle' => []];
        }

        $rh = [];
        $this->agregar($rh, 'datos_laborales', 'Datos laborales', array_column($faltantes['laborales'], 'etiqueta'));
        $contrato = $this->documentoProceso->completitudAlta($colaborador);
        $paraContrato = array_values(array_diff(array_map(fn (array $f): string => (string) $f['etiqueta'], $contrato['faltantes']), $personales));
        $this->agregar($rh, 'contrato', 'Para generar el contrato', $paraContrato);

        $colaborador->loadMissing('user');

        return [
            'persona' => $persona,
            'rh' => $rh,
            'total_persona' => array_sum(array_column($persona, 'cantidad')),
            'tiene_cuenta' => $colaborador->user !== null,
        ];
    }

    /**
     * Manda el aviso con todo lo que le falta.
     *
     * @return array{persona: list<Rubro>, rh: list<Rubro>, total_persona: int, tiene_cuenta: bool}
     *
     * @throws ValidationException
     */
    public function pedir(Colaborador $colaborador, User $rh): array
    {
        $resumen = $this->resumen($colaborador);

        if ($resumen['persona'] === []) {
            throw ValidationException::withMessages(['informacion' => $resumen['rh'] === []
                ? 'A esta persona no le falta nada.'
                : 'A la persona no le falta nada; lo pendiente lo completa RH.']);
        }

        if (! $resumen['tiene_cuenta']) {
            throw ValidationException::withMessages(['informacion' => 'La persona todavía no tiene cuenta para recibir el aviso.']);
        }

        if (! Cache::add(sprintf('pedir-informacion:%d', $colaborador->id), $rh->id, now()->addMinutes(self::MINUTOS_ENTRE_AVISOS))) {
            throw ValidationException::withMessages(['informacion' => 'Ya se le pidió hace menos de una hora.']);
        }

        // El pendiente de la solicitud sale en su portal aunque ya esté activa.
        if (in_array('solicitud_empleo', array_column($resumen['persona'], 'clave'), true)) {
            $this->solicitud->marcarPedido($colaborador, $rh);
        }

        $partes = array_map(fn (array $r): string => sprintf('%s (%d)', $r['titulo'], $r['cantidad']), $resumen['persona']);

        try {
            $this->notificador->notificar(
                [$colaborador->user],
                'informacion_faltante',
                'Recursos Humanos te pide completar tu información',
                sprintf('Te falta: %s. Entra a Mi portal para completarlo.', implode(', ', $partes)),
                $colaborador,
                'completar_informacion',
                'alta',
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar «Pedir información al colaborador».', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }

        return $resumen;
    }

    /**
     * @param  list<Rubro>  $rubros
     * @param  list<string>  $detalle
     */
    private function agregar(array &$rubros, string $clave, string $titulo, array $detalle): void
    {
        if ($detalle !== []) {
            $rubros[] = ['clave' => $clave, 'titulo' => $titulo, 'cantidad' => count($detalle), 'detalle' => $detalle];
        }
    }
}
