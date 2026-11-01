<?php

use App\Enums\EstadoCandidato;
use App\Enums\TipoSeguimientoCandidato;
use App\Models\Candidato;
use App\Models\CandidatoReferencia;
use App\Models\CandidatoSocioeconomico;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

/*
| Rutas sin autoridad: los pasos del candidato solo avanzan una vez, salvo tras una
| reapertura; ahí sí hay una decisión previa y un puesto inferior no la revierte.
| Sin decisión previa, quien hace el siguiente paso no se bloquea.
*/

function rfCaUsuario(string $rol, Sucursal $sucursal, string $puesto): User
{
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'puesto_id' => Puesto::query()->firstOrCreate(['nombre' => $puesto])->id,
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole($rol);
    $usuario->givePermissionTo(['candidatos.revisar_perfil', 'candidatos.revisar_psicometricas', 'candidatos.revisar_socioeconomico', 'candidatos.validar_referencias', 'candidatos.editar', 'candidatos.ver', 'candidatos.ver_sucursal']);

    return $usuario;
}

function rfCaCandidato(EstadoCandidato $estado, ?User $decidioAntes = null, ?EstadoCandidato $destino = null): Candidato
{
    $candidato = Candidato::factory()->create(['sucursal_id' => test()->sucursal->id, 'estado' => $estado]);

    if ($decidioAntes !== null) {
        $candidato->seguimientos()->create([
            'tipo' => TipoSeguimientoCandidato::CambioEstado,
            'nota' => 'Decisión anterior a la reapertura.',
            'estado_anterior' => $estado->value,
            'estado_nuevo' => ($destino ?? EstadoCandidato::NoViable)->value,
            'fecha' => now(),
            'registrado_por' => $decidioAntes->id,
        ]);
    }

    CandidatoSocioeconomico::query()->create(['candidato_id' => $candidato->id, 'fecha_visita' => now()->toDateString(), 'direccion' => 'Calle Uno', 'registrado_por' => test()->rh->id]);
    CandidatoReferencia::query()->create(['candidato_id' => $candidato->id, 'empresa' => 'Empresa', 'contacto' => 'Contacto', 'resultado' => 'positiva', 'fecha_validacion' => now()->toDateString(), 'validada_por' => test()->rh->id]);

    return $candidato;
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->sucursal = Sucursal::factory()->create();
    $this->rh = clUsuario('rh_admin');
    $this->regional = rfCaUsuario('gerente_regional', $this->sucursal, 'Gerente Regional');
    $this->gerente = rfCaUsuario('gerente_sucursal', $this->sucursal, 'Gerente de Sucursal');
    $this->flujo = app(CandidatoWorkflowService::class);
    $this->pasos = [
        'perfil' => [EstadoCandidato::Recibidos, fn (Candidato $c, User $u) => $this->flujo->evaluarPerfil($c, $u, true, 'Sí cubre')],
        'psicometricas' => [EstadoCandidato::RevisionPsicometricas, fn (Candidato $c, User $u) => $this->flujo->revisarPsicometricas($c, $u, true, 'En perfil')],
        'socioeconomico' => [EstadoCandidato::SocioeconomicoPendiente, fn (Candidato $c, User $u) => $this->flujo->revisarSocioeconomico($c, $u, true, 'Viable')],
        'referencias' => [EstadoCandidato::ReferenciasPendientes, fn (Candidato $c, User $u) => $this->flujo->concluirReferencias($c, $u, true, 'Bien')],
    ];
});

$pasos = ['perfil', 'psicometricas', 'socioeconomico', 'referencias'];

test('sin decisión previa, quien hace el siguiente paso avanza', function (string $paso) {
    [$estado, $accion] = $this->pasos[$paso];
    $candidato = rfCaCandidato($estado);

    expect($accion($candidato, $this->gerente)->estado)->not->toBe($estado);
})->with($pasos);

test('un puesto inferior no revierte lo que decidió un puesto superior en ese paso', function (string $paso) {
    [$estado, $accion] = $this->pasos[$paso];
    $candidato = rfCaCandidato($estado, $this->regional);

    expect(fn () => $accion($candidato, $this->gerente))->toThrow(AuthorizationException::class);
    expect($candidato->refresh()->estado)->toBe($estado);
})->with($pasos);

test('un puesto superior sí la cambia, y RH conserva su facultad', function (string $paso) {
    [$estado, $accion] = $this->pasos[$paso];

    expect($accion(rfCaCandidato($estado, $this->gerente), $this->regional)->estado)->not->toBe($estado)
        ->and($accion(rfCaCandidato($estado, $this->regional), $this->rh)->estado)->not->toBe($estado);
})->with($pasos);

test('un regreso por reapertura no cuenta como decisión del paso', function () {
    $candidato = rfCaCandidato(EstadoCandidato::RevisionPsicometricas, $this->regional, EstadoCandidato::EntrevistaPendiente);

    expect($this->flujo->revisarPsicometricas($candidato, $this->gerente, true, 'En perfil')->estado)->toBe(EstadoCandidato::SocioeconomicoPendiente);
});

test('un gerente no opera los pasos de quien podría ser su reemplazo', function () {
    $candidato = rfCaCandidato(EstadoCandidato::Recibidos);
    $candidato->update(['puesto_objetivo_id' => Puesto::query()->where('nombre', 'Gerente de Sucursal')->value('id')]);

    expect(fn () => $this->flujo->evaluarPerfil($candidato, $this->gerente, true, 'Sí cubre'))->toThrow(AuthorizationException::class);
});

test('editar teléfono no se bloquea; cambiar el puesto sobre lo decidido arriba, sí', function () {
    $candidato = rfCaCandidato(EstadoCandidato::SocioeconomicoPendiente, $this->regional, EstadoCandidato::ReferenciasPendientes);
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor de cobranza']);

    expect($this->flujo->actualizar($candidato, ['telefono' => '7221234567'], $this->gerente)->telefono)->toBe('7221234567');
    expect(fn () => $this->flujo->actualizar($candidato, ['puesto_objetivo_id' => $puesto->id], $this->gerente))->toThrow(AuthorizationException::class);
    expect($this->flujo->actualizar($candidato, ['puesto_objetivo_id' => $puesto->id], $this->regional)->puesto_objetivo_id)->toBe($puesto->id);
});
