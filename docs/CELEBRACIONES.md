# Celebraciones: Cumpleaños y Aniversarios laborales

Un solo sistema para los dos tipos (`App\Enums\TipoCelebracion`: `cumpleanos`, `aniversario_laboral`). Mismo registro (`App\Models\BirthdayGreeting`, tabla `birthday_greetings`, columna `tipo`), mismas acciones, misma pantalla. Solo cambian los datos, los textos y el diseño de la tarjeta.

## Reglas de negocio

| Regla | Dónde vive |
|---|---|
| "Hoy" se calcula en **America/Mexico_City**, nunca en UTC | `Celebraciones\FechasCelebracion::hoy()` |
| Nacidos / ingresados un **29 de febrero** celebran el **28** en años no bisiestos | `FechasCelebracion::proxima()` / `ocurrenciaEn()` |
| Años exactos de calendario (edad o antigüedad) | `FechasCelebracion::aniosCumplidos()` |
| Aniversario: solo colaboradores **activos** con `fecha_ingreso`; no se celebra el año 0 | `Celebraciones\AniversariosService` |
| Cumpleaños: colaboradores activos con `fecha_nacimiento`, dentro del alcance | `Cumpleanos\CumpleanosService` |
| Alcance organizacional (qué personas ve cada usuario) | `AlcanceOrganizacionalService` |
| Un evento por colaborador + fecha + tipo (índice único); a prueba de carreras | `CelebracionService::asegurarAniversario()` / `BirthdayCardService::generar()` |

## Panel RH (web)

Rutas: `rh.cumpleanos.index` (`/rh/cumpleanos`) y `rh.aniversarios.index` (`/rh/aniversarios`). En el sidebar hay **una sola** entrada, "Celebraciones"; dentro, las pestañas Cumpleaños | Aniversarios (`CelebracionesTabsNav`).

Las dos páginas (`pages/Rh/Cumpleanos/Index.vue`, `pages/Rh/Aniversarios/Index.vue`) son delgadas: solo traducen filtros a su ruta y pasan datos a **`components/Celebraciones/CelebracionesPanel.vue`**, que arma la pantalla completa:

```
[Cumpleaños | Aniversarios]            [Buscar] [Filtros (n)] [Configurar]
Avisos (sin fecha de nacimiento / módulo apagado)
HOY — tarjeta por persona con el flujo completo de la tarjeta
CALENDARIO DEL MES                      PRÓXIMOS (rango configurable)
LISTADO DEL MES
```

### Forma de los datos (idéntica para ambos tipos)

`CelebracionService::filasCumpleanos()` y `filasAniversarios()` devuelven la misma fila (`resources/js/types/celebraciones.ts` → `EventoCelebracion`):

`colaborador_id, nombre (completo), puesto, sucursal, departamento, foto_url, fecha (Y-m-d de la celebración), es_hoy, anios, detalle, celebracion_id, enviada_at, avisada_todos_at`

- `detalle` ya viene redactado: «Cumple 34 años» (solo si `cumpleanos.show_age`) / «6 años en MR. LANA».
- **Nunca** se expone la fecha de nacimiento ni la de ingreso completas: solo la fecha de la celebración en el periodo.
- `foto_url` es la ruta protegida `rh.expedientes.foto` (miniatura normalizada 800×800), nunca `foto_path`.
- Props de ambas páginas: `fechaHoy`, `mes`, `anio`, `hoy` (sin filtros: quien celebra hoy siempre está a la vista), `delMes` (calendario + listado), `proximos` (rango `desde/hasta`, por defecto hoy → +30 días), `filtros`, catálogos acotados por alcance, `permisos`.
- Todo el periodo sale en **una** consulta (y una más para el estado enviado/avisado): el calendario no pide nada por celda.

### Componentes compartidos (`resources/js/components/Celebraciones/`)

| Componente | Qué hace |
|---|---|
| `CelebracionesPanel` | Pantalla completa (tabs + toolbar + hoy + calendario + próximos + listado) |
| `CelebracionesTabsNav` | Subnavegación Cumpleaños/Aniversarios + slot para la toolbar en la misma fila |
| `CelebracionFiltros` | Buscador siempre visible; empresa/sucursal/departamento/colaborador/estatus/rango en `CrudFilterSheet` con contador |
| `TarjetaCelebracionPersona` | Tarjeta del bloque "Hoy" (genérica: nombre, subtítulo, detalle, foto, acciones). Container queries: 2 columnas de botones en angosto, wrap en medio, fila completa en ancho. Nunca recorta botones |
| `CelebracionAcciones` | Flujo de la tarjeta: **Ver tarjeta** (PNG real en diálogo), **Generar** (regenera y refresca la vista previa), **Descargar**, **Copiar** (imagen al portapapeles; si el navegador no puede, copia el texto) |
| `AccionesCelebracionHoy` | **Enviar al colaborador** / **Avisar a todos** (también en `pages/Celebraciones/Show.vue`) |
| `CelebracionCalendario` | Mes con fotos por día (1 en angosto, hasta 3 + «+N» en ancho). Cada día con eventos es un `<button>` con popover accesible por teclado/tap |
| `CelebracionLista` / `CelebracionPersonaFila` | Próximos y listado del mes: filas tipo directorio (foto, nombre completo en hasta 2 líneas, puesto · sucursal, «25 SEP / En 3 días»). Columnas por ancho real del contenedor; «Ver todas» en vez de scroll anidado |
| `CelebracionConfiguracionLayout` | Diseño común de las pantallas de configuración con vista previa real |

Presentación compartida (fechas «25 SEP», «En 3 días», textos por tipo) en `resources/js/lib/celebraciones.ts`. Las fechas relativas se calculan contra `fechaHoy` del backend, no contra el reloj del navegador.

### Responsive

Se validó con capturas reales (Playwright) en 320, 360, 390, 430, 600, 768, 820, 1024, 1280, 1366, 1440 y 1920 px, con 0/1/5 personas hoy, nombres y puestos largos, 5 celebraciones en un mismo día, sin foto y modo oscuro. Sin scroll horizontal en ningún ancho.

- Hoy: máximo 2 columnas; con número impar la última ocupa la fila completa (nunca una tarjeta huérfana).
- Calendario y Próximos van lado a lado solo si el **contenido** mide ≥ 56rem (container query: el sidebar puede estar abierto o no); si no, se apilan.

## Acciones y rutas comunes

Todas bajo `rh.celebraciones.*` con `{colaborador}/{tipo}` y solo para el evento de **hoy** (fuera del día responden un error de validación legible, no un 500):

| Acción | Ruta | Autorización |
|---|---|---|
| Ver tarjeta (inline) / Descargar | `GET rh.celebraciones.tarjeta` (`?ver=1` = inline) | policy `gestionar` |
| Generar (regenerar con datos actuales) | `POST rh.celebraciones.tarjeta.regenerar` | policy `gestionar` |
| Enviar al colaborador | `POST rh.celebraciones.enviar` | policy `enviar` (`celebraciones.enviar`) |
| Avisar a todos (una sola vez por evento) | `POST rh.celebraciones.avisar-todos` | policy `enviar` |
| Abrir/cerrar recepción de mensajes | `POST rh.celebraciones.recepcion` | policy `enviar` |

Cumpleaños conserva además `rh.cumpleanos.felicitacion` (elegir otra frase para la tarjeta del año), enlazado desde el diálogo «Ver tarjeta».

Los mensajes de éxito los manda el backend (`->with('toast', ...)`), puenteados al frontend por `HandleInertiaRequests` y deduplicados en `lib/flashToast.ts`: una acción = un aviso.

## Configuración

| | Cumpleaños (`rh.cumpleanos.configuracion.index`) | Aniversarios (`rh.aniversarios.configuracion`) |
|---|---|---|
| Fondo propio | Sí | Sí |
| Texto | Catálogo de frases que rotan (antes era un diálogo en el calendario) | Mensaje institucional con `{anios}`, `{nombre}`, `{sucursal}` |
| Activo / envío automático | `config/cumpleanos.php` (`.env`) | En la pantalla (`CelebracionConfiguracion`) |
| Vista previa real (PNG, datos de ejemplo con nombre largo) | `rh.cumpleanos.configuracion.vista-previa` | `rh.aniversarios.configuracion.vista-previa` |

## Privacidad de los mensajes

- Un compañero solo ve **su propio** mensaje (puede editarlo/eliminarlo).
- El homenajeado ve **todos** los mensajes que le dejaron.
- RH con permiso de moderación ve todos y puede retirar uno inapropiado (queda en bitácora).

Reglas en `App\Policies\BirthdayGreetingPolicy` (`view`, `escribir`, `verTodosLosMensajes`, `moderar`).

## Colaborador (inicio)

`CelebracionesHoyCard` (dashboards y portal) consulta `celebraciones.hoy`: si no hay nada no ocupa espacio; con una persona muestra "Ver y felicitar"; con varias, lista compacta. La pantalla del evento es `celebraciones.show` (`pages/Celebraciones/Show.vue`).

## API móvil

- `GET api/v1/celebraciones` (activas), `GET api/v1/celebraciones/{id}`, tarjeta, foto y mensajes (`api.v1.celebraciones.*`).
- RH: `GET api/v1/rh/celebraciones/aniversarios` usa el mismo `CelebracionService::filasAniversarios()` que la web (ahora con `detalle`).

## Scheduler

`routes/console.php` (hora de México):

- 06:30 `celebraciones:preparar` — crea los eventos del día y sus tarjetas (idempotente); con envío automático activo, felicita a quien cumple aniversario.
- 07:30 `cumpleanos:recordar-rh` — recordatorio a RH (hoy y próximos 7 días, por alcance).
- 08:00 `cumpleanos:enviar-felicitaciones` — felicitación automática de cumpleaños (idempotente).

Un fallo al notificar nunca deshace la acción principal (se registra con `Log::warning`).

## Pruebas

`tests/Feature/Celebraciones/` (detección, 29/feb, zona horaria, idempotencia, privacidad, envío, avisos, y `PanelCelebracionesTest`: forma de fila, calendario, próximos, fotos, paridad del flujo de tarjeta entre tipos, vista previa de configuración y puente de toasts) y `tests/Feature/Cumpleanos/`.
