# Vacaciones

Rutas `/vacaciones` (self-service del colaborador) y `/rh/vacaciones` (revisión de
RH/gerencia/jefe directo). Tabla `solicitudes_vacaciones`.

## Sin tabla de "saldos"

Igual criterio que expediente/onboarding: no se persiste un saldo, se calcula.
`App\Services\Vacaciones\VacacionesService::saldo()` calcula, a partir de
`fecha_ingreso` del colaborador y la tabla legal configurable
(`config/vacaciones.php`):

- `antiguedad_anios` — años completos de servicio.
- `dias_generados` — según la tabla legal (México/LFT, configurable):
  año 1: 12, año 2: 14, año 3: 16, año 4: 18, año 5: 20, y +2 días por cada bloque de 5
  años adicionales a partir del año 6.
- `dias_usados` / `dias_en_solicitud` — suma de `solicitudes_vacaciones` aprobadas /
  pendientes dentro de la vigencia actual (del último aniversario laboral al
  siguiente).
- `dias_disponibles` = generados − usados − en solicitud.

No hay venta de vacaciones ni integración con nómina (fuera de alcance, ver
`docs/LIMITACIONES.md`).

## Flujo

1. El colaborador solicita vacaciones en `/vacaciones` (fecha inicio/fin, días,
   comentario opcional). El backend rechaza la solicitud si excede
   `dias_disponibles`.
2. RH, gerente de sucursal o jefe directo revisan en `/rh/vacaciones` (alcance según
   `AlcanceOrganizacionalService::limitarUsuariosPorAlcance()` — mismo criterio que el
   resto del proyecto) y aprueban o rechazan (con motivo obligatorio).
3. El colaborador puede cancelar su propia solicitud mientras esté `pendiente`.
4. La pestaña **Vacaciones** del expediente (`docs/EXPEDIENTES_DIGITALES.md`) muestra
   el saldo y las últimas solicitudes de ese colaborador — ya no es un placeholder.

## Permisos

`vacaciones.ver`, `vacaciones.solicitar`, `vacaciones.aprobar`, `vacaciones.rechazar`,
`vacaciones.ajustar` (reservado, sin UI en Fase 1), `vacaciones.reportes` (usado en
`docs/REPORTES_RH.md`). Se corrigió una asimetría del seeder original: `gerente_sucursal`
y `jefe_directo` tenían `vacaciones.aprobar` pero no `vacaciones.rechazar` — ahora
tienen ambos, ya que un revisor que puede aprobar debe poder rechazar.

## Estados

`pendiente`, `aprobada`, `rechazada`, `cancelada` (enum
`App\Enums\EstadoSolicitudVacaciones`).
