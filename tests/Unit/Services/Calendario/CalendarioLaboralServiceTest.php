<?php

use App\Services\Calendario\CalendarioLaboralService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->calendario = new CalendarioLaboralService;
});

test('salta fines de semana al sumar días hábiles', function () {
    // Viernes + 1 día hábil = lunes (no sábado).
    $resultado = $this->calendario->sumarDiasHabiles(Carbon::parse('2026-10-02'), 1); // viernes

    expect($resultado->toDateString())->toBe('2026-10-05'); // lunes
});

test('15 días hábiles desde un lunes salta tres fines de semana', function () {
    $resultado = $this->calendario->sumarDiasHabiles(Carbon::parse('2026-10-05'), 15); // lunes

    // 15 días hábiles = 3 semanas completas → 21 días naturales.
    expect($resultado->toDateString())->toBe('2026-10-26');
});

test('diasHabilesEntre cuenta solo días hábiles entre dos fechas', function () {
    $dias = $this->calendario->diasHabilesEntre(Carbon::parse('2026-10-02'), Carbon::parse('2026-10-09')); // vie a vie

    expect($dias)->toBe(5); // lun-vie siguiente, sin contar el viernes de inicio
});

test('un sábado y un domingo nunca son hábiles', function () {
    expect($this->calendario->esHabil(Carbon::parse('2026-10-03')))->toBeFalse() // sábado
        ->and($this->calendario->esHabil(Carbon::parse('2026-10-04')))->toBeFalse() // domingo
        ->and($this->calendario->esHabil(Carbon::parse('2026-10-05')))->toBeTrue(); // lunes
});
