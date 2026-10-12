<?php

namespace Database\Seeders;

use App\Enums\TipoModuloOnboarding;
use App\Models\OnboardingModulo;
use Illuminate\Database\Seeder;

/**
 * Módulo institucional de onboarding "Inducción Mr. Lana": su avance NO se
 * aprueba con el quiz genérico (preguntas) — lo aprueba la evaluación
 * oficial de 30 puntos (App\Services\Onboarding\EvaluacionInduccionService,
 * config/induccion_evaluacion.php). Idempotente y seguro en producción: solo
 * crea/actualiza el catálogo, nunca datos de personas.
 *
 * `contenido` guarda los 7 capítulos reales de
 * docs/onboarding/Induccion_Mr_Lana_Rediseno (5).pdf como JSON navegable
 * (ver OnboardingModulo::contenidoEstructurado()) — texto tal cual el
 * documento fuente, sin resumir ni inventar. El capítulo 4 (Organigrama) no
 * trae texto estático de jerarquía: se renderiza con el organigrama
 * CANÓNICO vigente del sistema (ver Fase 3, OrganigramaPersonasService) para
 * que nunca quede desactualizado respecto al PDF.
 */
class InduccionMrLanaModuloSeeder extends Seeder
{
    public function run(): void
    {
        OnboardingModulo::query()->updateOrCreate(['clave' => 'induccion_mr_lana_2026'], [
            'titulo' => 'Inducción Mr. Lana',
            'descripcion' => 'Quiénes somos · Misión, visión y principios · Cultura Mr. Lana · Organigrama · Reglamento Interior de Trabajo · Modelo de negocio · Empresa con factor humano.',
            'tipo' => TipoModuloOnboarding::Institucional,
            'orden' => 1,
            'contenido' => json_encode($this->capitulos(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
            'obligatorio' => true,
            'activo' => true,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function capitulos(): array
    {
        return [
            [
                'clave' => 'quienes_somos',
                'numero' => 1,
                'titulo' => '¿Quiénes somos?',
                'subtitulo' => 'Identidad institucional',
                'bloques' => [
                    ['tipo' => 'parrafo', 'titulo' => 'Impulsamos el crecimiento de las personas', 'texto' => 'MR. LANA nace en el estado de Morelos con la visión de impulsar el crecimiento de las personas. Somos una entidad financiera especializada en nano y micro créditos, que ofrece soluciones rápidas, accesibles y confiables para respaldar negocios, comercios y proyectos productivos.'],
                    ['tipo' => 'datos', 'items' => [
                        ['etiqueta' => 'Origen', 'valor' => 'Morelos, México'],
                        ['etiqueta' => 'Especialidad', 'valor' => 'Nano y micro créditos'],
                        ['etiqueta' => 'Sello', 'valor' => 'Rapidez y confianza'],
                    ]],
                    ['tipo' => 'destacado', 'texto' => 'Nuestro propósito es que cada crédito otorgado se traduzca en desarrollo real para un negocio, una familia y una comunidad.'],
                ],
            ],
            [
                'clave' => 'mision_vision_principios',
                'numero' => 2,
                'titulo' => 'Misión, visión y principios',
                'subtitulo' => 'Nuestro rumbo',
                'bloques' => [
                    ['tipo' => 'parrafo', 'titulo' => 'Misión · ¿Qué hacemos hoy?', 'texto' => 'Satisfacer las necesidades financieras de nuestros clientes a través de préstamos rápidos, accesibles y confiables, impulsando el crecimiento de negocios y de personas con actividad laboral. La rapidez de nuestro servicio es lo que nos distingue de la competencia.'],
                    ['tipo' => 'parrafo', 'titulo' => 'Visión · ¿A dónde vamos?', 'texto' => 'Posicionarnos como una de las principales entidades financieras de nano y micro créditos a nivel nacional e internacional, destacando por nuestra rapidez, innovación, tecnología y compromiso; generando confianza en nuestros clientes y desarrollo constante en nuestros colaboradores.'],
                    ['tipo' => 'parrafo', 'titulo' => 'Nuestros principios', 'texto' => 'En Mr. Lana nos regimos por cuatro principios que orientan cada decisión, cada proceso y cada relación con nuestros clientes y compañeros.'],
                    ['tipo' => 'tarjetas', 'items' => [
                        ['titulo' => 'Innovación', 'texto' => 'Implementamos nuevas tecnologías y nos mantenemos siempre a la vanguardia.', 'nota' => 'En tu día a día: propón mejoras y aprovecha las herramientas digitales.'],
                        ['titulo' => 'Trabajo en equipo', 'texto' => 'Creamos comunidad para alcanzar nuestras metas y las de nuestros clientes.', 'nota' => 'En tu día a día: apoya a tus compañeros y comparte lo que sabes.'],
                        ['titulo' => 'Diligencia', 'texto' => 'Nos esforzamos día con día para atender de manera eficaz cada uno de nuestros procesos.', 'nota' => 'En tu día a día: cumple a tiempo y con calidad cada actividad.'],
                        ['titulo' => 'Integridad', 'texto' => 'Somos coherentes en nuestra forma de ser y de actuar, siempre desde lo correcto y lo justo.', 'nota' => 'En tu día a día: actúa con honestidad, aunque nadie te vea.'],
                    ]],
                ],
            ],
            [
                'clave' => 'cultura_mr_lana',
                'numero' => 3,
                'titulo' => 'Cultura Mr. Lana',
                'subtitulo' => 'Rasgos que nos caracterizan',
                'bloques' => [
                    ['tipo' => 'parrafo', 'texto' => 'Nuestra cultura se sostiene en cuatro rasgos: liderazgo, trabajo inteligente, inteligencia colectiva y documentación de procesos.'],
                    ['tipo' => 'tarjetas', 'titulo' => 'Liderazgo', 'items' => [
                        ['titulo' => 'Liderazgo ascendente', 'texto' => 'Los equipos aportan ideas, soluciones y apoyo a la dirección.'],
                        ['titulo' => 'Liderazgo descendente', 'texto' => 'Los líderes guían, organizan y toman decisiones para el equipo.'],
                        ['titulo' => 'Liderazgo horizontal', 'texto' => 'Todas las áreas colaboran y trabajan juntas para alcanzar objetivos comunes.'],
                    ]],
                    ['tipo' => 'parrafo', 'titulo' => 'Trabajo inteligente · Trabajar mejor, no solo más', 'texto' => 'Para nosotros, el trabajo inteligente consiste en realizar las actividades de manera estratégica, organizada y eficiente, enfocándonos en objetivos claros y priorizando las tareas más importantes para obtener mejores resultados. Implica optimizar el tiempo, aprovechar la tecnología disponible y sostener una planeación adecuada de cada jornada.'],
                    ['tipo' => 'parrafo', 'titulo' => 'Inteligencia colectiva · La fuerza del trabajo en equipo', 'texto' => 'En Mr. Lana creemos en la fuerza del trabajo en equipo. La inteligencia colectiva nos permite unir las ideas, conocimientos y habilidades de cada colaborador para generar mejores soluciones, tomar decisiones más efectivas y alcanzar objetivos comunes, creciendo juntos como empresa. Ninguna idea se pierde: la aportación de cada colaborador suma al resultado de todos.'],
                    ['tipo' => 'parrafo', 'titulo' => 'Documentación de procesos', 'texto' => 'En Mr. Lana trabajamos mediante procesos organizados y estructurados que permiten ejecutar cada actividad de forma eficiente y con calidad. Cada actividad tiene un responsable, una secuencia definida y un estándar que cumplir.'],
                    ['tipo' => 'parrafo', 'titulo' => 'La Carpeta Gerencial', 'texto' => 'Todas las sucursales de Mr. Lana cuentan con una Carpeta Gerencial, en la que se explican a detalle cada uno de nuestros procesos. Su función es apoyar la operación diaria de la sucursal y garantizar que el trabajo se ejecute siempre bajo el mismo estándar: descripción detallada de todos los procesos, documentación actualizada y vigente, políticas institucionales y de crédito, formatos oficiales de uso diario, y las herramientas necesarias para el desempeño de las actividades.'],
                    ['tipo' => 'lista', 'titulo' => 'Contenido de la Carpeta Gerencial (15 secciones)', 'items' => [
                        'Carátula Mr. Lana de Carpeta Gerencial por Sucursal', 'Índice', 'Rentabilidad de Sucursal',
                        'Prospección de clientes (leads)', 'Políticas Generales Mr. Lana', 'Comunicados', 'Minutas',
                        'Auditorías', 'Check list Motocicletas', 'Check list Sucursales', 'Actas administrativas',
                        'Acta de hechos', 'Headcount', 'Capacitación', 'Descriptivos de puesto',
                    ]],
                    ['tipo' => 'destacado', 'texto' => 'Consulta siempre la versión vigente: la Carpeta Gerencial se actualiza conforme cambian las políticas y los formatos institucionales.'],
                ],
            ],
            [
                'clave' => 'organigrama',
                'numero' => 4,
                'titulo' => 'Organigrama de la empresa',
                'subtitulo' => 'Estructura corporativa, comercial y de sucursal',
                'bloques' => [
                    ['tipo' => 'parrafo', 'texto' => 'La estructura de Mr. Lana se organiza en tres niveles: corporativo, Dirección Comercial y sucursal. El organigrama que ves aquí es el VIGENTE del sistema — se actualiza automáticamente cuando Recursos Humanos modifica la estructura de puestos, nunca es una imagen fija.'],
                    ['tipo' => 'organigrama_dinamico', 'vista' => 'corporativo', 'titulo' => 'Organigrama corporativo', 'nota' => 'Las áreas que reportan directamente a la Dirección General.'],
                    ['tipo' => 'organigrama_dinamico', 'vista' => 'comercial', 'titulo' => 'Organigrama · Dirección Comercial', 'nota' => 'Regionales, Mesa de Control, Auditoría y áreas de apoyo.'],
                    ['tipo' => 'organigrama_dinamico', 'vista' => 'sucursal', 'titulo' => 'Organigrama de sucursal', 'nota' => 'La Coordinación mantiene una relación de apoyo administrativo con la Gerencia de Sucursal (no es línea de mando directa).'],
                    ['tipo' => 'tarjetas', 'titulo' => 'Funciones por puesto en la operación local', 'items' => [
                        ['titulo' => 'Gerente de Sucursal', 'texto' => 'Gestionar la operación de la sucursal, lograr los objetivos, supervisar a los Gestores de Crédito, generar y revisar reportes del día, auditar cartera de clientes y medir el desempeño del personal.'],
                        ['titulo' => 'Subgerente de Sucursal', 'texto' => 'Asignar cartera a los gestores, realizar cobranza, entregar resultados y KPI\'s, supervisar a los Gestores de Crédito, auditar cartera de clientes y medir el desempeño del personal.'],
                        ['titulo' => 'Coordinación', 'texto' => 'Revisión de expedientes, dispersión y recepción de efectivo, archivos y cuadre de caja, reportes diarios y nómina de RH, gestión de cobranza y auditoría, y depósito de seguros acumulados.'],
                        ['titulo' => 'Gestores de Crédito (5 a 10)', 'texto' => 'Prospectar clientes, mantener cartera activa, realizar cobranza, verificar mora de la cartera, integrar expediente del cliente y realizar cuadre diario de la cartera.'],
                    ]],
                ],
            ],
            [
                'clave' => 'reglamento_interior',
                'numero' => 5,
                'titulo' => 'Reglamento interior de trabajo',
                'subtitulo' => 'Obligaciones, prohibiciones, cero tolerancia y vestimenta',
                'bloques' => [
                    ['tipo' => 'lista', 'titulo' => 'Obligaciones de los trabajadores (Art. 134 LFT)', 'items' => [
                        'Asistir puntualmente a su jornada de trabajo.', 'Cumplir con las actividades asignadas a su puesto.',
                        'Seguir las instrucciones y políticas de la empresa.', 'Mantener respeto y buena conducta en todo momento.',
                        'Cuidar las herramientas e instalaciones de la empresa.', 'Cumplir las medidas de seguridad e higiene establecidas.',
                        'Guardar confidencialidad de la información institucional.',
                    ]],
                    ['tipo' => 'lista', 'titulo' => 'Obligaciones institucionales adicionales', 'items' => [
                        'Ejecutar el trabajo con la mayor efectividad, eficiencia, esmero y cuidado posible, evitando distracciones ajenas a sus labores.',
                        'Permanecer en su área de trabajo, salvo por motivos derivados de sus funciones laborales.',
                        'Portar el gafete de identificación en un lugar visible durante toda la jornada laboral.',
                        'Ser disciplinado y mantener buena conducta en el desempeño de sus labores.',
                        'Asistir a sus labores aseado y con vestimenta adecuada y limpia.',
                        'Utilizar el uniforme correspondiente cuando así lo disponga la empresa.',
                        'Asistir a los cursos de capacitación y adiestramiento proporcionados por la empresa.',
                    ]],
                    ['tipo' => 'lista', 'titulo' => 'Prohibiciones (Art. 135 LFT)', 'items' => [
                        'Vender cualquier producto o mercancía dentro del lugar de trabajo sin autorización de la empresa.',
                        'Introducir o consumir bebidas alcohólicas, narcóticos o enervantes dentro de las instalaciones.',
                        'Introducir al lugar de trabajo cualquier tipo de arma; si se trata de una herramienta de trabajo, deberá justificarse con el permiso correspondiente.',
                        'Sacar de las instalaciones documentos, útiles, herramientas u objetos, estén o no bajo su resguardo, para fines personales o ajenos a la actividad laboral.',
                        'Ejecutar trabajos ajenos a los propios durante la jornada laboral.',
                        'Comunicarse con palabras inapropiadas, discriminatorias, ofensivas o que falten al respeto.',
                        'Causar daño a los vehículos del personal de la empresa o de terceros.',
                    ]],
                    ['tipo' => 'lista', 'titulo' => 'Cero tolerancia', 'destacado' => true, 'texto' => 'Las siguientes conductas comprometen la confianza de nuestros clientes y la integridad de la institución. No existe tolerancia alguna hacia ellas.', 'items' => [
                        'Malos manejos en los procedimientos de entrega del crédito.', 'Faltar a las políticas de crédito e institucionales.',
                        'Otorgar préstamos propios a clientes.', 'Trabajar para otra empresa mientras se labora en Mr. Lana.',
                        'Robos de cualquier naturaleza.', 'Malversación de fondos. Ejemplo: recibir tres pagos de un cliente, aplicar dos a esa cuenta y uno a otro cliente.',
                        'Tomar garantías y no reportarlas.', 'Realizar cobros adicionales a los clientes.',
                    ]],
                    ['tipo' => 'tarjetas', 'titulo' => 'Código de vestimenta', 'items' => [
                        ['titulo' => 'Hombres', 'texto' => 'Pantalón de mezclilla sin rasgaduras ni estampados · Camisa blanca de manga corta, fajada · Zapato cómodo o bota (no se permiten tenis) · Chaleco institucional · Gafete siempre visible.'],
                        ['titulo' => 'Mujeres', 'texto' => 'Pantalón de mezclilla sin rasgaduras, estampados ni corte ajustado · Camisa blanca de manga corta, fajada · Zapato cómodo o bota (no se permiten tenis) · Chaleco institucional · Gafete siempre visible.'],
                    ]],
                    ['tipo' => 'destacado', 'texto' => 'Nuestra imagen refleja profesionalismo, seguridad y confianza. Viste con orgullo: representas a Mr. Lana.'],
                ],
            ],
            [
                'clave' => 'modelo_negocio',
                'numero' => 6,
                'titulo' => 'Modelo de negocio',
                'subtitulo' => 'Cómo generamos valor',
                'bloques' => [
                    ['tipo' => 'parrafo', 'texto' => 'Nuestro modelo se basa en el otorgamiento de nano y micro créditos a través de una red de sucursales que opera bajo los mismos procesos. La rapidez, la accesibilidad y la confianza son la propuesta de valor que nos distingue.'],
                    ['tipo' => 'tarjetas', 'titulo' => 'Ciclo del crédito: cada etapa alimenta a la siguiente', 'items' => [
                        ['titulo' => '1 · Promoción y prospección', 'texto' => 'Acercamos la oferta de crédito a negocios, comercios y personas con actividad laboral.'],
                        ['titulo' => '2 · Evaluación y autorización', 'texto' => 'Analizamos la solicitud con criterios claros y respuesta ágil.'],
                        ['titulo' => '3 · Entrega del crédito', 'texto' => 'Otorgamos el recurso conforme a las políticas y procesos institucionales.'],
                        ['titulo' => '4 · Seguimiento y cobranza', 'texto' => 'Acompañamos al cliente y damos seguimiento puntual a su compromiso de pago.'],
                    ]],
                    ['tipo' => 'destacado', 'texto' => 'Cada colaborador participa en alguna etapa de este ciclo: tu desempeño impacta directamente en la experiencia del cliente y en el resultado de la sucursal.'],
                ],
            ],
            [
                'clave' => 'factor_humano',
                'numero' => 7,
                'titulo' => 'Empresa con factor humano',
                'subtitulo' => 'Nuestro compromiso contigo',
                'bloques' => [
                    ['tipo' => 'parrafo', 'texto' => 'En Mr. Lana sabemos que detrás de cada resultado hay personas. Por ello nos ocupamos de que nuestros colaboradores se sientan cómodos, reconocidos y parte de la empresa: celebramos festividades, reconocemos el esfuerzo y promovemos la convivencia en todas nuestras sucursales.'],
                    ['tipo' => 'lista', 'items' => ['Celebramos festividades', 'Reconocemos el esfuerzo', 'Promovemos la convivencia']],
                    ['tipo' => 'cierre', 'titulo' => '¡Bienvenido a Mr. Lana!', 'texto' => 'Hoy inicias una nueva etapa dentro de una empresa que cree en el crecimiento de las personas. Tu talento, tu compromiso y tus ideas suman a una comunidad que día con día impulsa negocios, familias y proyectos productivos. Aquí tu desarrollo también es parte de nuestro crecimiento.'],
                ],
            ],
        ];
    }
}
