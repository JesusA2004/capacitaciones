import type { ArticuloGuia } from '../tipos';

/**
 * Proceso 1 — Reclutamiento. Fuente real:
 *  - App\Services\Headcount\HeadcountService::plantillaDePar() (faltantes)
 *  - App\Services\Vacantes\VacanteAutoGenerationService / VacantesListadoService
 *  - App\Services\Reclutamiento\CampanaReclutamientoService + CostoReclutamientoService
 *  - App\Services\Reclutamiento\CandidatoWorkflowService (cada paso)
 *  - App\Enums\EstadoCandidato (estados, fases del tablero)
 *  - App\Services\Reclutamiento\IntervencionCandidatoService
 */
export const ARTICULOS_RECLUTAMIENTO: ArticuloGuia[] = [
    {
        id: 'plantilla-y-vacantes',
        titulo: 'De dónde nace una vacante',
        proceso: 'reclutamiento',
        resumen:
            'Una vacante no se captura: PEOPLE la calcula restando a la plantilla autorizada las personas que hoy ocupan el puesto.',
        queEs: 'Una vacante es una plaza autorizada que hoy no tiene titular. Nadie escribe «vacante abierta»: PEOPLE compara, para cada sucursal y puesto, la plantilla autorizada contra los colaboradores vigentes que ocupan ese puesto, y lo que falta es la vacante real.',
        quien: ['rh'],
        quienDetalle:
            'Gerencia de RH captura la plantilla autorizada (Administración → Sucursales). Todos los demás solo consultan Vacantes: la pantalla es informativa.',
        antes: [
            'La sucursal y el puesto deben existir en Administración.',
            'La plantilla autorizada de ese puesto en esa sucursal debe estar capturada (viene del Excel de headcount de Dirección y Gerencia de RH la mantiene).',
        ],
        pasos: [
            {
                titulo: 'Plantilla autorizada',
                detalle:
                    'Es cuántas plazas de un puesto autorizó Dirección en una sucursal. Ejemplo: Sucursal Tlaxcala, Gestor, plantilla autorizada 5.',
            },
            {
                titulo: 'Ocupados',
                detalle:
                    'PEOPLE cuenta en vivo a los colaboradores vigentes (activos o en incorporación) titulares de ese puesto en esa sucursal. El Gestor Volante cuenta como Gestor. Ejemplo: 4 gestores.',
            },
            {
                titulo: 'Faltantes = vacantes reales',
                detalle:
                    'Plantilla autorizada − ocupados, nunca menos de cero. Ejemplo: 5 − 4 = 1 vacante real de Gestor en Tlaxcala.',
            },
            {
                titulo: 'Qué ve RH en Vacantes',
                detalle:
                    'Arriba, cuántas plazas faltan de cada puesto y en qué sucursales (un clic filtra). Debajo, una fila por vacante: puesto, sucursal, plazas que faltan, días abierta, candidatos que lleva y el contexto «4 de 5 ocupadas».',
                canal: 'web',
            },
        ],
        despues: [
            'Si alguien se da de baja, cambia de puesto o de sucursal, la vacante del lugar que deja se abre sola.',
            'Cuando alguien entra a esa plaza (contratación), la vacante baja o se cierra sola.',
            'Campañas, Candidatos y Contratación usan esta misma cifra: nadie puede publicar, registrar o contratar sobre una plaza que ya no falta.',
        ],
        errores: [
            {
                problema: '«No veo una vacante que debería existir.»',
                solucion:
                    'Revisa la plantilla autorizada de ese puesto en Administración → Sucursales → detalle. Si la plantilla ya está completa, no hay vacante. Si la sucursal no es de tu alcance, tampoco la verás.',
            },
            {
                problema: '«Quiero crear una vacante a mano.»',
                solucion:
                    'No se puede y no hace falta: Vacantes es 100 % derivada. Si Dirección autorizó una plaza nueva, Gerencia de RH la agrega a la plantilla y la vacante aparece.',
            },
            {
                problema:
                    '«Alguien cubre temporalmente la plaza y la vacante sigue abierta.»',
                solucion:
                    'Es correcto: una cobertura temporal no cuenta como ocupado. La plaza sigue sin titular.',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Una sola fuente para todos: Vacantes, Campañas, Candidatos y la contratación consultan el mismo cálculo (plantilla autorizada − ocupados). No existen listas de vacantes paralelas.',
            },
        ],
        registro: [
            'Cada cambio de plantilla autorizada queda en el historial de la sucursal: quién, cuándo, antes → después y motivo.',
        ],
        relacionados: [
            'campanas',
            'registrar-candidato',
            'estructura-y-plantilla',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Vacantes',
                ruta: '/rh/vacantes',
                permisos: ['vacantes.ver'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Sucursales',
                ruta: '/administracion/sucursales',
                permisos: ['sucursales.administrar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Por qué no veo una vacante?',
            '¿Cómo se abre una vacante?',
            '¿Qué es la plantilla autorizada?',
            '¿Cómo creo una vacante?',
        ],
        recorrido: 'crear-campana',
    },
    {
        id: 'campanas',
        titulo: 'Crear una campaña de reclutamiento',
        proceso: 'reclutamiento',
        resumen:
            'Una campaña siempre parte de una vacante real: registra qué se publicó, cuánto costó y qué produjo.',
        queEs: 'La campaña es el registro de una publicación (Meta Ads, Indeed, Computrabajo, LinkedIn, referidos u otros) para cubrir una vacante. Sirve para saber cuánto cuesta conseguir un candidato y una contratación.',
        quien: ['rh'],
        quienDetalle:
            'Reclutamiento / RH (permiso para administrar campañas). Gerencias no capturan campañas.',
        antes: [
            'Debe existir una vacante real con plaza disponible. Si la lista de vacantes del formulario está vacía, no hay plazas faltantes.',
            'Ten a la mano el texto del anuncio, la liga y el reporte o factura del proveedor.',
        ],
        pasos: [
            {
                titulo: 'Abre Campañas',
                detalle: 'Reclutamiento → Campañas → «Nueva campaña».',
                canal: 'web',
            },
            {
                titulo: 'Elige la vacante real',
                detalle:
                    'Por ejemplo «Gestor — Tlaxcala». PEOPLE toma de ella la empresa, la sucursal, el departamento y el puesto: no se vuelven a capturar.',
            },
            {
                titulo: 'Captura la campaña',
                detalle:
                    'Canal, responsable, inicio y fin (opcional), presupuesto, gasto real, sueldo publicado, nombre (opcional), texto del anuncio (copy), liga del anuncio, impresiones y clics (opcionales, del reporte del proveedor), candidatos generados (opcional) y observaciones.',
            },
            {
                titulo: 'Adjunta la evidencia',
                detalle:
                    'Arte y documentos en PDF o imagen: PDF de Meta Ads, factura, captura o reporte del proveedor. Los archivos son privados y se abren desde la propia campaña.',
            },
            {
                titulo: 'Guarda',
                detalle:
                    'La campaña aparece como tarjeta con su embudo y sus costos.',
            },
        ],
        campos: [
            {
                grupo: 'Vacante y canal',
                campos: ['Vacante real', 'Canal', 'Responsable'],
            },
            {
                grupo: 'Fechas y dinero',
                campos: [
                    'Inicio',
                    'Fin (opcional)',
                    'Presupuesto',
                    'Gasto real',
                    'Sueldo publicado',
                ],
            },
            {
                grupo: 'Publicación',
                campos: [
                    'Nombre de la campaña (opcional)',
                    'Texto del anuncio (copy)',
                    'Liga del anuncio',
                    'Impresiones (opcional)',
                    'Clics (opcional)',
                ],
            },
            {
                grupo: 'Evidencia y resultado',
                campos: [
                    'Arte y documentos (PDF o imagen, privados)',
                    'Candidatos generados (opcional)',
                    'Observaciones',
                ],
            },
        ],
        despues: [
            'Cada candidato que registres ligado a esta campaña alimenta su embudo: candidatos → contactados (llegaron a entrevista) → entrevistas → psicométricos → socioeconómicos → contratados.',
            'La tarjeta calcula costo por candidato (gasto ÷ candidatos), costo por colaborador contratado (gasto ÷ contratados), conversión (contratados ÷ candidatos) y días para cubrir.',
            'Ejemplo: $4,500 gastados, 30 candidatos y 2 contratados → $150 por candidato y $2,250 por contratación.',
        ],
        errores: [
            {
                problema: '«El sueldo publicado es distinto al del puesto.»',
                solucion:
                    'Está bien: el sueldo publicado vive solo en la campaña y no modifica el catálogo de Puestos ni el sueldo con que se contrata.',
            },
            {
                problema: '«El embudo de la campaña sale en ceros.»',
                solucion:
                    'Al registrar a cada candidato, elige esa campaña en el campo «Campaña». Si no hay ninguno ligado, PEOPLE atribuye a la campaña los contratados de su misma vacante.',
            },
            {
                problema: '«La vacante no aparece al crear la campaña.»',
                solucion:
                    'Esa plaza ya se cubrió o la plantilla está completa. Revisa Vacantes.',
            },
        ],
        registro: [
            'La campaña y sus adjuntos quedan en la bitácora con quién la creó y modificó.',
        ],
        relacionados: ['plantilla-y-vacantes', 'registrar-candidato'],
        accesos: [
            {
                etiqueta: 'Ir a Campañas',
                ruta: '/rh/campanas',
                permisos: ['reclutamiento.campanas.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo creo una campaña?',
            '¿Cuánto cuesta cada contratación?',
            '¿Dónde subo la factura de Meta?',
            '¿Qué es el costo por candidato?',
        ],
        recorrido: 'crear-campana',
    },
    {
        id: 'registrar-candidato',
        titulo: 'Registrar un candidato',
        proceso: 'reclutamiento',
        resumen:
            'El candidato se registra sobre una vacante real; de ella salen la empresa, la sucursal, el departamento y el puesto.',
        queEs: 'Es dar de alta a una persona interesada en una plaza. Desde ese momento entra al tablero de Candidatos en la fase «Filtro RH».',
        quien: ['rh'],
        quienDetalle: 'Reclutamiento / RH (permiso para crear candidatos).',
        antes: [
            'Ten el nombre y al menos un dato de contacto (teléfono o correo).',
            'Identifica la vacante real a la que aplica y, si llegó por una campaña, cuál.',
        ],
        pasos: [
            {
                titulo: 'Abre Candidatos',
                detalle: 'Reclutamiento → Candidatos → «Nuevo candidato».',
                canal: 'web',
            },
            {
                titulo: 'Datos de contacto',
                detalle: 'Nombre, apellidos, teléfono y correo.',
            },
            {
                titulo: 'Elige la vacante',
                detalle:
                    'Por ejemplo «Gestor — Tlaxcala». PEOPLE muestra el puesto y la sucursal que toma de ella: no se capturan de nuevo.',
            },
            {
                titulo: 'Campaña y fuente',
                detalle:
                    'Elige la campaña de esa vacante por la que llegó (si aplica) y la fuente o canal: pauta Meta, grupos de Facebook, WhatsApp, Indeed, Computrabajo, LinkedIn, referido, orgánico u otro.',
            },
            {
                titulo: 'Observaciones y guardar',
                detalle: 'Agrega lo que el equipo deba saber y guarda.',
            },
            {
                titulo: 'Sube el CV',
                detalle:
                    'En la ficha del candidato, «Subir CV» (PDF o Word). Se descarga desde la ficha sin exponer dónde se guarda.',
            },
        ],
        campos: [
            {
                grupo: 'Contacto',
                campos: ['Nombre', 'Apellidos', 'Teléfono', 'Correo'],
            },
            {
                grupo: 'Plaza',
                campos: [
                    'Vacante (obligatoria si no es espontáneo)',
                    'Campaña de esa vacante (opcional)',
                    'Fuente / canal',
                ],
            },
            {
                grupo: 'Candidato espontáneo',
                campos: ['Puesto de interés', 'Sucursal preferida'],
            },
            {
                grupo: 'Otros',
                campos: ['Observaciones', 'CV (desde la ficha)'],
            },
        ],
        llamados: [
            {
                tono: 'aviso',
                texto: 'Candidato espontáneo: marca «Candidato espontáneo / sin vacante actual» solo si todavía no hay vacante para esa persona. Puede avanzar en el proceso, pero NO se puede contratar hasta vincularlo a una vacante real disponible (desde «Editar datos»).',
            },
        ],
        despues: [
            'El candidato queda en «Filtro RH» y se abre el pendiente de revisión para Reclutamiento.',
            'Su ficha registra «Candidato registrado (fuente…)» como primer evento del historial.',
        ],
        errores: [
            {
                problema:
                    '«Esta vacante ya fue cubierta. Elige otra vacante disponible.»',
                solucion:
                    'Alguien ocupó la última plaza mientras capturabas. Elige otra vacante o registra al candidato como espontáneo.',
            },
            {
                problema: '«La campaña no aparece en la lista.»',
                solucion:
                    'Solo se ofrecen las campañas de la vacante elegida. Elige primero la vacante.',
            },
        ],
        registro: [
            'Ficha del candidato con su historial (cada paso con fecha, responsable y nota) y la bitácora de auditoría.',
        ],
        relacionados: ['etapas-del-candidato', 'filtro-rh', 'campanas'],
        accesos: [
            {
                etiqueta: 'Ir a Candidatos',
                ruta: '/rh/candidatos',
                permisos: ['candidatos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo registro un candidato?',
            '¿Qué es un candidato espontáneo?',
            '¿Dónde subo el CV?',
            '¿Cómo ligo un candidato a una campaña?',
        ],
        recorrido: 'contratar-candidato',
    },
    {
        id: 'etapas-del-candidato',
        titulo: 'Las fases del candidato',
        proceso: 'reclutamiento',
        resumen:
            'El tablero tiene 6 fases y 2 columnas de cierre. Cada avance se hace con su acción en la ficha, nunca arrastrando.',
        queEs: 'Es el recorrido de cada candidato, visible como columnas en Reclutamiento → Candidatos. Las fases agrupan los pasos internos para que RH vea solo lo importante.',
        quien: ['rh', 'gerente'],
        antes: ['El candidato ya está registrado.'],
        pasos: [
            {
                titulo: '1 · Filtro RH',
                detalle:
                    'Reclutamiento revisa si el perfil cubre la vacante (primer contacto).',
                quien: 'rh',
            },
            {
                titulo: '2 · Entrevista',
                detalle:
                    'El gerente de la sucursal entrevista y registra el resultado.',
                quien: 'gerente',
            },
            {
                titulo: '3 · Psicométricos',
                detalle:
                    'Reclutamiento envía el link y registra resultados; el gerente los revisa.',
            },
            {
                titulo: '4 · Socioeconómico',
                detalle:
                    'El gerente visita el domicilio y registra el estudio con evidencias.',
                quien: 'gerente',
            },
            {
                titulo: '5 · Contratación',
                detalle:
                    'Agrupa: validación de referencias → preautorización del gerente → autorización final de RH → QR de contratación → registro y contratos.',
            },
            {
                titulo: '6 · Contratado',
                detalle:
                    'Contratos firmados: la persona ya es colaborador e inicia onboarding.',
            },
            {
                titulo: 'Cerrados: Rechazado · Desistió',
                detalle:
                    '«No viable», «No seleccionado», «No respondió» y «Rechazado por RH» son MOTIVOS dentro de «Rechazado», no fases propias. El motivo sale del catálogo y queda en la ficha.',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Arrastrar una tarjeta solo sirve para cerrar el proceso (a Rechazado o Desistió, con motivo). Para avanzar se usa la acción de la ficha («Revisar perfil», «Registrar entrevista»…): así cada paso deja su registro completo.',
            },
            {
                tono: 'info',
                texto: 'La ficha muestra siempre quién tiene la siguiente acción (Reclutamiento, Gerente de sucursal o RH) y solo ofrece los botones que a ti te tocan.',
            },
        ],
        despues: [
            'Cada avance abre el pendiente del siguiente responsable en «Mis pendientes» (web) y en la app.',
            'Un candidato puede cerrarse desde reclutamiento mientras no haya entrado a contratación.',
        ],
        errores: [
            {
                problema:
                    '«No puedo arrastrar la tarjeta a la siguiente columna.»',
                solucion:
                    'Es a propósito. Abre la ficha y usa la acción del paso.',
            },
            {
                problema:
                    '«El candidato ya está en otra fase. Recarga la ficha.»',
                solucion:
                    'Otra persona avanzó el paso al mismo tiempo. Recarga y revisa el historial.',
            },
        ],
        registro: [
            'Historial del candidato: cada cambio de fase con estado anterior → nuevo, quién y nota.',
            'Fase máxima alcanzada (alimenta el embudo del tablero de Inicio).',
        ],
        relacionados: ['filtro-rh', 'entrevista-gerente', 'autorizacion-rh'],
        accesos: [
            {
                etiqueta: 'Ir a Candidatos',
                ruta: '/rh/candidatos',
                permisos: ['candidatos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cuáles son las fases del candidato?',
            '¿Por qué no puedo arrastrar un candidato?',
            '¿Qué significa no respondió?',
        ],
    },
    {
        id: 'filtro-rh',
        titulo: 'Filtro RH (primer contacto)',
        proceso: 'reclutamiento',
        resumen:
            'Reclutamiento contacta al candidato (normalmente por WhatsApp) y registra si continúa o no.',
        queEs: 'Es la primera revisión del perfil. El contacto se hace fuera del sistema (WhatsApp, llamada); PEOPLE NO envía ese mensaje, solo registra el resultado.',
        quien: ['rh'],
        antes: ['El candidato está en «Filtro RH».'],
        pasos: [
            {
                titulo: 'Contacta al candidato',
                detalle: 'Por WhatsApp o llamada, fuera de PEOPLE.',
            },
            {
                titulo: 'Abre su ficha',
                detalle: 'Candidatos → tarjeta del candidato.',
                canal: 'web_app',
            },
            {
                titulo: '«Revisar perfil»',
                detalle:
                    'Elige el resultado: continúa (viable) o no continúa. Escribe observaciones. La fecha y el responsable se guardan solos.',
            },
            {
                titulo: 'Si no continúa',
                detalle:
                    'El motivo es obligatorio (catálogo de motivos de rechazo) y decides si la persona puede considerarse de nuevo en el futuro.',
            },
        ],
        despues: [
            'Continúa → pasa a «Entrevista» y le llega el pendiente al gerente de la sucursal.',
            'No continúa → queda en «Rechazado» con su motivo.',
        ],
        errores: [
            {
                problema: '«No me deja guardar el rechazo.»',
                solucion: 'Elige un motivo del catálogo: es obligatorio.',
            },
        ],
        registro: [
            'Fecha, responsable, notas y resultado en el historial del candidato.',
        ],
        relacionados: ['entrevista-gerente', 'etapas-del-candidato'],
        accesos: [
            {
                etiqueta: 'Ir a Candidatos',
                ruta: '/rh/candidatos',
                permisos: ['candidatos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo hago el filtro RH?',
            '¿PEOPLE manda WhatsApp?',
            '¿Cómo rechazo a un candidato?',
        ],
    },
    {
        id: 'entrevista-gerente',
        titulo: 'Entrevista con el gerente',
        proceso: 'reclutamiento',
        resumen:
            'El gerente de la sucursal entrevista y registra fecha, observaciones y resultado.',
        queEs: 'Es la entrevista del gerente de la sucursal donde está la vacante. El Gerente Regional NO aprueba cada candidato: esta decisión es del gerente.',
        quien: ['gerente'],
        antes: ['El candidato pasó el Filtro RH y aparece en tus pendientes.'],
        pasos: [
            {
                titulo: 'Abre el pendiente',
                detalle: 'Mis pendientes o Candidatos → ficha del candidato.',
                canal: 'web_app',
            },
            {
                titulo: '«Registrar entrevista»',
                detalle:
                    'Fecha y hora, observaciones y resultado (viable / no viable).',
            },
            {
                titulo: 'Si no es viable',
                detalle: 'Motivo obligatorio del catálogo.',
            },
        ],
        despues: [
            'Viable → pasa a «Psicométricos» y Reclutamiento recibe el pendiente de enviar el link.',
            'No viable → «Rechazado» con motivo.',
        ],
        errores: [
            {
                problema: '«No me aparece el botón Registrar entrevista.»',
                solucion:
                    'Solo lo ve quien tiene permiso de evaluar candidatos y alcance sobre esa sucursal. Revisa que la vacante sea de tu sucursal.',
            },
        ],
        registro: [
            'Entrevista (fecha, entrevistador, observaciones, resultado) en «Evaluaciones registradas» de la ficha.',
        ],
        relacionados: ['psicometricos', 'filtro-rh'],
        accesos: [
            {
                etiqueta: 'Ir a Mis pendientes',
                ruta: '/rh/pendientes',
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo registro una entrevista?',
            '¿El regional tiene que aprobar cada candidato?',
        ],
    },
    {
        id: 'psicometricos',
        titulo: 'Psicométricos',
        proceso: 'reclutamiento',
        resumen:
            'Reclutamiento envía el link, carga los resultados (PDF o imagen) y el gerente los revisa.',
        queEs: 'Son las pruebas psicométricas del candidato. Se aplican en la plataforma del proveedor; PEOPLE guarda el link, el resumen y los reportes, y registra la revisión del gerente.',
        quien: ['rh', 'gerente'],
        quienDetalle:
            'Reclutamiento envía y registra; el gerente revisa (en perfil / fuera de perfil).',
        antes: ['El candidato pasó la entrevista.'],
        pasos: [
            {
                titulo: '«Registrar link de psicométricas»',
                detalle:
                    'Pega la liga que se le envió al candidato (opcional, para dejar constancia).',
                quien: 'rh',
            },
            {
                titulo: '«Registrar resultados»',
                detalle:
                    'Resumen de resultados y hasta 5 reportes en PDF o imagen (JPG, PNG, WEBP).',
                quien: 'rh',
            },
            {
                titulo: '«Revisar psicométricas»',
                detalle:
                    'El gerente decide si están en perfil o fuera de perfil, con observaciones.',
                quien: 'gerente',
            },
            {
                titulo: 'Ver la evidencia sin salir de PEOPLE',
                detalle:
                    'En la ficha, «Evaluaciones registradas» → clic en el archivo: el PDF y las imágenes se abren en un visor dentro de PEOPLE.',
            },
        ],
        despues: [
            'En perfil → «Socioeconómico».',
            'Fuera de perfil → «Rechazado» con motivo.',
        ],
        errores: [
            {
                problema: '«¿Dónde quedó guardado el reporte?»',
                solucion:
                    'En el almacenamiento privado de PEOPLE. Nunca se muestra la ruta del servidor: se abre siempre desde la ficha.',
            },
        ],
        registro: [
            'Link, fecha de envío, resumen, reportes, revisión y responsable.',
        ],
        relacionados: ['socioeconomico', 'entrevista-gerente'],
        accesos: [
            {
                etiqueta: 'Ir a Candidatos',
                ruta: '/rh/candidatos',
                permisos: ['candidatos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo subo los psicométricos?',
            '¿Cómo veo el PDF de psicométricos?',
        ],
    },
    {
        id: 'socioeconomico',
        titulo: 'Estudio socioeconómico',
        proceso: 'reclutamiento',
        resumen:
            'El gerente visita el domicilio y registra el estudio con fotos, PDF o video.',
        queEs: 'Es la visita domiciliaria al candidato. PEOPLE guarda los datos de la visita, un checklist, los riesgos y las evidencias.',
        quien: ['gerente'],
        antes: ['Las psicométricas quedaron en perfil.'],
        pasos: [
            {
                titulo: '«Registrar estudio socioeconómico»',
                detalle:
                    'Fecha de visita, dirección visitada y checklist: vivienda en orden, vive con su familia, años de arraigo y resguardo de motocicleta.',
            },
            {
                titulo: 'Riesgos y observaciones',
                detalle:
                    'Riesgos o inconsistencias detectados y observaciones.',
            },
            {
                titulo: 'Evidencias',
                detalle:
                    'Hasta 12 archivos: fotos (JPG, PNG, WEBP), PDF o video (MP4, MOV).',
            },
            {
                titulo: 'Resultado',
                detalle:
                    'Viable o no viable (si no es viable, motivo obligatorio).',
            },
            {
                titulo: 'Abrir las evidencias',
                detalle:
                    'En la ficha, clic en cada archivo: imagen y PDF se ven en línea; el video se reproduce dentro de PEOPLE.',
            },
        ],
        despues: [
            'Viable → «Validación de referencias».',
            'No viable → «Rechazado».',
        ],
        errores: [
            {
                problema: '«El video no sube.»',
                solucion: 'Usa MP4 o MOV y revisa el tamaño máximo permitido.',
            },
        ],
        registro: [
            'Estudio completo con visitador, checklist, riesgos y evidencias en la ficha.',
        ],
        relacionados: ['referencias', 'psicometricos'],
        accesos: [
            {
                etiqueta: 'Ir a Candidatos',
                ruta: '/rh/candidatos',
                permisos: ['candidatos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo registro el socioeconómico?',
            '¿Puedo subir video del socioeconómico?',
        ],
    },
    {
        id: 'referencias',
        titulo: 'Validación de referencias',
        proceso: 'reclutamiento',
        resumen:
            'Se registra al menos una referencia laboral validada antes de pedir la preautorización del gerente.',
        queEs: 'Es la verificación de referencias laborales del candidato (empresa, contacto, resultado).',
        quien: ['rh', 'gerente'],
        antes: ['El socioeconómico quedó viable.'],
        pasos: [
            {
                titulo: '«Registrar referencia»',
                detalle:
                    'Empresa, contacto, teléfono, relación o puesto, resultado y observaciones. Repite por cada referencia.',
            },
            {
                titulo: '«Concluir validación de referencias»',
                detalle:
                    'Indica si las referencias son favorables. Se exige al menos una registrada.',
            },
        ],
        despues: [
            'Favorables → «Preautorización del gerente» y se abre la aprobación al gerente de la sucursal.',
            'No favorables → «Rechazado» con motivo.',
        ],
        errores: [
            {
                problema:
                    '«Registra al menos una referencia laboral validada antes de continuar.»',
                solucion:
                    'Captura una referencia con «Registrar referencia» y vuelve a concluir.',
            },
        ],
        registro: ['Cada referencia con quién la validó y cuándo.'],
        relacionados: ['autorizacion-rh'],
        accesos: [
            {
                etiqueta: 'Ir a Candidatos',
                ruta: '/rh/candidatos',
                permisos: ['candidatos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: ['¿Cómo valido referencias?'],
    },
    {
        id: 'autorizacion-rh',
        titulo: 'Preautorización del gerente y decisión de RH',
        proceso: 'reclutamiento',
        resumen:
            'El gerente preautoriza y RH da la autorización final. En el caso normal NO interviene el Regional ni Dirección Comercial.',
        queEs: 'Es la decisión de contratar. Quien preautoriza (gerente) no puede dar la autorización final: esa siempre es de RH.',
        quien: ['gerente', 'rh'],
        antes: ['Las referencias se concluyeron favorables.'],
        pasos: [
            {
                titulo: '«Preautorizar contratación»',
                detalle:
                    'El gerente confirma que quiere a la persona (comentario opcional). RH recibe la notificación «Candidato preautorizado».',
                quien: 'gerente',
            },
            {
                titulo: '«Autorizar contratación»',
                detalle:
                    'RH da la autorización final. El candidato queda «Autorizado por RH» y aparece el botón para generar el QR.',
                quien: 'rh',
            },
            {
                titulo: 'O bien «Devolver al gerente»',
                detalle:
                    'Con motivo, si falta algo: regresa a la preautorización.',
                quien: 'rh',
            },
            {
                titulo: 'O bien «Rechazar»',
                detalle:
                    'Con motivo del catálogo: el candidato queda «Rechazado por RH».',
                quien: 'rh',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Caso normal: RH aprueba → el candidato continúa. NO se manda al Regional ni a Dirección Comercial. Ellos solo intervienen si, después de un rechazo de RH, el gerente pide una intervención.',
            },
        ],
        despues: [
            'Autorizado → RH ve «Generar QR de contratación».',
            'Rechazado por RH → el gerente que entrevistó puede aceptar el rechazo o «Solicitar intervención».',
        ],
        notificaciones: [
            '«Candidato preautorizado» a RH (con prioridad alta).',
        ],
        errores: [
            {
                problema: '«No veo el botón Autorizar contratación.»',
                solucion:
                    'La autorización final requiere el permiso de RH y alcance sobre ese candidato. El gerente que preautorizó no puede autorizar.',
            },
        ],
        registro: [
            'Aprobación con el nombre de quien preautorizó y de quien autorizó (queda fijo aunque después cambie el jefe).',
        ],
        relacionados: ['intervencion', 'iniciar-contratacion'],
        accesos: [
            {
                etiqueta: 'Ir a Mis pendientes',
                ruta: '/rh/pendientes',
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Quién autoriza a un candidato?',
            '¿Qué pasa si RH rechaza al candidato?',
            '¿El regional aprueba candidatos?',
        ],
        recorrido: 'contratar-candidato',
    },
    {
        id: 'intervencion',
        titulo: 'Intervención sobre un rechazo de RH',
        proceso: 'reclutamiento',
        resumen:
            'Solo si el gerente la pide: Regional (gestor o volante) o Dirección Comercial (puestos superiores) deciden.',
        queEs: 'Es una excepción. Cuando RH rechaza a un candidato, el gerente que lo entrevistó puede aceptar el rechazo o pedir que se reconsidere. Nunca es automático.',
        quien: ['gerente', 'regional', 'direccion_comercial'],
        quienDetalle:
            'Solicita: el gerente que entrevistó. Decide: el Gerente Regional de la sucursal si el puesto es Gestor o Gestor Volante; Dirección Comercial para cualquier puesto superior.',
        antes: [
            'El candidato está «Rechazado por RH» y no hay otra intervención pendiente.',
        ],
        pasos: [
            {
                titulo: '«Solicitar intervención»',
                detalle:
                    'En la ficha, el gerente explica por qué debería reconsiderarse (motivo obligatorio).',
                quien: 'gerente',
            },
            {
                titulo: 'Decisión',
                detalle:
                    'Quien corresponde ve «Aprobar intervención» o «Confirmar rechazo», con comentario.',
                quien: 'regional',
            },
        ],
        despues: [
            'Aprobada → el candidato pasa a «Autorizado por RH» y puede contratarse (nunca se le inventa una vacante).',
            'Rechazo confirmado → queda rechazado.',
            'La decisión es definitiva y queda en la ficha.',
        ],
        errores: [
            {
                problema: '«No veo Solicitar intervención.»',
                solucion:
                    'Solo aparece al gerente con permiso de evaluar y si no hay ya una intervención pendiente.',
            },
        ],
        registro: [
            'Rechazo de RH, solicitud, decisión, aprobador y comentarios en la sección «Intervención sobre el rechazo de RH».',
        ],
        relacionados: ['autorizacion-rh'],
        accesos: [
            {
                etiqueta: 'Ir a Candidatos',
                ruta: '/rh/candidatos',
                permisos: ['candidatos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo pido una intervención?',
            '¿Quién decide una intervención?',
            '¿Qué hace Dirección Comercial en reclutamiento?',
        ],
    },
];
