<?php

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Integraciones\Auditoria\DiagnosticoActaAuditoriaService;
use App\Services\Integraciones\TokenIntegracionService;
use Illuminate\Support\Facades\DB;

/**
 * auditoria:diagnosticar-acta: sólo lectura, presencia sin valores (salvo
 * --mostrar, y nunca la clave de elector) y si la respuesta v2 trae el dato.
 */
beforeEach(function () {
    $this->empresa = Empresa::factory()->create();
    app(TokenIntegracionService::class)->emitir('auditoria', 'Auditoría (diagnóstico)', [TokenIntegracionService::HABILIDAD_AUDITORIA_LEER], false, [$this->empresa->id]);
    $this->sucursal = Sucursal::factory()->create([
        'empresa_id' => $this->empresa->id, 'nombre' => 'Atlixco', 'clave' => 'ATL01',
        'direccion' => 'C. 9 Sur 507', 'colonia' => 'Centro', 'municipio' => 'Atlixco', 'ciudad' => 'Atlixco',
        'estado' => 'Puebla', 'codigo_postal' => '74200',
    ]);
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor']);
    $this->gerente = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id, 'numero_empleado' => 'EMP-0200', 'fecha_nacimiento' => '1996-01-10',
        'domicilio' => null, 'domicilio_calle' => null, 'domicilio_colonia' => null, 'domicilio_municipio' => null, 'domicilio_estado' => null, 'domicilio_cp' => null,
    ]);
    $this->gestor = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $puesto->id, 'gerente_id' => $this->gerente->id,
        'numero_empleado' => 'EMP-0201', 'fecha_nacimiento' => '1992-05-02', 'clave_elector' => 'CLAVESECRETA123',
        'domicilio_calle' => 'Calle Privada Uno', 'domicilio_numero_exterior' => '14', 'domicilio_colonia' => 'San Juan',
        'domicilio_municipio' => 'Atlixco', 'domicilio_estado' => 'Puebla', 'domicilio_cp' => '74210',
    ]);
    $this->sucursal->update(['responsable_id' => User::factory()->create(['colaborador_id' => $this->gerente->id])->id]);
});

test('reporta presencia y llegada a la API v2 sin imprimir valores', function () {
    $antes = DB::table('colaboradores')->max('updated_at');

    $this->artisan('auditoria:diagnosticar-acta', ['--sucursal' => ['ATL01'], '--colaborador' => ['EMP-0201', (string) $this->gerente->id]])
        ->expectsOutputToContain('respuesta v2: visible')
        ->expectsOutputToContain('colaboradores.domicilio_calle')
        ->expectsOutputToContain('sucursales.codigo_postal')
        ->expectsOutputToContain('compuesto de los campos')
        ->doesntExpectOutputToContain('Calle Privada Uno')
        ->doesntExpectOutputToContain('C. 9 Sur 507')
        ->doesntExpectOutputToContain('1992-05-02')
        ->doesntExpectOutputToContain('CLAVESECRETA123')
        ->assertSuccessful();

    expect(DB::table('colaboradores')->max('updated_at'))->toBe($antes);
});

test('el servicio distingue el dato ausente en la base del dato que llega', function () {
    $servicio = app(DiagnosticoActaAuditoriaService::class);
    $gestor = collect($servicio->colaborador($this->gestor->id)['filas'])->keyBy('dato');
    $gerente = collect($servicio->colaborador($this->gerente->id)['filas'])->keyBy('dato');

    expect($gestor['calle']['en_bd'])->toBeTrue()->and($gestor['calle']['en_v2'])->toBeTrue()
        ->and($gestor['calle']['valor'])->toBeNull()
        ->and($gestor['clave_elector']['en_v2'])->toBeTrue()
        ->and($gestor['gerente_id']['en_v2'])->toBeTrue()
        ->and($gestor['puesto']['en_v2'])->toBeTrue()
        // Lo que se ve en producción: edad sí, domicilio no (columnas vacías en People).
        ->and($gerente['fecha_nacimiento']['en_bd'])->toBeTrue()->and($gerente['fecha_nacimiento']['en_v2'])->toBeTrue()
        ->and($gerente['domicilio_texto']['en_bd'])->toBeFalse()->and($gerente['domicilio_texto']['en_v2'])->toBeFalse()
        ->and($gerente['calle']['en_bd'])->toBeFalse()->and($gerente['colonia']['en_v2'])->toBeFalse();

    $mostrado = collect($servicio->colaborador($this->gestor->id, true)['filas'])->keyBy('dato');
    expect($mostrado['calle']['valor'])->toBe('Calle Privada Uno')->and($mostrado['clave_elector']['valor'])->toBeNull();
});

test('señala un domicilio legado en la cuenta o una sugerencia OCR sin aplicar, sin valores', function () {
    DB::table('users')->where('colaborador_id', $this->gerente->id)->update(['domicilio' => 'Texto legado 1']);
    $r = app(DiagnosticoActaAuditoriaService::class)->colaborador($this->gerente->id);

    expect(implode(' ', $r['otras']))->toContain('users.domicilio')->not->toContain('Texto legado 1');
});

test('sin opciones es inválido', function () {
    $this->artisan('auditoria:diagnosticar-acta')->assertFailed();
});
