/**
 * Anti-adelanto del video de la Inducción (bug 2026-11-03): el buffering
 * nunca cuenta como adelanto; un seek manual más allá de lo visto sí se
 * regresa; retroceder a lo ya visto se permite.
 *
 *   npm run test:js
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    TOLERANCIA_SALTO_SEGUNDOS,
    esAvanceContinuo,
    evaluarSeek,
    evaluarTimeUpdate,
} from '../../resources/js/lib/induccion/antiAdelantoVideo.ts';

test('la reproducción normal sube el máximo visto', () => {
    let maximo = 10;
    let anterior = 10;

    for (const t of [10.25, 10.5, 10.75, 11, 11.25]) {
        const r = evaluarTimeUpdate(anterior, t, maximo);
        assert.equal(r.regresarA, null);
        maximo = r.maximoVisto;
        anterior = t;
    }

    assert.equal(maximo, 11.25);
});

test('un buffering (el tiempo se queda quieto y luego sigue) no regresa ni avisa', () => {
    // Trabado en 30 s durante varias lecturas, luego retoma.
    const lecturas = [30, 30, 30, 30, 30.1, 30.35, 30.6];
    let maximo = 30;
    let anterior = 30;

    for (const t of lecturas) {
        const r = evaluarTimeUpdate(anterior, t, maximo);
        assert.equal(r.regresarA, null, `no debió regresar en ${t}`);
        maximo = r.maximoVisto;
        anterior = t;
    }
});

test('los micro-saltos del reproductor al salir de un stall (nudge) se toleran', () => {
    assert.equal(evaluarSeek(30.3, 30).regresarA, null);
    assert.equal(
        evaluarSeek(30 + TOLERANCIA_SALTO_SEGUNDOS, 30).regresarA,
        null,
    );
});

test('un seek manual más allá de lo visto regresa al máximo visto', () => {
    assert.deepEqual(evaluarSeek(120, 30), { regresarA: 30, maximoVisto: 30 });
    // El máximo NO se actualiza con el destino del seek.
    assert.equal(evaluarSeek(120, 30).maximoVisto, 30);
});

test('un salto sin evento seeking (detectado en timeupdate) también regresa', () => {
    assert.deepEqual(evaluarTimeUpdate(30, 95, 30), {
        regresarA: 30,
        maximoVisto: 30,
    });
});

test('retroceder o moverse dentro de lo ya visto se permite', () => {
    assert.equal(evaluarSeek(5, 100).regresarA, null);
    assert.equal(evaluarSeek(90, 100).regresarA, null);
    assert.equal(evaluarTimeUpdate(100, 20, 100).regresarA, null);
    // Reproducir algo ya visto no mueve el máximo.
    assert.equal(evaluarTimeUpdate(20, 20.25, 100).maximoVisto, 100);
});

test('encadenar seeks pequeños dentro de la tolerancia no estira el máximo', () => {
    let maximo = 30;
    let posicion = 30;

    for (let i = 0; i < 10; i++) {
        const destino = posicion + TOLERANCIA_SALTO_SEGUNDOS - 0.1;
        const seek = evaluarSeek(destino, maximo);

        if (seek.regresarA !== null) {
            posicion = seek.regresarA;
            continue;
        }

        posicion = destino;
        const r = evaluarTimeUpdate(posicion, posicion + 0.25, maximo);
        maximo = r.maximoVisto;
        posicion += 0.25;
    }

    // Solo avanzó lo que de verdad se reprodujo (≈ 0.25 s por vuelta).
    assert.ok(maximo <= 30 + 10 * 0.25 + 1e-9, `máximo estirado: ${maximo}`);
});

test('esAvanceContinuo distingue reproducción de salto', () => {
    assert.ok(esAvanceContinuo(10, 10.25));
    assert.ok(esAvanceContinuo(10, 10));
    assert.ok(!esAvanceContinuo(10, 20));
    assert.ok(!esAvanceContinuo(10, 9));
});

test('una pestaña en segundo plano (timeupdate espaciado) no cuenta como adelanto', () => {
    // 6 s de reproducción real entre dos lecturas.
    assert.equal(evaluarTimeUpdate(30, 36, 30, 6).regresarA, null);
    assert.equal(evaluarTimeUpdate(30, 36, 30, 6).maximoVisto, 36);
    // Pero un salto de 60 s en 6 s reales sí se regresa.
    assert.equal(evaluarTimeUpdate(30, 90, 30, 6).regresarA, 30);
});
