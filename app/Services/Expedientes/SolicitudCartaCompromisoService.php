<?php

namespace App\Services\Expedientes;

use App\Models\CartaCompromiso;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Incorporacion\RegistroAppService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\RhMobile\ResponsableResolverService;
use App\Support\FechaMexico;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La propia persona pide su carta compromiso («Mi expediente» en la web y
 * POST colaborador/carta-compromiso/solicitar en la app): elige cuáles de
 * SUS documentos requeridos pendientes se compromete a entregar (de 1 a
 * maxDocumentos()), ve la fecha límite y la vista previa, y la envía.
 *
 * La carta la crea CartaCompromisoService::crear() (mismo snapshot, folio
 * y validaciones que la del registro en la app), pero nace
 * `pendiente_aprobacion`: no da plazo ni levanta el bloqueo de la app hasta
 * que alguien con cartas_compromiso.aprobar la aprueba
 * (GestionCartasCompromisoService::aprobar); entonces el plazo corre desde
 * ese día. Mismas reglas de elegibilidad que la carta que genera RH: solo
 * nuevo ingreso, máximo de documentos, una sola carta abierta, nunca
 * super_admin, nunca con el registro en la app abierto (ahí se usa «No
 * tengo este documento»).
 */
class SolicitudCartaCompromisoService
{
    public function __construct(
        private readonly CartaCompromisoService $cartas,
        private readonly GestionCartasCompromisoService $gestion,
        private readonly DocumentosPendientesService $pendientes,
        private readonly RegistroAppService $registro,
        private readonly NotificacionEventosService $eventos,
        private readonly ResponsableResolverService $responsables,
    ) {}

    /**
     * Lo que «Mi expediente» necesita para ofrecer «Pedir carta compromiso».
     * `mostrar` = false cuando no aplica en absoluto (personal que ya
     * laboraba, no le falta nada, ya tiene carta abierta: esa se ve en su
     * tarjeta). Con más faltantes que el máximo se muestra, pero sin poder
     * enviarla y con el motivo.
     *
     * @return array{mostrar: bool, puede_solicitar: bool, motivo: string|null, pendientes: list<array{document_type_id: int, nombre: string, descripcion: string|null, estado_etiqueta: string}>, max_documentos: int, dias_plazo: int, fecha_limite: string}
     */
    public function estado(User $usuario): array
    {
        $colaborador = $this->colaboradorDe($usuario);

        // Carta diferida que ya puede generarse: se genera sola (como en la app).
        if ($this->cartas->esperaCartaAutomatica($colaborador)) {
            $this->cartas->sincronizarSinFallar($colaborador);
        }

        $pendientes = $this->pendientes->pendientes($colaborador);
        $motivo = $this->motivoNoElegible($usuario, $colaborador, count($pendientes));
        $mostrar = $pendientes !== []
            && $this->gestion->esNuevoIngreso($colaborador)
            && $this->gestion->cartaAbierta($colaborador) === null
            && ! $usuario->hasRole('super_admin');

        return [
            'mostrar' => $mostrar,
            'puede_solicitar' => $mostrar && $motivo === null,
            'motivo' => $motivo,
            'pendientes' => array_map(fn (array $p): array => [
                'document_type_id' => (int) $p['document_type_id'],
                'nombre' => (string) $p['nombre'],
                'descripcion' => $p['descripcion'] ?? null,
                'estado_etiqueta' => (string) $p['estado_etiqueta'],
            ], $pendientes),
            'max_documentos' => $this->cartas->maxDocumentos(),
            'dias_plazo' => $this->cartas->diasPlazo(),
            'fecha_limite' => FechaMexico::inicioDeHoy()->addDays($this->cartas->diasPlazo())->toDateString(),
        ];
    }

    /**
     * PDF de exactamente la carta que se pediría (no guarda nada).
     *
     * @param  list<int>  $documentTypeIds
     *
     * @throws ValidationException
     */
    public function vistaPrevia(User $usuario, array $documentTypeIds): string
    {
        $colaborador = $this->colaboradorDe($usuario);
        $this->exigirElegible($usuario, $colaborador);

        return $this->cartas->vistaPrevia($colaborador, $documentTypeIds);
    }

    /**
     * Crea la solicitud (pendiente de aprobación de RH) y avisa a quien la
     * aprueba. El aviso nunca deshace la solicitud.
     *
     * @param  list<int>  $documentTypeIds
     *
     * @throws ValidationException
     */
    public function solicitar(User $usuario, array $documentTypeIds): CartaCompromiso
    {
        $colaborador = $this->colaboradorDe($usuario);
        $this->exigirElegible($usuario, $colaborador);

        $carta = $this->cartas->crear($colaborador, $documentTypeIds, $usuario, solicitudDelColaborador: true);
        $this->avisarAprobadores($carta, $usuario, $colaborador);

        return $carta;
    }

    /**
     * @throws ValidationException
     */
    private function exigirElegible(User $usuario, Colaborador $colaborador): void
    {
        $motivo = $this->motivoNoElegible($usuario, $colaborador, count($this->pendientes->idsPendientes($colaborador)));

        if ($motivo !== null) {
            throw ValidationException::withMessages(['documentos' => $motivo]);
        }
    }

    /** Motivo (en segunda persona) por el que no puede pedirla; null si sí. */
    private function motivoNoElegible(User $usuario, Colaborador $colaborador, int $pendientes): ?string
    {
        $maximo = $this->cartas->maxDocumentos();
        $abierta = $this->gestion->cartaAbierta($colaborador);

        return match (true) {
            $usuario->hasRole('super_admin') => 'Las cuentas de super administrador no reciben carta compromiso.',
            $this->registro->aplica($usuario) => 'Primero termina tu registro en la app: ahí puedes marcar «No tengo este documento» y se genera tu carta compromiso.',
            ! $this->gestion->esNuevoIngreso($colaborador) => 'La carta compromiso es solo para personal de nuevo ingreso (en contratación u onboarding). Sube tus documentos pendientes.',
            $abierta !== null => sprintf(
                'Ya tienes la carta compromiso %s (%s).',
                (string) $abierta->folio,
                mb_strtolower($this->cartas->estadoEfectivo($abierta)->etiqueta(), 'UTF-8'),
            ),
            $pendientes === 0 => 'No te falta ningún documento obligatorio: no necesitas carta compromiso.',
            $pendientes > $maximo => sprintf(
                'Te faltan %d documentos y una carta compromiso cubre como máximo %d. Sube los demás hasta que te falten %d o menos para poder pedirla.',
                $pendientes,
                $maximo,
                $maximo,
            ),
            default => null,
        };
    }

    private function avisarAprobadores(CartaCompromiso $carta, User $usuario, Colaborador $colaborador): void
    {
        try {
            $this->eventos->crearPendiente(
                $this->responsables->paraColaborador($usuario, GestionCartasCompromisoService::PERMISO_APROBAR),
                new Aviso(
                    'carta_compromiso_solicitada',
                    'rh_incorporacion',
                    'Carta compromiso por aprobar',
                    sprintf(
                        '%s pidió una carta compromiso para entregar %d documento(s) (carta %s). Revísala y apruébala o recházala.',
                        $colaborador->nombreCompleto(),
                        count($carta->documentos),
                        (string) $carta->folio,
                    ),
                    $carta,
                    'aprobar_carta_compromiso',
                    'alta',
                    ['carta_compromiso_id' => $carta->id, 'colaborador_id' => $colaborador->id],
                    sprintf('carta-solicitud-%d', $carta->id),
                ),
            );
        } catch (Throwable $e) {
            Log::warning('CartaCompromiso: no se pudo avisar la solicitud a quien la aprueba.', ['carta_id' => $carta->id, 'error' => $e->getMessage()]);
        }
    }

    private function colaboradorDe(User $usuario): Colaborador
    {
        $colaborador = $usuario->colaborador;

        abort_if($colaborador === null, 422, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
