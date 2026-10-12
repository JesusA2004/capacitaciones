#!/usr/bin/env node
/**
 * Toma las capturas REALES de la Guía maestra y del video tutorial desde el
 * sistema corriendo en LOCAL con los datos ficticios de DemoSeeder.
 *
 *   php artisan migrate:fresh --seed          # base local recién sembrada
 *   php artisan serve --port=8123             # en otra terminal
 *   node scripts/guia/grabar-capturas.mjs     # → docs/guias/video/capturas/*.png
 *                                             #   public/guia/capturas/{articulo}.webp
 *
 * Variables opcionales: GUIA_URL (default http://127.0.0.1:8123),
 * GUIA_PASSWORD (default la contraseña DEMO de los seeders locales),
 * GUIA_CHROME (ruta de Chromium), GUIA_SOLO="05,09" (solo esas escenas).
 *
 * Reglas de privacidad que el script hace cumplir:
 *  - Se niega a correr contra algo que no sea localhost/127.0.0.1/*.test.
 *  - Nunca captura la contraseña: el campo se llena fuera de cuadro.
 *  - Antes de cada captura oculta correos, CURP, RFC, NSS, teléfonos,
 *    tokens de QR y rutas del NAS, y difumina los códigos QR.
 *  - Solo usa las personas ficticias del escenario demo
 *    (scripts/guia/escenario-demo.php, que tampoco corre fuera de local).
 *
 * Las escenas de portada, cierre y app se arman como tarjetas con la paleta
 * de PEOPLE; las de la app incrustan la pantalla real del portal del
 * colaborador en tamaño celular (no son maquetas).
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import puppeteer from 'puppeteer';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SALIDA = join(RAIZ, 'docs', 'guias', 'video', 'capturas');
const PUBLICO = join(RAIZ, 'public', 'guia', 'capturas');
const BASE = (process.env.GUIA_URL ?? 'http://127.0.0.1:8123').replace(
    /\/$/,
    '',
);
const PASSWORD = process.env.GUIA_PASSWORD ?? 'Capacitacion2026!';
const SOLO = process.env.GUIA_SOLO
    ? process.env.GUIA_SOLO.split(',').map((s) => s.trim().padStart(2, '0'))
    : null;

const host = new URL(BASE).hostname;

if (!['localhost', '127.0.0.1'].includes(host) && !host.endsWith('.test')) {
    console.error(
        `grabar-capturas: ${BASE} no es local. Las capturas solo se toman en local/testing con datos ficticios.`,
    );
    process.exit(1);
}

const { escenasVideo, TITULO_VIDEO } = await import(
    pathToFileURL(join(RAIZ, 'resources', 'js', 'lib', 'guia', 'video.ts')).href
);
const escenas = escenasVideo();

const e = JSON.parse(
    execFileSync('php', [join(RAIZ, 'scripts', 'guia', 'escenario-demo.php')], {
        cwd: RAIZ,
        encoding: 'utf8',
    }),
);

mkdirSync(SALIDA, { recursive: true });
mkdirSync(PUBLICO, { recursive: true });

const chrome = [
    process.env.GUIA_CHROME,
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
].find((r) => r && existsSync(r));
const navegador = await puppeteer.launch({
    headless: true,
    executablePath: chrome,
    args: ['--no-sandbox', '--font-render-hinting=none', '--lang=es-MX'],
});

const ESCRITORIO = { width: 1920, height: 1080, deviceScaleFactor: 1 };
const CELULAR = {
    width: 390,
    height: 844,
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
};

/* ───────────── utilidades de página ───────────── */

const sesiones = new Map();

/** Una pestaña con sesión iniciada por rol (rh, gerente, colaborador). */
async function pestana(rol, vista = ESCRITORIO) {
    const clave = `${rol}-${vista.width}`;

    if (sesiones.has(clave)) {
        return sesiones.get(clave);
    }

    const contexto = await navegador.createBrowserContext();
    const page = await contexto.newPage();
    await page.setViewport(vista);
    await page.emulateTimezone('America/Mexico_City');

    if (rol) {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
        await page.type('#username', e.usuarios[rol]);
        await page.type('#password', PASSWORD);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.keyboard.press('Enter'),
        ]);

        if (page.url().includes('/login')) {
            throw new Error(
                `No se pudo iniciar sesión como ${rol} (${e.usuarios[rol]}).`,
            );
        }
    }

    sesiones.set(clave, page);

    return page;
}

async function ir(page, ruta) {
    await page.goto(`${BASE}${ruta}`, { waitUntil: 'networkidle0' });
    await esperar(400);
}

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

/** Clic en el botón/enlace visible cuyo texto contiene `texto`. */
async function clic(page, texto) {
    const hecho = await page.evaluate((t) => {
        const candidatos = [
            ...document.querySelectorAll(
                'button, a, [role="button"], [role="tab"]',
            ),
        ];
        const el = candidatos.find(
            (n) =>
                n.offsetParent !== null &&
                n.textContent.replace(/\s+/g, ' ').trim().includes(t),
        );
        el?.click();

        return Boolean(el);
    }, texto);

    if (!hecho) {
        console.warn(`  ⚠ no encontré «${texto}» en ${page.url()}`);
    }

    await esperar(700);

    return hecho;
}

/** Desplaza hasta un [data-tour] o hasta el primer texto que coincida. */
async function desplazar(page, { tour, texto }) {
    await page.evaluate(
        (tour, texto) => {
            let el = tour
                ? document.querySelector(`[data-tour="${tour}"]`)
                : null;

            if (!el && texto) {
                const xp = document.evaluate(
                    `//*[contains(normalize-space(text()), "${texto}")]`,
                    document,
                    null,
                    XPathResult.FIRST_ORDERED_NODE_TYPE,
                    null,
                );
                el = xp.singleNodeValue;
            }

            el?.scrollIntoView({ block: 'center' });
        },
        tour ?? null,
        texto ?? null,
    );
    await esperar(500);
}

/** Escribe en el campo cuyo label/placeholder contiene `etiqueta`. */
async function escribir(page, etiqueta, valor) {
    const ok = await page.evaluate((etiqueta) => {
        const dialogo = document.querySelector('[role="dialog"]') ?? document;
        const labels = [...dialogo.querySelectorAll('label')];
        const label = labels.find((l) =>
            l.textContent
                .trim()
                .toLowerCase()
                .startsWith(etiqueta.toLowerCase()),
        );
        let input = label?.htmlFor
            ? document.getElementById(label.htmlFor)
            : label?.parentElement?.querySelector('input, textarea');
        input ??= [...dialogo.querySelectorAll('input, textarea')].find((i) =>
            (i.placeholder ?? '')
                .toLowerCase()
                .includes(etiqueta.toLowerCase()),
        );

        if (!input) {
            return false;
        }

        input.setAttribute('data-guia-escribir', '1');
        input.focus();

        return true;
    }, etiqueta);

    if (ok) {
        await page.type('[data-guia-escribir="1"]', String(valor), {
            delay: 5,
        });
        await page.evaluate(() =>
            document
                .querySelector('[data-guia-escribir="1"]')
                ?.removeAttribute('data-guia-escribir'),
        );
    } else {
        console.warn(`  ⚠ no encontré el campo «${etiqueta}»`);
    }
}

/**
 * Privacidad: oculta en pantalla cualquier dato que parezca personal o
 * sensible, aunque el escenario ya sea ficticio (doble candado).
 */
async function ocultarSensibles(page) {
    await page.evaluate(() => {
        const reglas = [
            [/[\w.+-]+@[\w-]+(\.[\w-]+)+/g, '•••@•••'],
            [
                /\b[A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{5}[A-Z0-9]\d\b/g,
                'CURP ••••',
            ],
            [/\b[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}\b/g, 'RFC ••••'],
            [/\b\d{11}\b/g, 'NSS ••••'],
            [
                /(\+?52[\s-]?)?\b\d{2,3}[\s-]?\d{3,4}[\s-]?\d{4}\b/g,
                '••• ••• ••••',
            ],
            [
                /(incorporacion\/qr\/|mrlanapeople:\/\/[\w/]*?)[A-Za-z0-9_-]{16,}/g,
                '$1••••••',
            ],
            [
                /(\/(mnt|volume\d+|nas|share)\/|\\\\)[^\s"']+/gi,
                '[ruta privada]',
            ],
            // Código manual de una invitación: es una llave de registro.
            [/(C[óo]digo:?\s*)[A-Z0-9]{6,10}\b/g, '$1••••••'],
            [
                /^\s*(?=[A-Z0-9 ]*\d)(?=[A-Z0-9 ]*[A-Z])[A-Z0-9](\s?[A-Z0-9]){5,9}\s*$/g,
                '• • • • • •',
            ],
        ];
        // Domicilios (aunque sean de sucursal): el renglón completo se oculta.
        const domicilio =
            /(^|\s)(Av\.|Avenida|Calle|Blvd\.|Calz\.|Col\.|C\.P\.)\s/;
        const limpiar = (texto) =>
            domicilio.test(texto) && texto.length > 12
                ? '[domicilio oculto]'
                : reglas.reduce((t, [re, rep]) => t.replace(re, rep), texto);

        const caminante = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
        );

        for (let n = caminante.nextNode(); n; n = caminante.nextNode()) {
            const limpio = limpiar(n.nodeValue);

            if (limpio !== n.nodeValue) {
                n.nodeValue = limpio;
            }
        }

        document.querySelectorAll('input, textarea').forEach((i) => {
            if (i.type === 'password') {
                i.value = '';
            } else if (i.value) {
                i.value = limpiar(i.value);
            }
        });

        // Códigos QR: se difuminan siempre (un QR vigente es una llave).
        document.querySelectorAll('img, svg, canvas').forEach((el) => {
            const caja = el.getBoundingClientRect();
            const cuadradoGrande =
                caja.width >= 160 &&
                Math.abs(caja.width - caja.height) < 8 &&
                !el.closest('[data-guia-tarjeta]');
            const desc =
                `${el.getAttribute('alt') ?? ''} ${el.getAttribute('aria-label') ?? ''} ${el.closest('[data-qr], [aria-label*="QR" i]') ? 'qr' : ''}`.toLowerCase();
            const esSvgQr =
                el.tagName.toLowerCase() === 'svg' &&
                el.querySelectorAll('rect, path').length > 150;

            if (desc.includes('qr') || esSvgQr || cuadradoGrande) {
                el.style.filter = 'blur(9px)';
            }
        });

        // Visores de PDF (recibos, contratos): su contenido trae RFC, CURP y
        // NSS del documento, así que se difuminan completos.
        document
            .querySelectorAll(
                'iframe, embed, object, canvas.pdf-page, [data-pdf-viewer]',
            )
            .forEach((el) => {
                el.style.filter = 'blur(7px)';
            });

        // Avisos efímeros y el indicador de Vite no salen en cuadro.
        document
            .querySelectorAll('[data-sonner-toaster], vite-error-overlay')
            .forEach((el) => el.remove());
    });
}

async function capturar(page, archivo) {
    await ocultarSensibles(page);
    await esperar(150);
    const ruta = join(SALIDA, archivo);
    await page.screenshot({ path: ruta, type: 'png' });

    return ruta;
}

/* ───────────── tarjetas (portada, cierre, escenas de celular) ───────────── */

const PALETA = {
    crema: '#f6efe1',
    marfil: '#fbf8f1',
    salvia: '#dfe8dc',
    olivo: '#e7ebd3',
    oro: '#c8a35a',
    petroleo: '#1f4b4f',
    texto: '#2b2a26',
    suave: '#6b665c',
};

function htmlTarjeta({ etiqueta, titulo, subtitulo, imagen }) {
    return `<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
        *{box-sizing:border-box;margin:0}
        body{width:1920px;height:1080px;font-family:Inter,'DejaVu Sans',sans-serif;color:${PALETA.texto};
          background:radial-gradient(1200px 700px at 15% 10%, ${PALETA.marfil}, transparent),
                     radial-gradient(900px 700px at 90% 90%, ${PALETA.salvia}, transparent), ${PALETA.crema};
          display:flex;align-items:center;justify-content:${imagen ? 'space-between' : 'center'};padding:0 160px;gap:96px}
        .txt{max-width:${imagen ? '900px' : '1400px'};text-align:${imagen ? 'left' : 'center'}}
        .eti{font-size:26px;letter-spacing:.28em;text-transform:uppercase;color:#9a7a3a;font-weight:600}
        h1{font-size:${imagen ? '72px' : '96px'};line-height:1.05;margin:28px 0;color:${PALETA.petroleo};font-weight:700;letter-spacing:-.02em}
        p{font-size:34px;line-height:1.4;color:${PALETA.suave}}
        .raya{width:120px;height:6px;border-radius:6px;background:${PALETA.oro};margin:${imagen ? '0' : '0 auto'} 0 0}
        .cel{width:420px;height:900px;border-radius:56px;background:#1d1d1b;padding:14px;box-shadow:0 40px 80px rgba(31,75,79,.25)}
        .cel img{width:100%;height:100%;border-radius:44px;object-fit:cover;object-position:top}
    </style></head><body>
        <div class="txt"><div class="eti">${etiqueta}</div><h1>${titulo}</h1>${imagen ? '' : '<div class="raya"></div>'}<p style="margin-top:28px">${subtitulo}</p></div>
        ${imagen ? `<div class="cel" data-guia-tarjeta><img src="data:image/png;base64,${imagen}"></div>` : ''}
    </body></html>`;
}

async function tarjeta(archivo, datos) {
    const page = await pestana(null);
    await page.setContent(htmlTarjeta(datos), { waitUntil: 'load' });
    await esperar(200);

    return capturar(page, archivo);
}

/** Pantalla real en tamaño celular, enmarcada en una tarjeta 16:9. */
async function celular(archivo, rol, ruta, textos, preparar) {
    const page = await pestana(rol, CELULAR);
    await ir(page, ruta);
    await preparar?.(page);
    await ocultarSensibles(page);
    const imagen = Buffer.from(await page.screenshot({ type: 'png' })).toString(
        'base64',
    );

    return tarjeta(archivo, { ...textos, imagen });
}

/* ───────────── plan de capturas (una por escena) ───────────── */

const C = e.candidatos;
const P = e.colaboradores;
const estado = {};

const PLAN = {
    '00-portada.png': () =>
        tarjeta('00-portada.png', {
            etiqueta: 'Guía del sistema',
            titulo: TITULO_VIDEO.replace(' — ', '<br>'),
            subtitulo:
                'De la necesidad de personal a la vida laboral del colaborador.',
        }),
    '01-login.png': async () => {
        const page = await pestana(null);
        await ir(page, '/login');
        await page.type('#username', e.usuarios.rh);

        return capturar(page, '01-login.png');
    },
    '02-dashboard-rh.png': ['rh', '/dashboard'],
    '03-plantilla-sucursal.png': [
        'rh',
        `/administracion/sucursales/${e.sucursal}`,
        (p) => desplazar(p, { texto: 'Gestor' }),
    ],
    '04-vacantes.png': ['rh', '/rh/vacantes'],
    '05-campana-nueva.png': [
        'rh',
        '/rh/campanas',
        (p) => clic(p, 'Nueva campaña'),
    ],
    '06-campana-metricas.png': ['rh', '/rh/campanas'],
    '07-candidato-nuevo.png': [
        'rh',
        '/rh/candidatos',
        async (p) => {
            await clic(p, 'Nuevo candidato');
            await escribir(p, 'Nombre', 'Juan');
            await escribir(p, 'Apellidos', 'Pérez Demo');
        },
    ],
    '08-candidatos-tablero.png': ['rh', '/rh/candidatos'],
    '09-candidato-ficha.png': ['rh', `/rh/candidatos/${C.filtro}`],
    '10-entrevista.png': [
        'gerente',
        `/rh/candidatos/${C.entrevista}`,
        (p) => clic(p, 'Registrar entrevista'),
    ],
    '11-psicometricos.png': [
        'rh',
        `/rh/candidatos/${C.psicometricas}`,
        (p) => clic(p, 'Registrar resultados'),
    ],
    '12-socioeconomico.png': [
        'gerente',
        `/rh/candidatos/${C.socioeconomico}`,
        (p) => clic(p, 'Registrar estudio socioeconómico'),
    ],
    '13-referencias-preautorizacion.png': [
        'gerente',
        `/rh/candidatos/${C.preseleccion}`,
        (p) => desplazar(p, { tour: 'candidato-acciones' }),
    ],
    '14-intervencion.png': [
        'rh',
        `/rh/candidatos/${C.rechazado}`,
        (p) => desplazar(p, { texto: 'Intervención' }),
    ],
    '15-generar-qr.png': async () => {
        if (!C.autorizado) {
            console.warn(
                '  ⚠ no hay candidato autorizado por RH: re-siembra la base local para grabar el QR.',
            );

            return null;
        }

        const page = await pestana('rh');
        await ir(page, `/rh/candidatos/${C.autorizado}`);
        await clic(page, 'Generar QR de contratación');
        await escribir(page, 'Sueldo', '9500');
        const ruta = await capturar(page, '15-generar-qr.png');

        // Confirma: es el flujo real (local) y deja la invitación para las
        // dos escenas siguientes.
        const enviado = await page.evaluate(() => {
            const dialogo = document.querySelector('[role="dialog"]');
            const boton = [
                ...(dialogo?.querySelectorAll(
                    'button[type="submit"], button',
                ) ?? []),
            ]
                .reverse()
                .find((b) => /generar|confirmar|iniciar/i.test(b.textContent));
            boton?.click();

            return Boolean(boton);
        });

        if (enviado) {
            // Inertia navega sin recargar: se espera a que cambie la ruta.
            const llego = await page
                .waitForFunction(
                    () => location.pathname.includes('/invitaciones/'),
                    { timeout: 20000 },
                )
                .then(() => true)
                .catch(() => false);

            if (!llego) {
                const errores = await page.evaluate(
                    () =>
                        document.querySelector('[role="dialog"]')?.innerText ??
                        '',
                );
                console.warn(
                    `  ⚠ la contratación no se confirmó: ${errores.replace(/\s+/g, ' ').slice(200, 900)}`,
                );
            }

            await esperar(1200);
            estado.invitacion = page.url();
            estado.linkQr = await page.evaluate(() => {
                const texto = [
                    ...document.querySelectorAll('a, input, code, p, span'),
                ]
                    .map(
                        (n) =>
                            n.value ??
                            n.getAttribute?.('href') ??
                            n.textContent,
                    )
                    .find((t) =>
                        /\/incorporacion\/qr\/[A-Za-z0-9_-]{16,}/.test(t ?? ''),
                    );

                return (
                    texto?.match(/\/incorporacion\/qr\/[A-Za-z0-9_-]+/)?.[0] ??
                    null
                );
            });
        }

        return ruta;
    },
    '16-invitacion-qr.png': async () => {
        const page = await pestana('rh');

        if (!estado.invitacion?.includes('/invitaciones/')) {
            await ir(page, '/rh/incorporacion/invitaciones');
        }

        return capturar(page, '16-invitacion-qr.png');
    },
    '17-app-registro.png': () =>
        estado.linkQr
            ? celular('17-app-registro.png', null, estado.linkQr, {
                  etiqueta: 'Del QR a la cuenta',
                  titulo: 'La persona escanea el QR',
                  subtitulo:
                      'Página real que abre el QR. Después, en la app, confirma nombre, apellidos y teléfono y crea su contraseña.',
              })
            : null,
    '18-app-documentos.png': () =>
        e.usuarios.colaborador
            ? celular('18-app-documentos.png', 'colaborador', '/mi-proceso', {
                  etiqueta: 'Vista del colaborador',
                  titulo: 'Mi proceso de incorporación',
                  subtitulo:
                      'Documentos del alta, «Completa tu información» y onboarding. Portal real del colaborador en tamaño celular; la app muestra lo mismo.',
              })
            : null,
    '19-expediente.png': ['rh', `/rh/expedientes/${P.documentos}`],
    '20-expediente-faltantes.png': [
        'rh',
        `/rh/expedientes/${P.documentos}`,
        (p) => desplazar(p, { tour: 'expediente-faltantes' }),
    ],
    '21-contrato-inicial.png': [
        'rh',
        `/rh/colaboradores/${P.documentos}/ciclo`,
        (p) => desplazar(p, { texto: 'contratación' }),
    ],
    '22-firma-original.png': [
        'rh',
        `/rh/colaboradores/${P.onboarding}/ciclo`,
        (p) => desplazar(p, { texto: 'Documentos de contratación' }),
    ],
    '23-onboarding-config.png': ['rh', '/rh/onboarding/configuracion'],
    '24-onboarding-cierre.png': [
        'rh',
        `/rh/colaboradores/${P.onboarding}/ciclo`,
    ],
    '25-evaluaciones.png': ['rh', '/rh/evaluaciones'],
    '26-contrato-indeterminado.png': [
        'rh',
        `/rh/colaboradores/${P.indeterminado}/ciclo`,
        (p) => desplazar(p, { texto: 'indeterminado' }),
    ],
    '27-solicitudes.png': ['rh', '/rh/solicitudes'],
    '28-nomina-lotes.png': ['rh', '/rh/nomina/lotes'],
    '29-nomina-emitir.png': [
        'rh',
        `/rh/nomina/lotes/${e.lote}`,
        (p) => clic(p, 'Correctos'),
    ],
    '30-avisos.png': ['rh', '/rh/avisos'],
    '31-baja.png': [
        'rh',
        `/rh/colaboradores/${P.baja}/ciclo`,
        (p) => desplazar(p, { texto: 'baja' }),
    ],
    '32-finiquito.png': [
        'rh',
        `/rh/solicitudes/${e.solicitudes.finiquito}`,
        (p) => desplazar(p, { texto: 'Finiquito' }),
    ],
    '33-reingresos.png': ['rh', '/rh/reingresos'],
    '34-guia.png': ['rh', '/ayuda'],
    '35-cierre.png': () =>
        tarjeta('35-cierre.png', {
            etiqueta: 'MR. LANA PEOPLE',
            titulo: '¿Dudas? Abre Ayuda',
            subtitulo:
                'Busca tu pregunta, sigue un recorrido guiado o pulsa «¿Cómo funciona?» en cualquier pantalla.',
        }),
};

/** Qué captura ilustra cada artículo de la guía (public/guia/capturas). */
const ARTICULOS = {
    'plantilla-y-vacantes': '04-vacantes.png',
    campanas: '06-campana-metricas.png',
    'registrar-candidato': '07-candidato-nuevo.png',
    'etapas-del-candidato': '08-candidatos-tablero.png',
    'filtro-rh': '09-candidato-ficha.png',
    'entrevista-gerente': '10-entrevista.png',
    psicometricos: '11-psicometricos.png',
    socioeconomico: '12-socioeconomico.png',
    referencias: '13-referencias-preautorizacion.png',
    intervencion: '14-intervencion.png',
    'iniciar-contratacion': '15-generar-qr.png',
    'qr-incorporacion': '16-invitacion-qr.png',
    'registro-en-app': '17-app-registro.png',
    'documentos-de-alta': '18-app-documentos.png',
    expediente: '19-expediente.png',
    'completitud-expediente': '20-expediente-faltantes.png',
    'contrato-inicial': '21-contrato-inicial.png',
    'firma-y-original': '22-firma-original.png',
    'configurar-onboarding': '23-onboarding-config.png',
    onboarding: '24-onboarding-cierre.png',
    'evaluacion-capacitacion': '25-evaluaciones.png',
    'contrato-indeterminado': '26-contrato-indeterminado.png',
    'revisar-solicitudes': '27-solicitudes.png',
    'nomina-lotes': '29-nomina-emitir.png',
    avisos: '30-avisos.png',
    baja: '31-baja.png',
    finiquito: '32-finiquito.png',
    reingreso: '33-reingresos.png',
};

/* ───────────── ejecución ───────────── */

const hechas = [];

for (const escena of escenas) {
    const numero = escena.captura.slice(0, 2);

    if (SOLO && !SOLO.includes(numero)) {
        continue;
    }

    const paso = PLAN[escena.captura];

    if (!paso) {
        console.warn(`  ⚠ ${escena.captura}: sin plan de captura`);
        continue;
    }

    try {
        let ruta;

        if (Array.isArray(paso)) {
            const [rol, url, preparar] = paso;
            const page = await pestana(rol);
            await ir(page, url);
            await preparar?.(page);
            ruta = await capturar(page, escena.captura);
        } else {
            ruta = await paso();
        }

        if (ruta) {
            hechas.push(escena.captura);
            console.log(`✓ ${escena.captura}`);
        }
    } catch (error) {
        console.warn(`  ✗ ${escena.captura}: ${error.message}`);
    }
}

await navegador.close();

// Capturas por artículo, ligeras (webp 1600 px) para la Guía web.
for (const [articulo, archivo] of Object.entries(ARTICULOS)) {
    const origen = join(SALIDA, archivo);

    if (hechas.includes(archivo) && existsSync(origen)) {
        execFileSync('ffmpeg', [
            '-v',
            'error',
            '-y',
            '-i',
            origen,
            '-vf',
            'scale=1600:-2',
            '-c:v',
            'libwebp',
            '-quality',
            '78',
            join(PUBLICO, `${articulo}.webp`),
        ]);
    }
}

writeFileSync(
    join(SALIDA, 'escenario.json'),
    JSON.stringify(
        {
            capturas: hechas.length,
            generado: new Date().toISOString().slice(0, 10),
        },
        null,
        2,
    ) + '\n',
);
console.log(
    `\n${hechas.length}/${escenas.length} capturas en docs/guias/video/capturas · ${readFileSync ? Object.keys(ARTICULOS).length : 0} artículos ilustrados en public/guia/capturas`,
);
