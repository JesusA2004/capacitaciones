<?php

namespace Database\Seeders;

use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AltaDigital\ConversionColaboradorService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Vacantes, candidatos, altas digitales e invitaciones QR de demostración
 * (docs/RECLUTAMIENTO.md, docs/ALTA_DIGITAL_COLABORADOR.md). Usa siempre los
 * servicios/modelos reales del dominio — nunca inserta un estado que un
 * flujo real no pueda producir — para que el tablero de Vacantes, la lista
 * de Candidatos y las Invitaciones QR se vean como un sistema en operación.
 *
 * Idempotente por el motivo/observaciones característicos "(demo-recl)".
 */
class ReclutamientoDemoSeeder extends Seeder
{
    private const MARCA = '(demo-recl)';

    private ConversionColaboradorService $conversion;

    private IncorporacionInvitacionService $invitaciones;

    public function run(): void
    {
        // Resuelto aquí (no por constructor): Seeder::call() no siempre
        // instancia vía el contenedor, así que un __construct() con
        // dependencias truena con "Too few arguments" en ciertos contextos
        // (ver DatabaseSeederProduccionTest). Mismo patrón que
        // SolicitudesDemoSeeder.
        $this->conversion = app(ConversionColaboradorService::class);
        $this->invitaciones = app(IncorporacionInvitacionService::class);

        if (Vacante::where('observaciones', 'like', '%'.self::MARCA)->exists()) {
            return;
        }

        $sucursal = Sucursal::where('clave', 'IXT01')->first();
        $departamento = Departamento::where('nombre', 'Operaciones')->first();
        $gestorFijo = Puesto::where('nombre', 'Gestor fijo')->first();
        $gestorVolante = Puesto::where('nombre', 'Gestor volante')->first();
        $rhAdmin = User::where('email', 'rh.admin@mrlana.test')->first();
        $gerente = User::where('email', 'gerente.sucursal@mrlana.test')->first();

        if ($sucursal === null || $gestorFijo === null || $rhAdmin === null) {
            return;
        }

        $empresaId = $sucursal->empresa_id;

        $vacanteBase = [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
            'departamento_id' => $departamento?->id,
            'puesto_id' => $gestorFijo->id,
            'gerente_solicitante_id' => $gerente?->id,
            'responsable_rh_id' => $rhAdmin->id,
            'motivo' => MotivoVacante::Crecimiento->value,
            'generada_automaticamente' => false,
            'plazas_requeridas' => 1,
            'plazas_cubiertas' => 0,
            'plazas_disponibles' => 1,
            'fecha_apertura' => now()->subDays(10),
            'creado_por' => $rhAdmin->id,
        ];

        // 1) Abierta.
        $vAbierta = Vacante::create([
            ...$vacanteBase,
            'estado' => EstadoVacante::Abierta->value,
            'observaciones' => 'Vacante abierta de demostración '.self::MARCA,
        ]);

        // 2) En reclutamiento.
        Vacante::create([
            ...$vacanteBase,
            'puesto_id' => ($gestorVolante !== null ? $gestorVolante->id : $gestorFijo->id),
            'estado' => EstadoVacante::EnReclutamiento->value,
            'observaciones' => 'Vacante en reclutamiento de demostración '.self::MARCA,
        ]);

        // 3) Con candidatos — se liga después a un candidato real.
        $vConCandidatos = Vacante::create([
            ...$vacanteBase,
            'estado' => EstadoVacante::ConCandidatos->value,
            'observaciones' => 'Vacante con candidatos de demostración '.self::MARCA,
        ]);

        // 4) En revisión.
        $vEnRevision = Vacante::create([
            ...$vacanteBase,
            'estado' => EstadoVacante::EnRevision->value,
            'observaciones' => 'Vacante en revisión de demostración '.self::MARCA,
        ]);

        // 5) Cubierta (1 de 1 plazas).
        Vacante::create([
            ...$vacanteBase,
            'estado' => EstadoVacante::Cubierta->value,
            'plazas_cubiertas' => 1,
            'plazas_disponibles' => 0,
            'observaciones' => 'Vacante cubierta de demostración '.self::MARCA,
        ]);

        // 6) Cancelada, con motivo.
        Vacante::create([
            ...$vacanteBase,
            'estado' => EstadoVacante::Cancelada->value,
            'motivo_cancelacion' => 'La ruta se dio de baja y ya no requiere cobertura '.self::MARCA,
            'observaciones' => 'Vacante cancelada de demostración '.self::MARCA,
        ]);

        // 7) Cobertura parcial: 3 requeridas, 1 cubierta, 2 disponibles —
        // sigue abierta (nunca "cubierta" con plazas disponibles > 0).
        Vacante::create([
            ...$vacanteBase,
            'estado' => EstadoVacante::EnReclutamiento->value,
            'plazas_requeridas' => 3,
            'plazas_cubiertas' => 1,
            'plazas_disponibles' => 2,
            'observaciones' => 'Vacante con cobertura parcial de demostración '.self::MARCA,
        ]);

        // 8) Vacante automática real: solo si el headcount sembrado
        // realmente tiene un faltante para (sucursal, puesto) — nunca se
        // fuerza un estado que el servicio no produciría por sí mismo.
        app(VacanteAutoGenerationService::class)->sincronizarTodo();

        // --- Candidatos en distintas fases (App\Enums\EstadoCandidato) ---
        $fases = [
            EstadoCandidato::Nuevo,
            EstadoCandidato::EntrevistaProgramada,
            EstadoCandidato::DocumentacionSolicitada,
            EstadoCandidato::EnRevision,
            EstadoCandidato::NoViable,
            EstadoCandidato::AprobadoRh,
        ];

        foreach ($fases as $indice => $fase) {
            Candidato::create([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursal->id,
                'departamento_id' => $departamento?->id,
                'puesto_objetivo_id' => $gestorFijo->id,
                'vacante_id' => $vConCandidatos->id,
                'nombre' => 'Candidato Demo '.($indice + 1),
                'apellidos' => 'Reclutamiento '.self::MARCA,
                'telefono' => sprintf('55%08d', 20000000 + $indice),
                'correo' => 'candidato.demo'.($indice + 1).'@example.test',
                'fuente' => 'bolsa_trabajo',
                'estado' => $fase->value,
                'observaciones' => 'Candidato de demostración en fase "'.$fase->etiqueta().'" '.self::MARCA,
                'responsable_rh_id' => $rhAdmin->id,
                'creado_por' => $rhAdmin->id,
            ]);
        }

        // --- Flujo completo, dejado ANTES de la incorporación final: vacante
        // -> candidato -> aprobado RH -> alta digital -> invitación QR ---
        $candidatoFlujoCompleto = Candidato::create([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
            'departamento_id' => $departamento?->id,
            'puesto_objetivo_id' => $gestorFijo->id,
            'vacante_id' => $vEnRevision->id,
            'nombre' => 'Karen',
            'apellidos' => 'Del Toro Sandoval '.self::MARCA,
            'telefono' => '5599990001',
            'correo' => 'karen.deltoro.demo@example.test',
            'fuente' => 'referido',
            'estado' => EstadoCandidato::AprobadoRh->value,
            'observaciones' => 'Flujo completo de demostración, alta en revisión '.self::MARCA,
            'responsable_rh_id' => $rhAdmin->id,
            'creado_por' => $rhAdmin->id,
        ]);

        $altaEnRevision = AltaDigital::create([
            'candidato_id' => $candidatoFlujoCompleto->id,
            'vacante_id' => $vEnRevision->id,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
            'departamento_id' => $departamento?->id,
            'puesto_id' => $gestorFijo->id,
            'token' => Str::random(48),
            'token_expira_en' => now()->addDays(7),
            'estado' => EstadoAltaDigital::EnRevisionRh->value,
            'nombre' => $candidatoFlujoCompleto->nombre,
            'apellidos' => $candidatoFlujoCompleto->apellidos,
            'telefono' => $candidatoFlujoCompleto->telefono,
            'correo' => $candidatoFlujoCompleto->correo,
            'aviso_privacidad_aceptado' => true,
            'aviso_privacidad_aceptado_en' => now()->subDay(),
            'consentimiento_datos_aceptado' => true,
            'consentimiento_datos_aceptado_en' => now()->subDay(),
            'enviada_en' => now()->subDay(),
        ]);

        ['invitacion' => $invitacionActiva] = $this->invitaciones->crear([
            'candidato_id' => $candidatoFlujoCompleto->id,
            'email' => $candidatoFlujoCompleto->correo,
            'telefono' => $candidatoFlujoCompleto->telefono,
            'nombre_prellenado' => $candidatoFlujoCompleto->nombreCompleto(),
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
            'departamento_id' => $departamento?->id,
            'puesto_id' => $gestorFijo->id,
            'observaciones' => 'Invitación activa de demostración '.self::MARCA,
        ], $rhAdmin);

        // Dos altas más, en estados que el flujo completo de arriba no cubre
        // (sección 30 del encargo): "creada" (recién generada, liga sin
        // enviar todavía) y "requiere corrección" (RH ya la revisó y pidió
        // ajustes). Usan dos de los candidatos "aprobado_rh" ya creados
        // arriba en vez de fabricar candidatos nuevos solo para esto.
        $candidatosAprobados = Candidato::where('apellidos', 'like', '%'.self::MARCA)
            ->where('estado', EstadoCandidato::AprobadoRh->value)
            ->where('id', '!=', $candidatoFlujoCompleto->id)
            ->get();

        if ($candidatoParaCreada = $candidatosAprobados->get(0)) {
            AltaDigital::create([
                'candidato_id' => $candidatoParaCreada->id,
                'vacante_id' => $vConCandidatos->id,
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursal->id,
                'departamento_id' => $departamento?->id,
                'puesto_id' => $gestorFijo->id,
                'token' => Str::random(48),
                'token_expira_en' => now()->addDays(7),
                'estado' => EstadoAltaDigital::Creada->value,
                'nombre' => $candidatoParaCreada->nombre,
                'apellidos' => $candidatoParaCreada->apellidos,
                'telefono' => $candidatoParaCreada->telefono,
                'correo' => $candidatoParaCreada->correo,
            ]);
        }

        if ($candidatoParaCorreccion = $candidatosAprobados->get(1) ?? $candidatosAprobados->get(0)) {
            AltaDigital::create([
                'candidato_id' => $candidatoParaCorreccion->id,
                'vacante_id' => $vConCandidatos->id,
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursal->id,
                'departamento_id' => $departamento?->id,
                'puesto_id' => $gestorFijo->id,
                'token' => Str::random(48),
                'token_expira_en' => now()->addDays(7),
                'estado' => EstadoAltaDigital::RequiereCorreccion->value,
                'nombre' => $candidatoParaCorreccion->nombre,
                'apellidos' => $candidatoParaCorreccion->apellidos,
                'telefono' => $candidatoParaCorreccion->telefono,
                'correo' => $candidatoParaCorreccion->correo,
                'comentarios' => 'Falta la fotografía y la CURP legible '.self::MARCA,
                'aviso_privacidad_aceptado' => true,
                'aviso_privacidad_aceptado_en' => now()->subDays(2),
                'consentimiento_datos_aceptado' => true,
                'consentimiento_datos_aceptado_en' => now()->subDays(2),
                'enviada_en' => now()->subDays(2),
                'revisado_por' => $rhAdmin->id,
                'revisado_en' => now()->subDay(),
            ]);
        }

        // --- Invitaciones QR en otros estados, con el mismo servicio real ---
        ['invitacion' => $invitacionAUsar] = $this->invitaciones->crear([
            'nombre_prellenado' => 'Invitación usada demo '.self::MARCA,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
        ], $rhAdmin);

        if ($gerente !== null) {
            $this->invitaciones->marcarUsada($invitacionAUsar, $gerente);
        }

        ['invitacion' => $invitacionVencida] = $this->invitaciones->crear([
            'nombre_prellenado' => 'Invitación vencida demo '.self::MARCA,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
            'duracion_horas' => 1,
        ], $rhAdmin);
        // `crear()` siempre nace "activo"; el paso a "vencido" normalmente lo
        // hace `validar()` al detectar expires_at pasado (nunca hay un cron
        // aparte, ver IncorporacionInvitacionService::sincronizarVencimiento()).
        // Para que la demo lo muestre correctamente sin depender de que
        // alguien escanee el QR, se replica aquí el mismo efecto.
        $invitacionVencida->update([
            'expires_at' => now()->subDays(3),
            'estado' => EstadoInvitacionIncorporacion::Vencido->value,
        ]);

        ['invitacion' => $invitacionARevocar] = $this->invitaciones->crear([
            'nombre_prellenado' => 'Invitación revocada demo '.self::MARCA,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
        ], $rhAdmin);
        $this->invitaciones->revocar($invitacionARevocar);

        // --- Candidato ya contratado: flujo hasta el final, vía el mismo
        // servicio que usa AltaDigitalController::aprobar() ---
        $candidatoContratado = Candidato::create([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
            'departamento_id' => $departamento?->id,
            'puesto_objetivo_id' => ($gestorVolante !== null ? $gestorVolante->id : $gestorFijo->id),
            'vacante_id' => $vAbierta->id,
            'nombre' => 'Óscar',
            'apellidos' => 'Beltrán Quiroz '.self::MARCA,
            'telefono' => '5599990002',
            'correo' => 'oscar.beltran.demo@example.test',
            'fuente' => 'redes_sociales',
            'estado' => EstadoCandidato::Contratado->value,
            'observaciones' => 'Flujo completo de demostración, ya contratado '.self::MARCA,
            'responsable_rh_id' => $rhAdmin->id,
            'creado_por' => $rhAdmin->id,
        ]);

        $altaAprobada = AltaDigital::create([
            'candidato_id' => $candidatoContratado->id,
            'vacante_id' => $vAbierta->id,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursal->id,
            'departamento_id' => $departamento?->id,
            'puesto_id' => ($gestorVolante !== null ? $gestorVolante->id : $gestorFijo->id),
            'token' => Str::random(48),
            'token_expira_en' => now()->addDays(7),
            'estado' => EstadoAltaDigital::Aprobada->value,
            'nombre' => $candidatoContratado->nombre,
            'apellidos' => $candidatoContratado->apellidos,
            'telefono' => $candidatoContratado->telefono,
            'correo' => 'oscar.beltran.demo.colaborador@example.test',
            'fecha_ingreso_propuesta' => now()->toDateString(),
            'aviso_privacidad_aceptado' => true,
            'aviso_privacidad_aceptado_en' => now()->subDays(2),
            'consentimiento_datos_aceptado' => true,
            'consentimiento_datos_aceptado_en' => now()->subDays(2),
            'enviada_en' => now()->subDays(2),
            'revisado_por' => $rhAdmin->id,
            'revisado_en' => now()->subDay(),
            'aprobado_por' => $rhAdmin->id,
            'aprobado_en' => now(),
        ]);

        $this->conversion->convertir($altaAprobada, $rhAdmin);
    }
}
