# Inducción Mr. Lana y descriptivo de puesto — asignación, Mi espacio y vista previa

Grupo «3 · Onboarding». Complementa `docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md` y `docs/ROLES_Y_NAVEGACION.md`.

## Qué ve la persona («Mi inducción»)

La inducción es **autoservicio**: la persona la ve por su cuenta en «Mi espacio» (y en la app) mientras la tiene asignada. Ya no depende de una sesión en vivo de RH (eso quedó tras `features.induccion_sesiones_legacy`, apagado).

Pasos (DTO único `App\Services\Onboarding\ProgresoInduccionService::resumen()`, el mismo en Mi inicio, Mis pendientes, la app —campo aditivo `miInduccion` de `GET /api/v1/colaborador/mi-proceso`— y «Ver como colaborador»):

1. **Presentación** — hojas del PDF oficial (`config/induccion.php`), avance por hoja mostrada (`InduccionPdfService::registrarPagina`).
2. **Video o audio** — material de explicación vigente (`MaterialInduccionService`). El video es obligatorio y con anti-adelanto; el audio no bloquea.
3. **Evaluación** — FO-RH-EI; se desbloquea con «Terminé la inducción» (`InduccionPdfService::terminar`, única fuente del gate) y `EvaluacionInduccionService::habilitarPara`.
4. **Resultado** — RH califica los casos prácticos; ≥ mínimo aprueba.

`AccesoInduccionColaboradorService::estado()` sigue siendo la verdad del acceso (`sin_asignar`, `disponible`, `evaluacion_pendiente`, `en_revision`, `completada`).

## «Asignar inducción» (RH, `onboarding.gestionar`)

Desde **Inducción institucional** (`induccion.presentacion`) y **Calificaciones y refuerzo**. A quién: **todos**, **una o varias personas** (buscador sin mínimo de letras) o **por sucursal**, siempre dentro del alcance del módulo `onboarding` (`DestinatariosOnboardingService`, `limitarPorModulo`), nunca a sí mismo, solo activos o en incorporación. Qué: presentación y/o video/audio, fecha límite y mensaje opcionales. Antes de confirmar el servidor devuelve el resumen («Se asignará a 37 personas de Sucursal Centro») y a quién omite y por qué.

### Modelo (mínimo y coherente)

- La inducción vive **siempre** en el avance institucional con evaluación formal de un `OnboardingProceso` (ahí está el progreso del PDF, del video y la evaluación). No hay un segundo lugar.
- **Persona con onboarding de ingreso abierto**: se reasigna sobre ese mismo proceso. Solo cambia qué debe ver, la fecha límite y el mensaje.
- **Persona sin onboarding de ingreso abierto** (personal activo o cuyo onboarding ya cerró): se abre una **reinducción** — `onboarding_procesos.tipo = 'reinduccion'` (`App\Enums\TipoProcesoOnboarding`), sin contrato, sin ocupar `colaborador_abierto_id` y solo con el módulo formal (`OnboardingService::iniciarReinduccion`). Al aprobarla, `OnboardingService::completar()` la cierra **sin** `AltaColaboradorService::recalcularEstado()` ni `activar()`, sin activos ni responsivas. `OnboardingService::procesoActual()` solo ve procesos de **ingreso**, así que la reinducción no altera el ciclo laboral, la contratación ni el expediente; la inducción de Mi espacio la resuelve `AccesoInduccionColaboradorService::procesoInduccion()`.
- Quien ya aprobó se omite salvo que RH marque **Reinducción**.
- `induccion_asignaciones`: qué asignó RH (`incluye_presentacion`, `incluye_material`, `fecha_limite`, `mensaje`), con `vigente = true` para la activa y `NULL` para las retiradas/reemplazadas (historial). Sin filas, el avance conserva la asignación automática del ingreso (todo). `InduccionPdfService::terminar()` solo exige lo asignado.
- **Retirar**: mientras no haya contestado la evaluación. En ingreso deja «Sin asignar» (sin borrar avance); en reinducción cancela el proceso.
- Aviso a la persona por `NotificacionEventosService::crearPendiente()` (web + app, evento `induccion_asignada`, tipo `onboarding_habilitado`), después del commit; un fallo al avisar nunca deshace la asignación.

## «Ver como colaborador» (solo lectura)

`rh.onboarding.induccion.vista-colaborador/{colaborador?}` (`Rh/Onboarding/VistaColaboradorInduccion`): la tarjeta «Mi inducción», el visor (hojas + material con el reproductor de vista previa de RH, sin anti-adelanto) y el paso de evaluación bloqueado/desbloqueado. Sin `{colaborador}`: un colaborador nuevo genérico. No registra hojas, video ni evaluación. Mismos componentes que el portal (`components/onboarding/*`).

## Descriptivo de puesto

- **Asignar a…** (mismo `AsignarDialog`): `AcuseDescriptivoPuestoService::asignar()` envía el descriptivo oficial vigente de SU puesto a cada destinatario; omite y reporta a quien ya lo tiene pendiente, ya lo dio por enterado o no tiene descriptivo para su puesto. Quien está en su **ingreso** lo recibe solo después de aprobar su inducción; al personal que ya labora se le puede enviar en cualquier momento.
- **Mi espacio** (`portal.descriptivo-puesto`, `Portal/DescriptivoPuesto`): casilla obligatoria «Enterado(a) de las actividades y responsabilidades de mi puesto descritas en este documento.» + campo obligatorio «Estoy de acuerdo con…». Se guardan `enterado_texto`, `enterado_en` y `conformidad` (además de `comentario`). La app instalada sigue mandando `enterado` + `comentario`; `conformidad` es opcional/aditivo en `POST /api/v1/colaborador/descriptivo-puesto/{acuse}/acusar` (sin él, el comentario se guarda también como conformidad).
- **Vista previa como colaborador**: `rh.onboarding.descriptivo-puesto.vista-colaborador/{colaborador}` — su acuse real o el que recibiría (acuse en memoria); no marca leído ni envía nada.
- No existe PDF de acuse del descriptivo: RH ve ambos campos en «Inducción al puesto».

## Rutas

| Ruta | Para qué |
|---|---|
| `GET rh/onboarding/asignar/colaboradores?q=` | Buscador del diálogo (lista completa al enfocar) |
| `GET rh/onboarding/asignar/sucursales` | Sucursales del alcance con su número de personas |
| `POST rh/onboarding/induccion/asignaciones/previsualizar` · `POST …/asignaciones` · `DELETE …/asignaciones/{colaborador}` | Resumen, asignar, retirar inducción |
| `POST rh/onboarding/descriptivo-puesto/asignaciones/previsualizar` · `POST …/asignaciones` | Resumen y asignar descriptivo |
| `GET rh/onboarding/induccion/vista-colaborador/{colaborador?}` · `GET rh/onboarding/descriptivo-puesto/vista-colaborador/{colaborador}` | Ver como colaborador |
| `GET mi-proceso/descriptivo-puesto` · `POST mi-proceso/descriptivo-puesto/{acuse}/acusar` | Mi descriptivo de puesto |

## Límites conocidos

- «Todos» procesa en la misma petición (tope `DestinatariosOnboardingService::MAXIMO` = 2000); cada persona es su propia transacción y los avisos ya salen por la cola `notifications`.
- «Inducción al puesto» sigue listando solo a quien tiene onboarding de ingreso; los descriptivos enviados a personal activo se ven en su ciclo/expediente y en «Ver como colaborador».
