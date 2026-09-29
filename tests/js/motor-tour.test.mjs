/**
 * Pruebas del motor del tour guiado (reglas puras de
 * resources/js/lib/tours/motor.ts). Se corren con el runner nativo de Node,
 * sin dependencias extra: `npm run test:js`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    avisoSelectorFaltante,
    decisionSinElemento,
    ESPERA_ELEMENTO_MS,
    ESPERA_MINIMA_MS,
    esperaParaElemento,
} from '../../resources/js/lib/tours/motor.ts';

test('un paso obligatorio sin elemento se muestra centrado (nunca se cuelga)', () => {
    assert.equal(
        decisionSinElemento({ titulo: 'Tabla', selector: '[data-tour="x"]' }),
        'centrado',
    );
});

test('un paso opcional sin elemento se omite', () => {
    assert.equal(
        decisionSinElemento({ titulo: 'Hoy', selector: '[data-tour="x"]', opcional: true }),
        'omitir',
    );
});

test('el primer paso de una pantalla recién cargada espera el presupuesto completo', () => {
    assert.equal(esperaParaElemento(1000, 1000), ESPERA_ELEMENTO_MS);
});

test('varios selectores faltantes en la misma pantalla NO suman 2.5 s cada uno', () => {
    // 6 selectores muertos seguidos (el bug de Cumpleaños): antes 6 × 2.5 s = 15 s.
    const llegada = 0;
    let reloj = 0;

    for (let i = 0; i < 6; i++) {
        reloj += esperaParaElemento(llegada, reloj);
    }

    assert.ok(
        reloj <= ESPERA_ELEMENTO_MS + 5 * ESPERA_MINIMA_MS,
        `esperó ${reloj} ms en total`,
    );
});

test('la espera nunca es menor al mínimo ni negativa', () => {
    assert.equal(esperaParaElemento(0, 60_000), ESPERA_MINIMA_MS);
    assert.equal(esperaParaElemento(5000, 1000), ESPERA_ELEMENTO_MS);
});

test('el aviso de desarrollo identifica tour, paso y selector faltante', () => {
    const aviso = avisoSelectorFaltante('cumpleanos', 2, {
        titulo: 'Filtros',
        selector: '[data-tour="cumpleanos-filtros"]',
    });

    assert.match(aviso, /cumpleanos/);
    assert.match(aviso, /paso 3/);
    assert.match(aviso, /\[data-tour="cumpleanos-filtros"\]/);
    assert.match(aviso, /centrado/);
});
