# Fase 2 — Desempeño y Nine Box (futura)

Solo documentación. La Fase 1 no incluye código, migraciones, modelos,
rutas, menú ni banderas de esta fase (las banderas `desempeno`/`nine_box`
se retiraron al cerrar la Fase 1 porque nada las usaba).

## Alcance previsto

- Seguimiento periódico del colaborador.
- Objetivos por puesto y por persona.
- Competencias.
- Evaluación de desempeño.
- Feedback (jefe ↔ colaborador).
- Nine Box (desempeño vs. potencial).
- Planes de desarrollo.
- Sucesión.

## Qué reutilizará de la Fase 1

| Pieza | Para qué |
|---|---|
| `Colaborador` | Sujeto de la evaluación |
| `Puesto` | Objetivos y competencias por puesto |
| Jefe directo | Derivado del organigrama: quién evalúa |
| Organigrama | Cadena de calibración y sucesión |
| Timeline | Historial de evaluaciones y planes |
| Notificaciones | Recordatorios y resultados (web y push) |
| Permisos (Spatie + Policies) | Quién ve y captura cada evaluación |

Al iniciar la Fase 2 se diseñará su modelo de datos; nada de la Fase 1 debe
cambiar para soportarla.
