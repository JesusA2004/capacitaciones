<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->

# Guía maestra de MR. LANA PEOPLE

Documentación oficial de uso del sistema, escrita contra el **flujo real del código** (rutas, servicios, enums y pantallas). La misma información vive dentro del sistema en **Ayuda → Guía del sistema** (`/ayuda`): buscador, flujo general clicable, 14 procesos, guía por rol, recorridos guiados, glosario, preguntas frecuentes y video.

## Documentos

- [FLUJO_COMPLETO.md](FLUJO_COMPLETO.md) — el recorrido completo, de la plantilla autorizada al contrato indeterminado y la vida laboral, con todos los artículos.
- [RH.md](RH.md) — Manual operativo de Recursos Humanos.
- [GERENTES.md](GERENTES.md) — Guía rápida para Gerentes (sucursal, regional y Dirección Comercial).
- [COLABORADORES.md](COLABORADORES.md) — Cómo usar la app MR. LANA PEOPLE.
- [SISTEMAS.md](SISTEMAS.md) — Altas, bajas, cuentas y roles.
- [GLOSARIO.md](GLOSARIO.md) · [PREGUNTAS_FRECUENTES.md](PREGUNTAS_FRECUENTES.md)
- [video/](video/) — paquete del video tutorial: guion palabra por palabra, storyboard, capturas, transcripción, subtítulos, videos cortos y producción.
- [video/MR_LANA_PEOPLE_GUIA_COMPLETA.mp4](video/MR_LANA_PEOPLE_GUIA_COMPLETA.mp4) — el video completo (capturas reales, subtítulos; voz sintética de referencia) y [video/cortos/](video/cortos/) — los 6 videos cortos. Cómo producir la versión final: [video/PRODUCCION.md](video/PRODUCCION.md).
- [DATOS_DEMO.md](DATOS_DEMO.md) — escenario ficticio (solo local) con el que se graban capturas y video.

## Procesos

| # | Proceso | Tiempo | Roles | Artículos |
|---|---|---|---|---|
| 1 | [Reclutamiento](FLUJO_COMPLETO.md#proceso-reclutamiento) | 1 a 3 semanas por candidato | RH, Gerente, Regional, Dirección Comercial | 11 |
| 2 | [Contratación](FLUJO_COMPLETO.md#proceso-contratacion) | 5 minutos (RH) · el QR vive 24 h | RH | 2 |
| 3 | [Alta del colaborador](FLUJO_COMPLETO.md#proceso-alta) | 1 a 5 días | Colaborador, RH, Sistemas | 4 |
| 4 | [Expediente](FLUJO_COMPLETO.md#proceso-expediente) | Consulta inmediata | RH, Gerente, Colaborador | 3 |
| 5 | [Contratos](FLUJO_COMPLETO.md#proceso-contratos) | 1 a 3 días (firma y envío del original) | RH, Gerente | 2 |
| 6 | [Onboarding](FLUJO_COMPLETO.md#proceso-onboarding) | 1 a 2 semanas | Colaborador, RH, Gerente | 3 |
| 7 | [Evaluación inicial](FLUJO_COMPLETO.md#proceso-evaluacion) | 15 días antes del vencimiento | Gerente, RH | 2 |
| 8 | [Solicitudes](FLUJO_COMPLETO.md#proceso-solicitudes) | 1 a 3 días hábiles | Colaborador, Gerente, Regional, RH | 4 |
| 9 | [Nómina](FLUJO_COMPLETO.md#proceso-nomina) | 30 a 60 minutos por lote | RH | 1 |
| 10 | [Avisos](FLUJO_COMPLETO.md#proceso-avisos) | 2 minutos | RH, Colaborador | 1 |
| 11 | [Vida laboral](FLUJO_COMPLETO.md#proceso-vida-laboral) | Continuo | Colaborador, RH, Gerente | 5 |
| 12 | [Baja y finiquito](FLUJO_COMPLETO.md#proceso-baja) | 1 a 3 semanas | Gerente, Regional, RH, Sistemas | 2 |
| 13 | [Reingresos](FLUJO_COMPLETO.md#proceso-reingresos) | 1 a 5 días | Gerente, RH | 1 |
| 14 | [Administración](FLUJO_COMPLETO.md#proceso-administracion) | Según el ajuste | RH, Sistemas | 9 |

## Dónde vive cada cosa en el código

- `resources/js/lib/guia/` — **fuente única** del contenido: procesos, artículos, flujo, roles, glosario, preguntas y guion del video.
- `resources/js/pages/Ayuda/*` y `resources/js/components/guia/*` — el centro de Guía en la web (`/ayuda`, `/ayuda/proceso/{id}`, `/ayuda/articulo/{id}`, `/ayuda/rol/{id}`, `/ayuda/video`, `/ayuda/glosario`, `/ayuda/preguntas`).
- `resources/js/lib/guia/contextual.ts` — qué artículo abre el botón «¿Cómo funciona?» en cada pantalla.
- `resources/js/lib/tours/recorridos.ts` — recorridos guiados por proceso (Primer día RH, Crear vacante y campaña, Contratar un candidato, Revisar un expediente, Emitir nómina, Autorizar un permiso); `modulos.ts`, los recorridos por pantalla.
- `scripts/guia/` — generador de estos documentos y scripts de captura y composición del video.

## Cómo actualizar la guía

1. Cambia el contenido en `resources/js/lib/guia/*.ts` (nunca en estos `.md`).
1. Ejecuta `npm run guia:docs` para regenerar `docs/guias/**`.
1. Corre `npm run test:js` (verifica referencias, buscador y que los docs estén al día) y `php artisan test tests/Feature/Ayuda` (rutas reales, anclajes de los recorridos y páginas).
1. Si cambió una pantalla, vuelve a tomar sus capturas con `node scripts/guia/grabar-capturas.mjs` (ver `video/PRODUCCION.md`).
