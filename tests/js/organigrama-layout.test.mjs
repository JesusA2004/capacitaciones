/**
 * Acomodo del organigrama editable (resources/js/lib/organigramaLayout.ts):
 * nada encimado, cada nivel en su fila y el padre centrado sobre sus hijos.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    calcularLayout,
    OPCIONES_LAYOUT,
    zoomParaAjustar,
} from '../../resources/js/lib/organigramaLayout.ts';

const arbol = [
    { clave: 'dg', padre: null },
    { clave: 'dc', padre: 'dg' },
    { clave: 'rh', padre: 'dg' },
    { clave: 'q1', padre: 'dc' },
    { clave: 'q3', padre: 'dc' },
    { clave: 'g1', padre: 'q1' },
    { clave: 'g2', padre: 'q1' },
    { clave: 'g3', padre: 'q3' },
    { clave: 's1', padre: 'g1' },
    { clave: 'x1', padre: 's1' },
    { clave: 'x2', padre: 's1' },
    { clave: 'x3', padre: 's1' },
    { clave: 'ap', padre: 'rh' },
    { clave: 'rc', padre: 'rh' },
];

function cajas(resultado) {
    return [...resultado.posiciones.values()];
}

test('ninguna tarjeta se encima con otra', () => {
    const resultado = calcularLayout(arbol);
    const todas = cajas(resultado);

    for (const a of todas) {
        for (const b of todas) {
            if (a.clave === b.clave) {
                continue;
            }

            const seEnciman =
                a.x < b.x + b.ancho &&
                b.x < a.x + a.ancho &&
                a.y < b.y + b.alto &&
                b.y < a.y + a.alto;

            assert.equal(seEnciman, false, `${a.clave} se encima con ${b.clave}`);
        }
    }
});

test('todas las tarjetas de un mismo nivel (no apiladas) comparten la misma fila', () => {
    const resultado = calcularLayout(arbol);
    const porNivel = new Map();

    for (const caja of cajas(resultado).filter((c) => !c.apilado)) {
        const ys = porNivel.get(caja.nivel) ?? new Set();
        ys.add(caja.y);
        porNivel.set(caja.nivel, ys);
    }

    for (const [nivel, ys] of porNivel) {
        assert.equal(ys.size, 1, `el nivel ${nivel} tiene ${ys.size} alturas`);
    }
});

test('el padre queda centrado exactamente sobre sus hijos', () => {
    const { posiciones } = calcularLayout(arbol);
    const centro = (clave) =>
        posiciones.get(clave).x + posiciones.get(clave).ancho / 2;

    assert.equal(centro('dc'), (centro('q1') + centro('q3')) / 2);
    assert.equal(centro('q1'), (centro('g1') + centro('g2')) / 2);
});

test('las hojas de un mismo jefe se apilan en columna y no ensanchan el árbol', () => {
    const { posiciones } = calcularLayout(arbol);
    const gestores = ['x1', 'x2', 'x3'].map((c) => posiciones.get(c));

    assert.ok(gestores.every((g) => g.apilado));
    assert.equal(new Set(gestores.map((g) => g.x)).size, 1);
    assert.ok(gestores[0].y < gestores[1].y && gestores[1].y < gestores[2].y);
    assert.equal(gestores[0].alto, OPCIONES_LAYOUT.altoApilado);
});

test('un padre fuera de lo visible no pierde la tarjeta: queda como raíz', () => {
    const { posiciones } = calcularLayout([
        { clave: 'a', padre: 'no-existe' },
        { clave: 'b', padre: 'a' },
    ]);

    assert.equal(posiciones.get('a').nivel, 0);
    assert.equal(posiciones.get('b').nivel, 1);
});

test('el zoom de «Ajustar» deja todo dentro del recuadro', () => {
    assert.equal(zoomParaAjustar({ ancho: 1000, alto: 400 }, { ancho: 548, alto: 900 }), 0.5);
    assert.equal(zoomParaAjustar({ ancho: 100, alto: 100 }, { ancho: 2000, alto: 2000 }), 1);
    assert.equal(zoomParaAjustar({ ancho: 100000, alto: 100 }, { ancho: 548, alto: 900 }), 0.2);
});

test('una rama chica queda junto a la grande (contornos), no al otro extremo', () => {
    const entradas = [
        { clave: 'dg', padre: null },
        { clave: 'dc', padre: 'dg' },
        { clave: 'asistente', padre: 'dg' },
    ];

    for (let i = 0; i < 6; i++) {
        entradas.push({ clave: `r${i}`, padre: 'dc' });
        entradas.push({ clave: `g${i}`, padre: `r${i}` });
        entradas.push({ clave: `s${i}`, padre: `g${i}` });
    }

    const { posiciones } = calcularLayout(entradas);
    const dc = posiciones.get('dc');
    const asistente = posiciones.get('asistente');
    const dg = posiciones.get('dg');

    assert.equal(asistente.x, dc.x + OPCIONES_LAYOUT.ancho + OPCIONES_LAYOUT.separacionX);
    assert.equal(dg.x + dg.ancho / 2, (dc.x + dc.ancho / 2 + asistente.x + asistente.ancho / 2) / 2);
});
