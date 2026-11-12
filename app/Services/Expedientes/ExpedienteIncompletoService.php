<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Incorporacion\RegistroAppService;

/**
 * REGLA ÚNICA del «modo completar expediente» de la app: ¿el autoservicio
 * del colaborador (solicitudes, vacaciones, préstamos, recibos…) queda
 * bloqueado hasta que complete su expediente?
 *
 * Bloquea cuando falta algo que SOLO la persona puede resolver:
 * - Datos personales requeridos sin capturar (DatosFaltantesService::PERSONALES),
 *   salvo que ya los haya enviado y RH los esté revisando.
 * - Documentos requeridos (DocumentosPendientesService) sin subir, vencidos
 *   o con cambio autorizado — salvo carta compromiso vigente, que justo da
 *   plazo para esos.
 * - Documentos requeridos rechazados o con corrección pedida por RH (la
 *   carta compromiso no los cubre: ya se subieron y hay que corregirlos).
 *
 * NUNCA bloquea por documentos ya subidos en revisión de RH (no se castiga
 * la espera de RH), ni a super_admin, ni mientras el registro por QR siga
 * abierto (ese asistente tiene su propio candado), ni con
 * config('features.bloqueo_expediente_incompleto') = false.
 *
 * Lo consumen: el middleware expediente.completo (423 en el autoservicio),
 * el bootstrap de la app y la guía «Tu siguiente paso» (GuiaSiguientePasoService).
 *
 * @phpstan-type MotivoBloqueo array{clave: string, titulo: string, detalle: list<string>, conteo: int}
 * @phpstan-type EstadoExpediente array{aplica: bool, bloqueado: bool, mensaje: string|null, motivos: list<MotivoBloqueo>, datos_faltantes: int, documentos_por_subir: int, documentos_rechazados: int}
 */
class ExpedienteIncompletoService
{
    /** Estados de la versión vigente que cuentan como «rechazado, corrígelo». */
    public const ESTADOS_RECHAZADOS = [EstadoDocumento::Rechazado->value, EstadoDocumento::RequiereCorreccion->value];

    public function __construct(
        private readonly DatosFaltantesService $datos,
        private readonly DocumentosPendientesService $documentos,
        private readonly CartaCompromisoService $cartas,
        private readonly RegistroAppService $registro,
    ) {}

    public function habilitado(): bool
    {
        return (bool) config('features.bloqueo_expediente_incompleto', true);
    }

    /**
     * @return EstadoExpediente
     */
    public function estado(User $usuario): array
    {
        $colaborador = $usuario->colaborador;

        if (! $this->habilitado()
            || ! $colaborador instanceof Colaborador
            || $usuario->hasRole('super_admin')
            || $this->registro->aplica($usuario)) {
            return $this->sinBloqueo(false);
        }

        return $this->evaluar($colaborador);
    }

    /**
     * @return EstadoExpediente
     */
    private function evaluar(Colaborador $colaborador): array
    {
        $motivos = [];

        // 1) Datos personales: los laborales los captura RH, nunca bloquean.
        $personales = $this->datos->faltantes($colaborador)['personales'];
        $datosFaltantes = $personales === [] || $this->datos->solicitudAbierta($colaborador) ? 0 : count($personales);

        if ($datosFaltantes > 0) {
            $secciones = $this->seccionesPersonales($personales);
            $motivos[] = [
                'clave' => 'datos_personales',
                'titulo' => $this->textoDatos($datosFaltantes, count($secciones)),
                'detalle' => array_map(fn (array $s): string => sprintf('%s: %s', $s['etiqueta'], implode(', ', $s['faltantes'])), $secciones),
                'conteo' => $datosFaltantes,
            ];
        }

        // 2) y 3) Documentos requeridos que la persona sube.
        $pendientes = $this->documentos->pendientes($colaborador);
        $rechazados = array_values(array_filter($pendientes, fn (array $d): bool => in_array($d['estado'], self::ESTADOS_RECHAZADOS, true)));
        $porSubir = array_values(array_filter($pendientes, fn (array $d): bool => ! in_array($d['estado'], self::ESTADOS_RECHAZADOS, true)));

        $carta = $porSubir !== [] ? $this->cartas->ultima($colaborador) : null;

        if ($carta !== null && ! $carta->estado->sinAprobar() && ! $carta->plazoVencido()) {
            // La carta compromiso vigente da plazo para entregarlos (una
            // solicitud que RH aún no aprueba, todavía no).
            $porSubir = [];
        }

        if ($porSubir !== []) {
            $motivos[] = [
                'clave' => 'documentos_por_subir',
                'titulo' => count($porSubir) === 1 ? 'Te falta 1 documento por subir' : sprintf('Te faltan %d documentos por subir', count($porSubir)),
                'detalle' => array_column($porSubir, 'nombre'),
                'conteo' => count($porSubir),
            ];
        }

        if ($rechazados !== []) {
            $motivos[] = [
                'clave' => 'documentos_rechazados',
                'titulo' => count($rechazados) === 1 ? 'Recursos Humanos rechazó 1 documento' : sprintf('Recursos Humanos rechazó %d documentos', count($rechazados)),
                'detalle' => array_column($rechazados, 'nombre'),
                'conteo' => count($rechazados),
            ];
        }

        if ($motivos === []) {
            return $this->sinBloqueo(true);
        }

        return [
            'aplica' => true,
            'bloqueado' => true,
            'mensaje' => $this->mensaje($datosFaltantes, count($porSubir), count($rechazados)),
            'motivos' => $motivos,
            'datos_faltantes' => $datosFaltantes,
            'documentos_por_subir' => count($porSubir),
            'documentos_rechazados' => count($rechazados),
        ];
    }

    /**
     * Secciones del expediente con datos PERSONALES faltantes (para listas
     * legibles: «Domicilio: Calle, Colonia»), nunca un párrafo de 13 campos.
     *
     * @param  list<array{campo: string, etiqueta: string}>  $personales
     * @return list<array{clave: string, etiqueta: string, faltantes: list<string>}>
     */
    public function seccionesPersonales(array $personales): array
    {
        $secciones = [];

        foreach ($this->datos->secciones(['personales' => $personales, 'laborales' => []]) as $seccion) {
            if ($seccion['faltantes'] !== []) {
                $secciones[] = ['clave' => $seccion['clave'], 'etiqueta' => $seccion['etiqueta'], 'faltantes' => $seccion['faltantes']];
            }
        }

        // Un dato personal fuera de las secciones conocidas nunca se pierde.
        $cubiertos = array_merge(...array_map(fn (array $s): array => $s['faltantes'], $secciones));
        $otros = array_values(array_filter(array_column($personales, 'etiqueta'), fn (string $e): bool => ! in_array($e, $cubiertos, true)));

        if ($otros !== []) {
            $secciones[] = ['clave' => 'otros', 'etiqueta' => 'Otros datos', 'faltantes' => $otros];
        }

        return $secciones;
    }

    public function textoDatos(int $datos, int $secciones): string
    {
        $textoDatos = $datos === 1 ? '1 dato' : sprintf('%d datos', $datos);

        return $secciones > 1
            ? sprintf('Te faltan %s en %d secciones', $textoDatos, $secciones)
            : sprintf('Te %s %s', $datos === 1 ? 'falta' : 'faltan', $textoDatos);
    }

    private function mensaje(int $datos, int $porSubir, int $rechazados): string
    {
        $partes = [];

        if ($datos > 0) {
            $partes[] = $datos === 1 ? '1 dato personal' : sprintf('%d datos personales', $datos);
        }

        if ($porSubir > 0) {
            $partes[] = $porSubir === 1 ? '1 documento por subir' : sprintf('%d documentos por subir', $porSubir);
        }

        if ($rechazados > 0) {
            $partes[] = $rechazados === 1 ? '1 documento rechazado por corregir' : sprintf('%d documentos rechazados por corregir', $rechazados);
        }

        $ultimo = array_pop($partes);
        $lista = $partes === [] ? (string) $ultimo : sprintf('%s y %s', implode(', ', $partes), (string) $ultimo);

        return sprintf('Completa tu expediente para usar esta sección. Te falta: %s.', $lista);
    }

    /**
     * @return EstadoExpediente
     */
    private function sinBloqueo(bool $aplica): array
    {
        return [
            'aplica' => $aplica,
            'bloqueado' => false,
            'mensaje' => null,
            'motivos' => [],
            'datos_faltantes' => 0,
            'documentos_por_subir' => 0,
            'documentos_rechazados' => 0,
        ];
    }
}
