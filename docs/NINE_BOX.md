# Nine Box (Seguimiento) — desempeño × potencial

Matriz 3×3 que ubica a cada **gestor de Reportes** por **desempeño** (datos duros de Reportes) y **potencial** (evaluaciones técnicas «Seguimiento 15/30/60…» + calibración del jefe directo o RH), por **periodo** (mes de Reportes o trimestre) y acotada al **alcance** de quien mira.

- Pantalla: **Seguimiento → Nine Box** (`/seguimiento/nine-box`, `resources/js/pages/Seguimiento/NineBox.vue`).
- Bandera: `features.nine_box` (`NINE_BOX_ENABLED`, encendida por defecto desde 2026-11-17) **y** `features.seguimiento`.
- Servicios: `app/Services/NineBox/` — `NineBoxService` (cálculo y alcance), `CalibracionNineBoxService`, `ConfiguracionNineBoxService`.
- Controlador: `App\Http\Controllers\Seguimiento\NineBoxController` (delgado); política `NineBoxCalibracionPolicy`.
- Enums: `CuadranteNineBox` (9 cuadrantes con `etiqueta()`, `descripcion()`, `accionSugerida()`), `NivelNineBox`, `TipoPeriodoNineBox`.

## 1. Periodos

| Tipo | Clave | Meses de Reportes |
|---|---|---|
| Mes | código del mes de Reportes (`M-2026-06`) | 1 (4 semanas, `component_period_ids`) |
| Trimestre | `2026-T2` | los meses **con radiografía** cuyo código cae en el trimestre calendario (abr–jun). Si faltan meses se marca «2 de 3 meses». |

Solo se ofrecen meses con radiografía generada (los KPIs oficiales la necesitan).

## 2. Eje DESEMPEÑO (0-100)

PEOPLE **no recalcula KPIs**: suma lo que Reportes ya calculó (`SeguimientoService::actividadGestoresMes`).

| Indicador | Dato de Reportes | Puntaje |
|---|---|---|
| Colocación | suma de `month_total.placement.amount` de los meses del periodo | con **meta mensual**: `real / (meta × meses) × 100` (tope 100). **Sin meta** (hoy): percentil contra **todos** los gestores de la empresa en el periodo (rango medio; empates comparten). |
| Recuperación | suma de `month_total.recovery.total` | igual que colocación |
| Mora | `vencida / cartera` del **último** mes del periodo (KPIs oficiales `/periods/{mes}/employees`) | ≤ «mora excelente» (5 %) = 100; ≥ «mora crítica» (25 %) = 0; lineal en medio |

Desempeño = promedio ponderado (pesos 40 / 35 / 25 por defecto). Un indicador sin dato (mora sin cartera) no cuenta y su peso se reparte. El percentil se calcula contra la empresa completa **antes** de acotar por alcance: el puntaje de una persona es el mismo la vea quien la vea.

## 3. Eje POTENCIAL (0-100)

| Componente | Fuente | Peso por defecto |
|---|---|---|
| Evaluaciones técnicas | `ResultadosEvaluacionTecnicaService::promedioPorColaborador()` — promedio de los «Seguimiento N» **contestados** en la ventana (6 meses hasta el fin del periodo) | 60 |
| Calibración | la vigente del colaborador (bajo = 25, medio = 60, alto = 90) | 40 |

Si solo existe uno de los dos, cuenta solo ese. **Sin ninguno, la persona no se ubica** («Por calibrar»): nunca se inventa un potencial.

**Calibración** (`nine_box_calibraciones`): la captura el jefe directo, el gerente o RH con `nine_box.calibrar` sobre alguien de su alcance — nivel + comentario obligatorio (≥ 10 caracteres). Nadie se calibra a sí mismo. Cada captura es una fila nueva (historial completo, **nunca se borra ni se edita**) y queda en la bitácora (`nine_box_calibrada`). La vigente para un periodo es la más reciente cuyo periodo **termina antes o igual** que ese periodo: sigue valiendo en los meses siguientes hasta que alguien recalibra (la pantalla dice «De Mayo 2026»).

## 4. Cuadrantes

Cortes por defecto en ambos ejes: **< 40 Bajo · 40–69 Medio · ≥ 70 Alto**.

| Potencial \ Desempeño | Bajo | Medio | Alto |
|---|---|---|---|
| **Alto** | Enigma | Alto potencial | **Estrella** |
| **Medio** | Dilema | Núcleo | Profesional clave |
| **Bajo** | Bajo desempeño | Efectivo | Confiable |

Cada cuadrante trae su descripción y acción sugerida (`CuadranteNineBox::accionSugerida()`); los colores son tokens de la paleta (`resources/js/lib/nineBox.ts`), nunca hex.

Fuera de la matriz:
- **Sin vincular a PEOPLE**: gestor de Reportes sin colaborador vinculado (solo desempeño). Se vincula en «Vínculos con Reportes».
- **Por calibrar**: vinculado, sin Seguimientos ni calibración.

## 5. Configuración (versionada)

`config/nine_box.php` trae los valores por defecto; la vigente es la última fila de `nine_box_configuraciones`. «Configurar» (`nine_box.configurar`) guarda **siempre una versión nueva** con motivo y autor (bitácora `nine_box_configurada`); se valida coherencia (pesos > 0, mora crítica > excelente, corte alto > medio, bajo < medio < alto en calibración).

## 6. Permisos y alcance

Módulo `nine_box` en `config/permisos_modulos.php` (migración `2026_11_17_900100_otorga_permisos_nine_box.php` para bases existentes):

| Rol | ver | alcance | calibrar | configurar |
|---|---|---|---|---|
| super_admin | ✓ | todos | ✓ | ✓ |
| rh_admin | ✓ | todos | ✓ | ✓ |
| direccion, director_comercial | ✓ | todos | — | — |
| gerente_regional | ✓ | región (todas las operativas) | ✓ | — |
| gerente_sucursal | ✓ | sucursal | ✓ | — |
| jefe_directo | ✓ | equipo (sus subordinados) | ✓ | — |

Gestor vinculado: se ubica por la sucursal principal de su colaborador (equipo = `jefe_id`/`gerente_id`). Sin vincular: por la sucursal de su branch y solo con alcance por sucursal/región/todos. El panel de una persona (`/seguimiento/nine-box/personas/{colaborador}`) responde 403 fuera del alcance.

## 7. Pantalla

- Selector Mes/Trimestre (reutiliza `SelectorPeriodo`), filtro buscable de sucursal, búsqueda que atenúa a quien no coincide.
- Cuadrícula con avatares/iniciales en una sola capa: al cambiar de periodo cada persona **se desliza** a su nuevo cuadrante; conteos con `NumeroAnimado`; «+N» cuando una celda se llena. Clic en un cuadrante = filtro + panel con acción sugerida y lista.
- Panel lateral (sheet): puntajes en anillo, desglose del desempeño (método y peso), tendencia semanal, «Seguimientos» técnicos con su calificación, formulario de calibración e historial; accesos a su detalle en Seguimiento y a su expediente.
- Vista de tabla ordenable y **Exportar a Excel** (`/seguimiento/nine-box/exportar`, mismo alcance y filtros).

## 8. Pendientes de negocio

- **Metas por gestor**: hoy no hay metas en PEOPLE; con metas mensuales (o el OKR de Reportes) el desempeño pasa de percentil a cumplimiento real. Se capturan en «Configurar» (una meta general) o, mejor, por puesto/gestor (falta definir).
- **Cortes y pesos** definitivos (40/70 y 40/35/25 – 60/40 son un punto de partida).
- **Efectividad de cobranza** (V4 semanal de Reportes) como indicador adicional de recuperación.
- **Planes de acción** por cuadrante (registro y seguimiento del plan sugerido) y sucesión.
- Vincular a los gestores reales en producción («Vínculos con Reportes»): sin vínculo no hay potencial.
- «Evaluación del desempeño» (bandera `evaluacion_desempeno`, sin pantalla ni menú): propuesta — retirarla y tratar la Nine Box + Seguimientos técnicos como la evaluación del desempeño, o definirla como ciclo semestral de objetivos/competencias que alimente el potencial.
