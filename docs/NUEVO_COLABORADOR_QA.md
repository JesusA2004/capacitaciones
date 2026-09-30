# QA — recorrido completo de un colaborador nuevo

De una vacante a una persona con expediente y sesión real en la app móvil, paso por
paso, indicando en cada uno si es web o móvil.

## 0. Punto de partida: la vacante

La vacante **no se crea a mano** salvo un caso excepcional — nace automáticamente
cuando corresponde según el headcount (baja de un colaborador, puesto autorizado sin
ocupar, apertura aprobada). Ver `docs/HEADCOUNT_Y_VACANTES.md`.

- [ ] **Web** → Operación RH → **Vacantes** (`/rh/vacantes`) → confirma que existe (o
      provoca que aparezca) una vacante abierta para el puesto/sucursal de prueba —
      por ejemplo, dando de baja a un colaborador de prueba en ese puesto y
      confirmando que `VacanteAutoGenerationService` abre la vacante de reemplazo
      automáticamente si el headcount la sigue requiriendo.
- [ ] La vacante ya trae **puesto, sucursal, empresa y departamento** resueltos desde
      el modelo — no hay que volver a teclear esos datos en ningún paso posterior.

## 1. Candidato

- [ ] **Web** → **Candidatos** (`/rh/candidatos`) → **Nuevo candidato**.
- [ ] Selecciona la **vacante** de la sección 0 en "Vacante relacionada" — el campo
      "Puesto objetivo" deja de pedirse a mano y muestra, de solo lectura, el puesto
      que ya trae la vacante (ver `Candidato::booted()` — el puesto objetivo se deriva
      siempre de la vacante, nunca se captura por separado mientras haya una).
- [ ] Completa nombre y datos de contacto → Guardar.
- [ ] Confirma en la lista que el candidato aparece con la vacante y el puesto
      correctos.

## 2. Mover el pipeline

- [ ] Mueve al candidato por el tablero (`recibidos` → `preseleccion` → ... según el
      pipeline real, ver `App\Enums\EstadoCandidato`) hasta `listo_para_contratacion`.
- [ ] Confirma que los permisos de aprobación/rechazo se respetan según el rol de la
      cuenta usada (un `rh_auxiliar` no debe poder aprobar/rechazar, solo mover
      estados rutinarios).

## 3. Invitación / QR de incorporación

- [ ] **Web** → **Invitaciones QR** (`/rh/incorporacion/invitaciones`) → **Nueva
      invitación** → selecciona el candidato.
- [ ] Genera el QR (`{invitacion}/qr`) — puede escanearse o abrirse el enlace
      directamente.

## 4. Alta digital (candidato/futuro colaborador)

- [ ] Abre el enlace/QR de la invitación (puede ser desde el navegador del teléfono,
      no necesita la app instalada todavía en este paso).
- [ ] Completa el formulario de alta: datos personales, documentos requeridos
      (identificación, comprobante de domicilio, etc.).
- [ ] **Web** → RH revisa el alta digital (`/rh/altas`) → **Aprobar**.

## 5. Se crea el colaborador y su cuenta

- [ ] Al aprobar el alta, confirma que:
  - se crea el `Colaborador` con `estatus = en_incorporacion` (o el que corresponda),
    puesto/sucursal ya heredados de la vacante/candidato;
  - se registra el `MovimientoLaboral` tipo `alta`;
  - la vacante se cierra/actualiza según corresponda.
- [ ] **Web** → Expedientes → busca al nuevo colaborador → pestaña **Cuenta** → si no
      tiene cuenta de acceso todavía, créala (correo + roles) — se envía correo para
      establecer contraseña.

## 6. Completar el expediente

- [ ] **Web o móvil**: sube los documentos requeridos restantes del expediente
      (los que no se subieron ya durante el alta digital).
- [ ] Confirma que el **porcentaje de expediente** sube conforme se aprueban
      documentos, y que refleja exactamente "aprobados / requeridos" (nunca 100% con
      documentos pendientes de revisión o rechazados) — ver
      `App\Services\Expedientes\ProgresoExpediente`.

## 7. Colaborador activo

- [ ] Cuando el expediente/onboarding esté conforme a la regla del negocio, confirma
      que el `estatus` del colaborador pasa a `activo`.

## 8. Primer login en la app móvil

- [ ] El colaborador recibe su correo para establecer contraseña, la configura.
- [ ] Instala la app (o ya la tenía) → login con ese correo/contraseña.
- [ ] Confirma que aparece la bienvenida (primera vez real en ese dispositivo) y,
      tras completarla, llega a **Mi espacio** (o al selector de modo si además tiene
      permisos operativos).

## 9. Mi espacio funcional

- [ ] El colaborador ve su expediente (con el porcentaje correcto), puede crear una
      solicitud, y su información básica (puesto, sucursal, empresa, fecha de
      ingreso) coincide con lo capturado en el proceso de alta — sin datos huérfanos
      ni "sin definir" que debieron heredarse de la vacante/puesto.

## Qué reportar si algo falla

Indicar en qué paso exacto (0–9) se rompió, si fue web o móvil, y si el problema es de
datos de prueba (falta un campo en el colaborador de prueba) o un problema real del
flujo automatizado (por ejemplo: la vacante no se cerró, el puesto objetivo no se
derivó, el expediente marcó 100% con documentos pendientes).

## Ver también

- `docs/HEADCOUNT_Y_VACANTES.md` — cómo nace una vacante automáticamente.
- `docs/SOLICITUDES_UNIFICADAS.md` — baja de colaborador (el otro extremo del ciclo).
- `docs/API_MOVIL.md` / `docs/BACKEND_MOBILE_V5.md` — contrato real que consume la app
  en cada paso móvil.
