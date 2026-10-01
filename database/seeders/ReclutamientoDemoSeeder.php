<?php

namespace Database\Seeders;

use App\Enums\EstadoCandidato;
use App\Enums\FuenteCandidato;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Plantilla, vacantes derivadas y candidatos de demostración en cada fase
 * del reclutamiento (Etapa 1). Los candidatos NO se colocan en un estado a
 * mano: se registran y avanzan con CandidatoWorkflowService — las mismas
 * acciones (y validaciones) que usan la web y la app —, así cada uno tiene
 * su entrevista, psicométricas, socioeconómico, referencias y aprobaciones
 * reales. Las etapas 2–6 las siembra CicloLaboralDemoSeeder.
 *
 * Idempotente por el apellido característico "(demo-recl)".
 */
class ReclutamientoDemoSeeder extends Seeder
{
    private const MARCA = '(demo-recl)';

    private CandidatoWorkflowService $workflow;

    public function run(): void
    {
        // Resuelto aquí (no por constructor): Seeder::call() no siempre
        // instancia vía el contenedor (ver DatabaseSeederProduccionTest).
        $this->workflow = app(CandidatoWorkflowService::class);

        if (Candidato::where('apellidos', 'like', '%'.self::MARCA)->exists()) {
            return;
        }

        $sucursalUno = Sucursal::where('clave', 'IXT01')->first();
        $sucursalDos = Sucursal::where('clave', 'CUE01')->first();
        $departamento = Departamento::where('nombre', 'Operaciones')->first();
        $gestorFijo = Puesto::where('nombre', 'Gestor')->first();
        $gestorVolante = Puesto::where('nombre', 'Gestor Volante')->first();
        $rhAdmin = User::where('email', 'rh.admin@mrlana.test')->first();
        $reclutamiento = User::where('email', 'rh.auxiliar@mrlana.test')->first() ?? $rhAdmin;
        $gerente = User::where('email', 'gerente.sucursal@mrlana.test')->first();

        if ($sucursalUno === null || $sucursalDos === null || $gestorFijo === null || $gestorVolante === null || $rhAdmin === null || $gerente === null || $reclutamiento === null) {
            return;
        }

        // --- 1) Plantilla autorizada por encima de la actual: el faltante
        // derivado es real y VacanteAutoGenerationService abre vacantes. ---
        $parA = ['sucursal' => $sucursalUno, 'puesto' => $gestorFijo, 'autorizada' => 5];
        $parB = ['sucursal' => $sucursalDos, 'puesto' => $gestorVolante, 'autorizada' => 4];

        foreach ([$parA, $parB] as $par) {
            HeadcountTarget::updateOrCreate(
                ['sucursal_id' => $par['sucursal']->id, 'puesto_id' => $par['puesto']->id],
                [
                    'empresa_id' => $par['sucursal']->empresa_id,
                    'departamento_id' => $departamento?->id,
                    'plantilla_autorizada' => $par['autorizada'],
                    'fuente' => 'manual',
                    'fecha_corte' => now()->toDateString(),
                    'editable' => true,
                    'created_by_id' => $rhAdmin->id,
                ],
            );
        }

        // --- 2) Vacantes: 100% derivadas, nunca creadas a mano aquí. ---
        app(VacanteAutoGenerationService::class)->sincronizarTodo();

        $vacanteA = Vacante::where('sucursal_id', $sucursalUno->id)->where('puesto_id', $gestorFijo->id)->where('generada_automaticamente', true)->first();
        $vacanteB = Vacante::where('sucursal_id', $sucursalDos->id)->where('puesto_id', $gestorVolante->id)->where('generada_automaticamente', true)->first();
        $vacanteA?->update(['sueldo_mensual' => 9500]);
        $vacanteB?->update(['sueldo_mensual' => 10200]);

        // --- 3) Candidatos en cada fase, avanzados con el workflow real. ---
        $fuentes = [FuenteCandidato::FacebookGrupos, FuenteCandidato::Meta, FuenteCandidato::Whatsapp, FuenteCandidato::Referido];
        $indice = 0;

        $registrar = function (string $nombre, array $par, ?Vacante $vacante) use ($departamento, $reclutamiento, $gerente, $fuentes, &$indice): Candidato {
            $indice++;

            return $this->workflow->registrar([
                'empresa_id' => $par['sucursal']->empresa_id,
                'sucursal_id' => $par['sucursal']->id,
                'departamento_id' => $departamento?->id,
                'puesto_objetivo_id' => $par['puesto']->id,
                'vacante_id' => $vacante?->id,
                'nombre' => $nombre,
                'apellidos' => 'Reclutamiento '.self::MARCA,
                'telefono' => sprintf('55%08d', 30000000 + $indice),
                'correo' => Str::slug($nombre, '.').'.demo@example.test',
                'fuente' => $fuentes[$indice % count($fuentes)]->value,
                'observaciones' => 'Candidato de demostración '.self::MARCA,
                'responsable_rh_id' => $reclutamiento->id,
                'gerente_involucrado_id' => $gerente->id,
            ], $reclutamiento);
        };

        // Hasta dónde avanza cada candidato (orden del pipeline).
        $planes = [
            ['Alejandra Nuñez', $parA, $vacanteA, EstadoCandidato::Recibidos],
            ['Brandon Reyes', $parB, $vacanteB, EstadoCandidato::EntrevistaPendiente],
            ['Citlali Moreno', $parA, $vacanteA, EstadoCandidato::PsicometricasPendientes],
            ['Diego Salcedo', $parB, $vacanteB, EstadoCandidato::RevisionPsicometricas],
            ['Estefania Rangel', $parA, $vacanteA, EstadoCandidato::SocioeconomicoPendiente],
            ['Fernando Cabrera', $parB, $vacanteB, EstadoCandidato::ReferenciasPendientes],
            ['Gabriela Solis', $parA, $vacanteA, EstadoCandidato::PreseleccionGerente],
            ['Hugo Betancourt', $parB, $vacanteB, EstadoCandidato::AutorizacionRhPendiente],
            ['Melissa Cordova', $parA, $vacanteA, EstadoCandidato::AutorizadoRh],
        ];

        foreach ($planes as [$nombre, $par, $vacante, $meta]) {
            $candidato = $registrar($nombre, $par, $vacante);
            $this->avanzarHasta($candidato, $meta, $reclutamiento, $gerente, $rhAdmin);
        }

        // Salidas con motivo (cada una en una fase distinta).
        $noViable = $registrar('Jorge Villagran', $parB, $vacanteB);
        $this->workflow->evaluarPerfil($noViable, $reclutamiento, false, 'No cuenta con licencia de motocicleta vigente.');

        $rechazadoRh = $registrar('Itzel Guzman', $parA, $vacanteA);
        $this->avanzarHasta($rechazadoRh, EstadoCandidato::AutorizacionRhPendiente, $reclutamiento, $gerente, $rhAdmin);
        $this->workflow->rechazarRh($rechazadoRh, $rhAdmin, 'Inconsistencias en referencias detectadas por RH.');

        $desistio = $registrar('Luis Olvera', $parB, $vacanteB);
        $this->workflow->evaluarPerfil($desistio, $reclutamiento, true, null);
        $this->workflow->descartar($desistio, $gerente, EstadoCandidato::Desistio, 'Aceptó otra oferta antes de la entrevista.');
    }

    /**
     * Ejecuta las acciones reales del workflow hasta el estado indicado.
     */
    private function avanzarHasta(Candidato $candidato, EstadoCandidato $meta, User $reclutamiento, User $gerente, User $rh): void
    {
        $pasos = [
            EstadoCandidato::EntrevistaPendiente->value => fn (Candidato $c) => $this->workflow->evaluarPerfil($c, $reclutamiento, true, 'Cubre el perfil: experiencia en cobranza y moto propia.'),
            EstadoCandidato::PsicometricasPendientes->value => fn (Candidato $c) => $this->workflow->registrarEntrevista($c, $gerente, ['realizada_en' => now()->subDays(6)->toDateTimeString(), 'resultado' => 'viable', 'observaciones' => 'Buena actitud y disponibilidad.']),
            EstadoCandidato::RevisionPsicometricas->value => function (Candidato $c) use ($reclutamiento): void {
                $this->workflow->enviarPsicometricas($c, $reclutamiento, 'https://evaluaciones.example.test/prueba/'.$c->id);
                $this->workflow->registrarResultadosPsicometricas($c, $reclutamiento, 'Perfil estable, orientado a resultados.');
            },
            EstadoCandidato::SocioeconomicoPendiente->value => fn (Candidato $c) => $this->workflow->revisarPsicometricas($c, $gerente, true, 'En perfil.'),
            EstadoCandidato::ReferenciasPendientes->value => fn (Candidato $c) => $this->workflow->registrarSocioeconomico($c, $gerente, [
                'fecha_visita' => now()->subDays(3)->toDateString(),
                'direccion' => 'Calle Demostración 45, Ixtapaluca, Edo. Méx.',
                'checklist' => ['vivienda_en_orden' => true, 'vive_con_familia' => true, 'arraigo_anios' => 3, 'resguardo_motocicleta' => true],
                'resultado' => 'viable',
                'observaciones' => 'Vivienda en orden, arraigo de 3 años.',
            ]),
            EstadoCandidato::PreseleccionGerente->value => function (Candidato $c) use ($reclutamiento): void {
                $this->workflow->registrarReferencia($c, $reclutamiento, ['empresa' => 'Financiera Demo', 'contacto' => 'Lic. Ramírez', 'telefono' => '5550001111', 'relacion_puesto' => 'Jefe inmediato', 'resultado' => 'positiva']);
                $this->workflow->concluirReferencias($c, $reclutamiento, true, 'Referencias positivas.');
            },
            EstadoCandidato::AutorizacionRhPendiente->value => fn (Candidato $c) => $this->workflow->preautorizar($c, $gerente, 'Lo preautorizo para la vacante.'),
            EstadoCandidato::AutorizadoRh->value => fn (Candidato $c) => $this->workflow->autorizarRh($c, $rh, 'Autorizado.'),
        ];

        foreach ($pasos as $destino => $paso) {
            $candidato->refresh();

            if ($candidato->estado->orden() >= $meta->orden()) {
                return;
            }

            $paso($candidato);
        }
    }
}
