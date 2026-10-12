#!/usr/bin/env node
/**
 * Genera docs/guias/** a partir del contenido de la Guía maestra
 * (resources/js/lib/guia/*.ts), la MISMA fuente que pinta /ayuda. Así la
 * guía web y la documentación del repositorio nunca dicen cosas distintas.
 *
 *   npm run guia:docs                  # escribe los archivos
 *   node scripts/guia/generar-docs.mjs --comprobar   # falla si algo no está al día
 *
 * Node ejecuta los .ts quitando los tipos (sin compilar): los archivos de
 * datos solo usan `import type`.
 */
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const GUIA = join(RAIZ, 'resources', 'js', 'lib', 'guia');
const DOCS = join(RAIZ, 'docs', 'guias');

const cargar = (archivo) => import(pathToFileURL(join(GUIA, archivo)).href);

const [
    { ARTICULOS_RECLUTAMIENTO },
    { ARTICULOS_CONTRATACION },
    { ARTICULOS_DESARROLLO },
    { ARTICULOS_VIDA_LABORAL },
    { ARTICULOS_SALIDA },
    { ARTICULOS_ADMINISTRACION },
    { PROCESOS },
    { FLUJO },
    { ROLES_GUIA },
    { GLOSARIO, PREGUNTAS_FRECUENTES },
    video,
] = await Promise.all([
    cargar('articulos/reclutamiento.ts'),
    cargar('articulos/contratacion.ts'),
    cargar('articulos/desarrollo.ts'),
    cargar('articulos/vida-laboral.ts'),
    cargar('articulos/salida.ts'),
    cargar('articulos/administracion.ts'),
    cargar('procesos.ts'),
    cargar('flujo.ts'),
    cargar('roles.ts'),
    cargar('glosario.ts'),
    cargar('video.ts'),
]);

const ARTICULOS = [
    ...ARTICULOS_RECLUTAMIENTO,
    ...ARTICULOS_CONTRATACION,
    ...ARTICULOS_DESARROLLO,
    ...ARTICULOS_VIDA_LABORAL,
    ...ARTICULOS_SALIDA,
    ...ARTICULOS_ADMINISTRACION,
];
const porId = new Map(ARTICULOS.map((a) => [a.id, a]));

const NOMBRE_ROL = {
    rh: 'RH',
    gerente: 'Gerente',
    regional: 'Regional',
    direccion_comercial: 'Dirección Comercial',
    sistemas: 'Sistemas',
    colaborador: 'Colaborador',
};
const CANAL = {
    web: 'Web',
    app: 'App',
    web_app: 'Web y app',
    automatico: 'Automático',
};

const AVISO =
    '<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->\n\n';

// ─────────────────────────────── Render ───────────────────────────────

/** Ancla de un artículo dentro de su documento. */
const ancla = (id) => `articulo-${id}`;

function documentoDe(articuloId, documentoActual) {
    const art = porId.get(articuloId);
    const enEste = documentoActual?.articulos.has(articuloId);

    if (enEste) {
        return `[${art.titulo}](#${ancla(articuloId)})`;
    }

    return `[${art.titulo}](FLUJO_COMPLETO.md#${ancla(articuloId)})`;
}

function lista(items, prefijo = '- ') {
    return items.map((i) => `${prefijo}${i}`).join('\n');
}

function renderArticulo(art, nivel, doc, compacto = false) {
    const h = '#'.repeat(nivel);
    const sub = '#'.repeat(nivel + 1);
    const partes = [];

    partes.push(`<a id="${ancla(art.id)}"></a>\n\n${h} ${art.titulo}`);
    partes.push(`> ${art.resumen}`);
    partes.push(`${sub} Qué es\n\n${art.queEs}`);
    partes.push(
        `${sub} Quién lo hace\n\n${art.quienDetalle ?? art.quien.map((r) => NOMBRE_ROL[r]).join(', ') + '.'}`,
    );

    for (const l of art.llamados ?? []) {
        const titulo =
            l.tono === 'importante'
                ? 'Importante'
                : l.tono === 'aviso'
                  ? 'Atención'
                  : 'Nota';
        partes.push(`> **${titulo}:** ${l.texto}`);
    }

    if (art.antes.length && !compacto) {
        partes.push(`${sub} Antes de empezar\n\n${lista(art.antes)}`);
    }

    partes.push(
        `${sub} Pasos\n\n` +
            art.pasos
                .map((p, i) => {
                    const etiquetas = [
                        p.quien ? NOMBRE_ROL[p.quien] : null,
                        p.canal ? CANAL[p.canal] : null,
                    ]
                        .filter(Boolean)
                        .join(' · ');

                    return `${i + 1}. **${p.titulo}**${etiquetas ? ` _(${etiquetas})_` : ''} — ${p.detalle}`;
                })
                .join('\n'),
    );

    if (art.campos?.length && !compacto) {
        partes.push(
            `${sub} Lo que se captura\n\n` +
                art.campos
                    .map((g) => `- **${g.grupo}:** ${g.campos.join(' · ')}`)
                    .join('\n'),
        );
    }

    if (art.despues.length) {
        partes.push(`${sub} Qué pasa después\n\n${lista(art.despues)}`);
    }

    if (art.notificaciones?.length && !compacto) {
        partes.push(`${sub} Notificaciones\n\n${lista(art.notificaciones)}`);
    }

    if (art.documentos?.length && !compacto) {
        partes.push(
            `${sub} Documentos que se generan\n\n${lista(art.documentos)}`,
        );
    }

    if (art.registro?.length && !compacto) {
        partes.push(`${sub} Qué queda registrado\n\n${lista(art.registro)}`);
    }

    if (art.errores.length) {
        partes.push(
            `${sub} Errores comunes\n\n` +
                art.errores
                    .map((e) => `- **${e.problema}** ${e.solucion}`)
                    .join('\n'),
        );
    }

    if (!compacto && art.relacionados.length) {
        partes.push(
            `${sub} Relacionado con\n\n${lista(art.relacionados.map((r) => documentoDe(r, doc)))}`,
        );
    }

    if (art.accesos.length) {
        partes.push(
            `${sub} En el sistema\n\n` +
                art.accesos
                    .map(
                        (a) =>
                            `- **${a.etiqueta}** → \`${a.ruta}\`${a.permisos?.length ? ` _(permiso: ${a.permisos.join(' o ')})_` : ''}`,
                    )
                    .join('\n') +
                `\n- Guía en pantalla → \`/ayuda/articulo/${art.id}\``,
        );
    }

    return partes.join('\n\n');
}

function diagramaFlujo() {
    const lineas = ['```mermaid', 'flowchart TD'];
    let etapa = null;

    FLUJO.forEach((b, i) => {
        if (b.etapa !== etapa) {
            if (etapa !== null) {
                lineas.push('    end');
            }

            etapa = b.etapa;
            lineas.push(
                `    subgraph ${etapa.replace(/\s+/g, '_')}["${etapa}"]`,
            );
        }

        lineas.push(
            `        ${b.id.replace(/-/g, '_')}["${i + 1}. ${b.titulo.toUpperCase()}"]`,
        );
    });
    lineas.push('    end');
    FLUJO.slice(1).forEach((b, i) => {
        lineas.push(
            `    ${FLUJO[i].id.replace(/-/g, '_')} --> ${b.id.replace(/-/g, '_')}`,
        );
    });
    lineas.push('```');

    return lineas.join('\n');
}

function tablaFlujo() {
    return (
        '| # | Paso | Quién | Dónde | Qué pasa | Guía |\n|---|---|---|---|---|---|\n' +
        FLUJO.map(
            (b, i) =>
                `| ${i + 1} | **${b.titulo}** | ${b.quien.map((r) => NOMBRE_ROL[r]).join(', ')} | ${CANAL[b.canal]} | ${b.resumen} | [${porId.get(b.articulo).titulo}](#${ancla(b.articulo)}) |`,
        ).join('\n')
    );
}

// ─────────────────────────────── Documentos ───────────────────────────────

const archivos = new Map();

function escribir(ruta, contenido) {
    archivos.set(
        join(DOCS, ruta),
        contenido.endsWith('\n') ? contenido : `${contenido}\n`,
    );
}

function documentoPorRol(rolIds) {
    const ids = new Set();

    for (const p of PROCESOS) {
        for (const id of p.articulos) {
            const art = porId.get(id);

            if (art.quien.some((r) => rolIds.includes(r))) {
                ids.add(id);
            }
        }
    }

    return { articulos: ids };
}

// FLUJO_COMPLETO.md
{
    const doc = { articulos: new Set(ARTICULOS.map((a) => a.id)) };
    const cuerpo = [
        `${AVISO}# MR. LANA PEOPLE — Flujo completo`,
        'Conoce el proceso completo desde la necesidad de personal hasta la vida laboral del colaborador. Este documento es el **flujo real del sistema** (revisado contra el código) y es la misma información que se ve en **Ayuda → Guía del sistema** (`/ayuda`).',
        '## De una vacante a un colaborador activo',
        diagramaFlujo(),
        tablaFlujo(),
        '> El esquema original (Plantilla → … → Contrato indeterminado) tiene en el sistema real dos pasos más: **Referencias** y **Preautorización del gerente**, antes de la autorización final de RH. Y el **alta real** (colaborador activo, aviso a RH y Sistemas) ocurre al **cerrar el onboarding**, no al registrarse con el QR.',
        '## Índice por proceso',
        PROCESOS.map(
            (p) =>
                `${p.numero}. **[${p.titulo}](#proceso-${p.id})** — ${p.resumen} _(${p.tiempo}; ${p.roles.map((r) => NOMBRE_ROL[r]).join(', ')})_`,
        ).join('\n'),
        ...PROCESOS.map((p) =>
            [
                `<a id="proceso-${p.id}"></a>\n\n## ${p.numero}. ${p.titulo}`,
                `${p.resumen}\n\n**Tiempo estimado:** ${p.tiempo} · **Roles:** ${p.roles.map((r) => NOMBRE_ROL[r]).join(', ')} · **En pantalla:** \`/ayuda/proceso/${p.id}\``,
                ...p.articulos.map((id) =>
                    renderArticulo(porId.get(id), 3, doc),
                ),
            ].join('\n\n'),
        ),
    ];
    escribir('FLUJO_COMPLETO.md', cuerpo.join('\n\n'));
}

function documentoRol(nombreArchivo, titulo, intro, rolIds, compacto) {
    const doc = documentoPorRol(rolIds);
    const roles = ROLES_GUIA.filter((r) => rolIds.includes(r.id));
    const partes = [`${AVISO}# ${titulo}`, intro];

    for (const r of roles) {
        partes.push(`## ${r.nombre}`);
        partes.push(r.resumen);
        partes.push(`**Dónde trabaja:** ${r.dondeTrabaja}`);
        partes.push(
            `**Roles del sistema:** ${r.rolesSistema.map((s) => `\`${s}\``).join(', ')}`,
        );
        partes.push(
            '### Tu checklist\n\n' +
                r.checklist
                    .map(
                        (c) =>
                            `- [ ] ${c.texto} → ${documentoDe(c.articulo, doc)}`,
                    )
                    .join('\n'),
        );

        if (r.noLeToca.length) {
            partes.push(`### Lo que NO le toca\n\n${lista(r.noLeToca)}`);
        }
    }

    partes.push('---');

    for (const p of PROCESOS) {
        const propios = p.articulos.filter((id) => doc.articulos.has(id));

        if (propios.length === 0) {
            continue;
        }

        partes.push(`## ${p.numero}. ${p.titulo}`);
        partes.push(
            ...propios.map((id) =>
                renderArticulo(porId.get(id), 3, doc, compacto),
            ),
        );
    }

    escribir(nombreArchivo, partes.join('\n\n'));
}

documentoRol(
    'RH.md',
    'Manual operativo de Recursos Humanos',
    'De la plantilla autorizada a la baja y el reingreso: todo lo que hace Reclutamiento / RH en MR. LANA PEOPLE, paso por paso y en el orden del proceso. Para el flujo completo con todos los roles, ver [FLUJO_COMPLETO.md](FLUJO_COMPLETO.md).',
    ['rh'],
    false,
);
documentoRol(
    'GERENTES.md',
    'Guía rápida para Gerentes',
    'Lo que le toca al **gerente de sucursal** (entrevistas, socioeconómico, preautorizaciones, firmas, entrega de equipo, evaluaciones y vistos buenos), al **gerente regional** y a **Dirección Comercial**. Solo aparece lo que su rol realmente puede hacer.',
    ['gerente', 'regional', 'direccion_comercial'],
    true,
);
documentoRol(
    'COLABORADORES.md',
    'Cómo usar la app MR. LANA PEOPLE',
    'Guía sencilla para el colaborador: registrarte con tu QR, entrar, completar tus datos, tus lecciones de bienvenida, pedir permisos, vacaciones y préstamos, y ver tus avisos y recibos. Lo mismo está en la app y en «Mi espacio» de la web.',
    ['colaborador'],
    true,
);
documentoRol(
    'SISTEMAS.md',
    'Guía de Sistemas',
    'Sistemas recibe cada alta y cada baja de colaborador para preparar o retirar cuentas, accesos y equipo, y administra cuentas, roles, notificaciones y versiones de la app. No tiene acceso a expedientes ni a nómina.',
    ['sistemas'],
    false,
);

// GLOSARIO.md y PREGUNTAS_FRECUENTES.md
escribir(
    'GLOSARIO.md',
    `${AVISO}# Glosario\n\nPalabras de MR. LANA PEOPLE explicadas sin tecnicismos (también en \`/ayuda/glosario\`).\n\n` +
        [...GLOSARIO]
            .sort((a, b) => a.termino.localeCompare(b.termino, 'es'))
            .map(
                (t) =>
                    `**${t.termino}** — ${t.definicion}${t.articulo ? ` → ${documentoDe(t.articulo, null)}` : ''}`,
            )
            .join('\n\n'),
);
escribir(
    'PREGUNTAS_FRECUENTES.md',
    `${AVISO}# Preguntas frecuentes\n\nTambién en \`/ayuda/preguntas\`.\n\n` +
        PREGUNTAS_FRECUENTES.map(
            (p) =>
                `## ${p.pregunta}\n\n${p.respuesta}${p.articulo ? `\n\n→ ${documentoDe(p.articulo, null)}` : ''}`,
        ).join('\n\n'),
);

// README.md
escribir(
    'README.md',
    [
        `${AVISO}# Guía maestra de MR. LANA PEOPLE`,
        'Documentación oficial de uso del sistema, escrita contra el **flujo real del código** (rutas, servicios, enums y pantallas). La misma información vive dentro del sistema en **Ayuda → Guía del sistema** (`/ayuda`): buscador, flujo general clicable, 14 procesos, guía por rol, recorridos guiados, glosario, preguntas frecuentes y video.',
        '## Documentos',
        lista([
            '[FLUJO_COMPLETO.md](FLUJO_COMPLETO.md) — el recorrido completo, de la plantilla autorizada al contrato indeterminado y la vida laboral, con todos los artículos.',
            '[RH.md](RH.md) — Manual operativo de Recursos Humanos.',
            '[GERENTES.md](GERENTES.md) — Guía rápida para Gerentes (sucursal, regional y Dirección Comercial).',
            '[COLABORADORES.md](COLABORADORES.md) — Cómo usar la app MR. LANA PEOPLE.',
            '[SISTEMAS.md](SISTEMAS.md) — Altas, bajas, cuentas y roles.',
            '[GLOSARIO.md](GLOSARIO.md) · [PREGUNTAS_FRECUENTES.md](PREGUNTAS_FRECUENTES.md)',
            '[video/](video/) — paquete del video tutorial: guion palabra por palabra, storyboard, capturas, transcripción, subtítulos, videos cortos y producción.',
            '[video/MR_LANA_PEOPLE_GUIA_COMPLETA.mp4](video/MR_LANA_PEOPLE_GUIA_COMPLETA.mp4) — el video completo (capturas reales, subtítulos; voz sintética de referencia) y [video/cortos/](video/cortos/) — los 6 videos cortos. Cómo producir la versión final: [video/PRODUCCION.md](video/PRODUCCION.md).',
            '[DATOS_DEMO.md](DATOS_DEMO.md) — escenario ficticio (solo local) con el que se graban capturas y video.',
        ]),
        '## Procesos',
        '| # | Proceso | Tiempo | Roles | Artículos |\n|---|---|---|---|---|\n' +
            PROCESOS.map(
                (p) =>
                    `| ${p.numero} | [${p.titulo}](FLUJO_COMPLETO.md#proceso-${p.id}) | ${p.tiempo} | ${p.roles.map((r) => NOMBRE_ROL[r]).join(', ')} | ${p.articulos.length} |`,
            ).join('\n'),
        '## Dónde vive cada cosa en el código',
        lista([
            '`resources/js/lib/guia/` — **fuente única** del contenido: procesos, artículos, flujo, roles, glosario, preguntas y guion del video.',
            '`resources/js/pages/Ayuda/*` y `resources/js/components/guia/*` — el centro de Guía en la web (`/ayuda`, `/ayuda/proceso/{id}`, `/ayuda/articulo/{id}`, `/ayuda/rol/{id}`, `/ayuda/video`, `/ayuda/glosario`, `/ayuda/preguntas`).',
            '`resources/js/lib/guia/contextual.ts` — qué artículo abre el botón «¿Cómo funciona?» en cada pantalla.',
            '`resources/js/lib/tours/recorridos.ts` — recorridos guiados por proceso (Primer día RH, Crear vacante y campaña, Contratar un candidato, Revisar un expediente, Emitir nómina, Autorizar un permiso); `modulos.ts`, los recorridos por pantalla.',
            '`scripts/guia/` — generador de estos documentos y scripts de captura y composición del video.',
        ]),
        '## Cómo actualizar la guía',
        lista(
            [
                'Cambia el contenido en `resources/js/lib/guia/*.ts` (nunca en estos `.md`).',
                'Ejecuta `npm run guia:docs` para regenerar `docs/guias/**`.',
                'Corre `npm run test:js` (verifica referencias, buscador y que los docs estén al día) y `php artisan test tests/Feature/Ayuda` (rutas reales, anclajes de los recorridos y páginas).',
                'Si cambió una pantalla, vuelve a tomar sus capturas con `node scripts/guia/grabar-capturas.mjs` (ver `video/PRODUCCION.md`).',
            ],
            '1. ',
        ),
    ].join('\n\n'),
);

// ─────────────────────────────── Video ───────────────────────────────

const escenas = video.escenasVideo();
const fmt = video.formatoTiempo;
const duracionTotal = fmt(escenas.at(-1).fin);

escribir(
    'video/GUION_VIDEO_COMPLETO.md',
    [
        `${AVISO}# Guion de voz — ${video.TITULO_VIDEO}`,
        `Duración estimada: **${duracionTotal}** · Formato 16:9, 1080p · Voz en español de México neutro, clara y natural · Música de fondo discreta (opcional, a −28 dB bajo la voz).`,
        'Texto **palabra por palabra** de la locución. Entre corchetes, lo que se ve en pantalla.',
        ...video
            .capitulosVideo()
            .map((c) =>
                [
                    `## ${fmt(c.inicio)} — ${c.titulo}`,
                    ...escenas
                        .filter((e) => e.capitulo === c.titulo)
                        .map(
                            (e) =>
                                `**[${fmt(e.inicio)} · ${e.pantalla}]**\n\n${e.narracion}`,
                        ),
                ].join('\n\n'),
            ),
    ].join('\n\n'),
);

escribir(
    'video/STORYBOARD.md',
    [
        `${AVISO}# Storyboard — ${video.TITULO_VIDEO}`,
        `${escenas.length} escenas · ${duracionTotal}. Cada escena se graba con datos ficticios del escenario de [DATOS_DEMO.md](../DATOS_DEMO.md).`,
        ...escenas.map((e) =>
            [
                `## Escena ${String(e.numero).padStart(2, '0')} · ${fmt(e.inicio)}–${fmt(e.fin)}`,
                `| | |\n|---|---|\n| **Capítulo** | ${e.capitulo} |\n| **Pantalla** | ${e.pantalla}${e.ruta ? ` (\`${e.ruta}\`)` : ''} |\n| **Acción** | ${e.accion} |\n| **Zoom** | ${e.zoom} |\n| **Texto en pantalla** | ${e.textoPantalla} |\n| **Captura / clip** | \`${e.captura}\` |\n| **Transición** | ${e.transicion} |`,
                `**Narración:** ${e.narracion}`,
            ].join('\n\n'),
        ),
    ].join('\n\n'),
);

escribir(
    'video/CAPTURAS_NECESARIAS.md',
    [
        `${AVISO}# Capturas y clips necesarios`,
        'Lista exacta de pantallas, en orden. Los PNG reales están en [capturas/](capturas/) y los produce `node scripts/guia/grabar-capturas.mjs` (solo local, datos ficticios de DemoSeeder). Si se graban clips en movimiento para una versión editada, se nombran igual con `.mp4` y siguen la acción indicada. Las escenas del colaborador se toman del portal web real en tamaño celular; para grabar la app nativa se usa un celular o emulador con la cuenta demo.',
        '> **Privacidad:** nunca grabar contraseñas, tokens, correos reales, CURP/RFC/NSS reales, teléfonos, domicilios ni rutas del NAS. Solo el escenario de [DATOS_DEMO.md](../DATOS_DEMO.md), en local o testing.',
        '| # | Archivo | Pantalla | Ruta | Qué debe verse |\n|---|---|---|---|---|\n' +
            escenas
                .map(
                    (e) =>
                        `| ${String(e.numero).padStart(2, '0')} | \`${e.captura}\` → \`${e.captura.replace('.png', '.mp4')}\` | ${e.pantalla} | ${e.ruta ? `\`${e.ruta}\`` : '—'} | ${e.accion} |`,
                )
                .join('\n'),
    ].join('\n\n'),
);

escribir(
    'video/TRANSCRIPCION.md',
    [
        `${AVISO}# Transcripción — ${video.TITULO_VIDEO}`,
        ...video.capitulosVideo().map((c) =>
            [
                `## ${c.titulo} (${fmt(c.inicio)})`,
                escenas
                    .filter((e) => e.capitulo === c.titulo)
                    .map((e) => e.narracion)
                    .join(' '),
            ].join('\n\n'),
        ),
    ].join('\n\n'),
);

/** Divide una narración en subtítulos de ≤ 2 líneas de 42 caracteres. */
function bloquesSubtitulo(texto) {
    const palabras = texto.split(/\s+/);
    const bloques = [];
    let actual = '';

    for (const p of palabras) {
        const prueba = actual ? `${actual} ${p}` : p;

        if (
            prueba.length > 84 ||
            (/[.?!]$/.test(actual) && actual.length > 30)
        ) {
            bloques.push(actual);
            actual = p;
        } else {
            actual = prueba;
        }
    }

    if (actual) {
        bloques.push(actual);
    }

    return bloques.map((b) => {
        if (b.length <= 42) {
            return b;
        }

        const corte = b.lastIndexOf(' ', 42) > 0 ? b.lastIndexOf(' ', 42) : 42;

        return `${b.slice(0, corte)}\n${b.slice(corte + 1)}`;
    });
}

function tiempoSrt(s, separador) {
    const ms = Math.round(s * 1000);
    const h = Math.floor(ms / 3600000);
    const m = Math.floor((ms % 3600000) / 60000);
    const seg = Math.floor((ms % 60000) / 1000);
    const resto = ms % 1000;

    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(seg).padStart(2, '0')}${separador}${String(resto).padStart(3, '0')}`;
}

function subtitulos() {
    const cues = [];

    for (const e of escenas) {
        const bloques = bloquesSubtitulo(e.narracion);
        const total = bloques.reduce((n, b) => n + b.split(/\s+/).length, 0);
        // La voz empieza 1 s después del corte y dura palabras / ritmo.
        let t = e.inicio + 1;
        const voz = total / video.PALABRAS_POR_SEGUNDO;

        for (const b of bloques) {
            const d = (b.split(/\s+/).length / total) * voz;
            cues.push({
                inicio: t,
                fin: Math.min(t + d, e.fin - 0.1),
                texto: b,
            });
            t += d;
        }
    }

    return cues;
}

escribir(
    'video/subtitulos.srt',
    subtitulos()
        .map(
            (c, i) =>
                `${i + 1}\n${tiempoSrt(c.inicio, ',')} --> ${tiempoSrt(c.fin, ',')}\n${c.texto}\n`,
        )
        .join('\n'),
);
escribir(
    'video/subtitulos.vtt',
    'WEBVTT\n\n' +
        subtitulos()
            .map(
                (c) =>
                    `${tiempoSrt(c.inicio, '.')} --> ${tiempoSrt(c.fin, '.')}\n${c.texto}\n`,
            )
            .join('\n'),
);

escribir(
    'video/MINI_VIDEOS.md',
    [
        `${AVISO}# Videos cortos (1–3 minutos)`,
        'Mismo estilo que el video principal; cada uno termina con «Encuentra la guía completa en Ayuda».',
        ...video.MINI_VIDEOS.map((m) =>
            [
                `## ${m.titulo} · ${m.duracion} min`,
                `Artículo de referencia: ${documentoDe(m.articulo, null)}`,
                '| # | Pantalla | Acción | Narración |\n|---|---|---|---|\n' +
                    m.escenas
                        .map(
                            (e, i) =>
                                `| ${i + 1} | ${e.pantalla} | ${e.accion} | ${e.narracion} |`,
                        )
                        .join('\n'),
            ].join('\n\n'),
        ),
    ].join('\n\n'),
);

escribir(
    'video/capitulos.txt',
    video
        .capitulosVideo()
        .map((c) => `${fmt(c.inicio)} ${c.titulo}`)
        .join('\n'),
);

// ─────────────────────────────── Salida ───────────────────────────────

const comprobar = process.argv.includes('--comprobar');
const desactualizados = [];

for (const [ruta, contenido] of archivos) {
    const actual = existsSync(ruta) ? readFileSync(ruta, 'utf8') : null;

    if (actual === contenido) {
        continue;
    }

    if (comprobar) {
        desactualizados.push(relative(RAIZ, ruta));
    } else {
        mkdirSync(dirname(ruta), { recursive: true });
        writeFileSync(ruta, contenido);
        console.log(`✓ ${relative(RAIZ, ruta)}`);
    }
}

if (comprobar) {
    if (desactualizados.length) {
        console.error(
            `Docs de la guía desactualizados (corre «npm run guia:docs»):\n- ${desactualizados.join('\n- ')}`,
        );
        process.exit(1);
    }

    console.log(`Docs de la guía al día (${archivos.size} archivos).`);
} else {
    console.log(
        `Guía: ${ARTICULOS.length} artículos, ${PROCESOS.length} procesos, video ${duracionTotal}.`,
    );
}
