/**
 * Pruebas del motor del tour guiado (reglas puras de
 * resources/js/lib/tours/motor.ts). Se corren con el runner nativo de Node,
 * sin dependencias extra: `npm run test:js`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    avisoSelectorFaltante,
    BORDE,
    calcularPosicion,
    decisionSinElemento,
    ESPERA_ELEMENTO_MS,
    ESPERA_MINIMA_MS,
    esperaParaElemento,
    intersects,
    PADDING_SPOTLIGHT,
    rectSpotlight,
    SEPARACION,
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

test('varios selectores faltantes en la misma pantalla NO suman la espera completa cada uno', () => {
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

test('la espera máxima por elemento es de ~1.5 s', () => {
    assert.equal(ESPERA_ELEMENTO_MS, 1500);
});

// ── Posición de la caja (nunca sobre el objetivo) ─────────────────────

const pantalla = { ancho: 1440, alto: 900 };
const caja = { ancho: 380, alto: 220 };

test('intersects detecta encimados y no cuenta bordes compartidos', () => {
    assert.equal(intersects({ top: 0, left: 0, width: 10, height: 10 }, { top: 5, left: 5, width: 10, height: 10 }), true);
    assert.equal(intersects({ top: 0, left: 0, width: 10, height: 10 }, { top: 0, left: 10, width: 10, height: 10 }), false);
});

test('sin objetivo la caja va centrada', () => {
    const p = calcularPosicion(null, caja, pantalla);
    assert.equal(p.modo, 'centrado');
    assert.equal(p.left, (pantalla.ancho - caja.ancho) / 2);
});

test('objetivo arriba (pestañas): la caja va abajo sin taparlo', () => {
    const objetivo = { top: 90, left: 300, width: 300, height: 40 };
    const p = calcularPosicion(objetivo, caja, pantalla);
    assert.equal(p.lado, 'abajo');
    assert.equal(p.tapaObjetivo, false);
    assert.ok(p.top >= objetivo.top + objetivo.height + PADDING_SPOTLIGHT + SEPARACION - 0.5);
});

test('objetivo pegado abajo: la caja va arriba', () => {
    const p = calcularPosicion({ top: 780, left: 400, width: 500, height: 80 }, caja, pantalla);
    assert.equal(p.lado, 'arriba');
    assert.equal(p.tapaObjetivo, false);
});

test('objetivo alto (calendario): la caja va al costado con más espacio', () => {
    const calendario = { top: 150, left: 280, width: 760, height: 700 };
    const p = calcularPosicion(calendario, caja, pantalla);
    assert.equal(p.lado, 'derecha');
    assert.equal(p.tapaObjetivo, false);
    assert.equal(intersects({ top: p.top, left: p.left, width: p.ancho, height: caja.alto }, rectSpotlight(calendario)), false);
});

test('objetivo que ocupa toda la pantalla: se elige lo que MENOS lo tape y se marca', () => {
    const p = calcularPosicion({ top: 0, left: 0, width: 1440, height: 900 }, caja, pantalla);
    assert.equal(p.tapaObjetivo, true);
    assert.ok(p.top >= BORDE && p.left >= BORDE);
});

test('la caja nunca se sale de la pantalla', () => {
    const p = calcularPosicion({ top: 400, left: 1400, width: 30, height: 30 }, caja, pantalla);
    assert.ok(p.left >= BORDE && p.left + p.ancho <= pantalla.ancho - BORDE);
    assert.ok(p.top >= BORDE && p.top + caja.alto <= pantalla.alto - BORDE);
});

test('propiedad: si existe un lugar que no tape el objetivo, nunca lo tapa', () => {
    let semilla = 7;
    const azar = () => (semilla = (semilla * 16807) % 2147483647) / 2147483647;

    for (let i = 0; i < 2000; i++) {
        const objetivo = {
            top: azar() * 800,
            left: azar() * 1300,
            width: 20 + azar() * 500,
            height: 20 + azar() * 300,
        };
        const p = calcularPosicion(objetivo, caja, pantalla);
        const rectCaja = { top: p.top, left: p.left, width: p.ancho, height: caja.alto };

        if (!p.tapaObjetivo) {
            assert.equal(intersects(rectCaja, rectSpotlight(objetivo)), false, JSON.stringify(objetivo));
        }

        assert.ok(p.left >= BORDE - 0.5 && p.left + p.ancho <= pantalla.ancho - BORDE + 0.5, 'dentro horizontal');
        assert.ok(p.top >= BORDE - 0.5 && p.top + caja.alto <= pantalla.alto - BORDE + 0.5, 'dentro vertical');
    }
});

test('móvil: hoja inferior; si el objetivo quedaría debajo, hoja superior', () => {
    const movil = { ancho: 390, alto: 844 };
    const arriba = calcularPosicion({ top: 120, left: 16, width: 300, height: 40 }, caja, movil);
    assert.equal(arriba.modo, 'hoja-abajo');
    assert.equal(arriba.tapaObjetivo, false);
    assert.equal(arriba.ancho, movil.ancho - BORDE * 2);

    const abajo = calcularPosicion({ top: 700, left: 16, width: 300, height: 60 }, caja, movil);
    assert.equal(abajo.modo, 'hoja-arriba');
    assert.equal(abajo.tapaObjetivo, false);
});

test('móvil 360 px: la hoja cabe completa con sus bordes', () => {
    const p = calcularPosicion({ top: 100, left: 10, width: 200, height: 40 }, caja, { ancho: 360, alto: 640 });
    assert.equal(p.ancho, 360 - BORDE * 2);
    assert.equal(p.left, BORDE);
});

test('si la caja normal no cabe a un lado, una más angosta evita tapar el objetivo', () => {
    // Calendario alto con 336 px libres a la derecha (menos que 380 + separación).
    const calendario = { top: 150, left: 290, width: 800, height: 640 };
    const p = calcularPosicion(calendario, caja, pantalla);
    assert.equal(p.tapaObjetivo, false);
    assert.ok(p.ancho <= 300);
    assert.equal(intersects({ top: p.top, left: p.left, width: p.ancho, height: caja.alto }, rectSpotlight(calendario)), false);
});
