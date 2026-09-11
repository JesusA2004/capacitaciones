<?php

namespace Database\Seeders;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoAsistencia;
use App\Enums\EstadoCurso;
use App\Enums\EstadoEntregaActividad;
use App\Enums\EstadoIntentoCuestionario;
use App\Enums\EstadoProgreso;
use App\Enums\EstadoSesionEnVivo;
use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Enums\ProveedorSesion;
use App\Enums\TipoEntregaActividad;
use App\Enums\TipoLeccion;
use App\Models\Actividad;
use App\Models\Asignacion;
use App\Models\AsignacionUsuario;
use App\Models\Asistencia;
use App\Models\Cuestionario;
use App\Models\Curso;
use App\Models\Departamento;
use App\Models\EntregaActividad;
use App\Models\InscripcionCurso;
use App\Models\IntentoCuestionario;
use App\Models\Leccion;
use App\Models\ProgresoLeccion;
use App\Models\Puesto;
use App\Models\SesionEnVivo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Solicitudes\BajaColaboradorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de demostración EXCLUSIVOS para entornos de desarrollo: agrega
 * colaboradores, un segundo curso, asignaciones/inscripciones en estados
 * variados, cuestionarios calificados, entregas de actividad, y una sesión
 * en vivo con asistencia — todo lo que necesitan las gráficas nuevas del
 * dashboard (fase de modernización visual) para no verse vacías en local.
 * Es puramente aditivo: no toca ni borra nada de los demás seeders.
 *
 * Contraseña de los colaboradores nuevos, igual que UsuarioDemoSeeder:
 * "Capacitacion2026!". Nunca usar en producción.
 */
class DashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'colaborador9@mrlana.test')->exists()) {
            return;
        }

        $colaboradores = $this->crearColaboradores();
        $responsable = User::where('email', 'admin.capacitacion@mrlana.test')->firstOrFail();
        $instructor = User::where('email', 'instructor@mrlana.test')->first() ?? $responsable;

        $cursoInduccion = Curso::where('titulo', 'Curso de Inducción General')->firstOrFail();
        $cursoAtencion = $this->crearCursoAtencionAlCliente();

        $this->asignarYProgresar($cursoInduccion, $colaboradores, $responsable);
        $this->asignarYProgresar($cursoAtencion, $colaboradores, $responsable);

        $this->sembrarCuestionario($cursoAtencion, $colaboradores);
        $this->sembrarActividad($cursoAtencion, $colaboradores);
        $this->sembrarSesionYAsistencia($cursoAtencion, $colaboradores, $instructor);
        $this->sembrarBajaDemo($responsable);
    }

    /**
     * Sin esto, "bajas" siempre daba 0 en el KPI de rotación del dashboard
     * (docs/REPORTES.md) sin importar el rango de fechas — ninguna baja real
     * existía en los datos demo. Ejecuta el flujo REAL de baja
     * (App\Services\Solicitudes\BajaColaboradorService), no solo inserta un
     * registro: así también sirve para probar el bloqueo de acceso con un
     * colaborador demo real.
     */
    private function sembrarBajaDemo(User $registradoPor): void
    {
        $sucursal = Sucursal::where('clave', 'ATC01')->first();
        $departamento = Departamento::where('nombre', 'Operaciones')->first();
        $puesto = Puesto::where('nombre', 'Gestor fijo')->first();

        $colaborador = User::firstOrCreate(
            ['email' => 'colaborador10@mrlana.test'],
            [
                'name' => 'Pablo',
                'apellidos' => 'Serrano Vega',
                'genero' => Genero::Masculino,
                'password' => Hash::make('Capacitacion2026!'),
                'email_verified_at' => now(),
                'sucursal_principal_id' => $sucursal?->id,
                'departamento_id' => $departamento?->id,
                'puesto_id' => $puesto?->id,
                'fecha_ingreso' => now()->subMonths(4),
                'estatus' => EstadoUsuario::Activo,
                'zona_horaria' => 'America/Mexico_City',
            ],
        );

        if ($colaborador->wasRecentlyCreated) {
            $colaborador->syncRoles(['colaborador']);
            app(MovimientoLaboralService::class)->registrarAlta($colaborador, $registradoPor);
        }

        if ($colaborador->estatus === EstadoUsuario::Activo) {
            app(BajaColaboradorService::class)->ejecutar($colaborador, $registradoPor, 'Renuncia voluntaria (dato de demostración).');
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function crearColaboradores(): Collection
    {
        $passwordDesarrollo = Hash::make('Capacitacion2026!');
        $sucursales = Sucursal::all()->keyBy('clave');
        $departamentos = Departamento::all()->keyBy('nombre');

        // Todo colaborador activo debe tener puesto (sección 5/8 de la
        // reestructuración): un puesto razonable por departamento, ya que
        // estos colaboradores demo existen para poblar capacitación, no
        // para modelar headcount operativo (Gestor fijo/volante se reserva
        // a los colaboradores que sí alimentan la vista de headcount, ver
        // UsuarioDemoSeeder).
        $puestoPorDepartamento = [
            'Recursos Humanos' => Puesto::where('nombre', 'Generalista de RH')->first(),
            'Sistemas' => Puesto::where('nombre', 'Soporte Técnico')->first(),
            'Ventas' => Puesto::where('nombre', 'Ejecutivo de Ventas')->first(),
            'Operaciones' => Puesto::where('nombre', 'Supervisor de Operaciones')->first(),
        ];

        $definiciones = [
            ['email' => 'colaborador3@mrlana.test', 'nombre' => 'Sofía', 'apellidos' => 'Reyes Cano', 'sucursal' => 'IXT01', 'departamento' => 'Recursos Humanos', 'genero' => Genero::Femenino],
            ['email' => 'colaborador4@mrlana.test', 'nombre' => 'Héctor', 'apellidos' => 'Domínguez Ríos', 'sucursal' => 'IXT01', 'departamento' => 'Sistemas', 'genero' => Genero::Masculino],
            ['email' => 'colaborador5@mrlana.test', 'nombre' => 'Valeria', 'apellidos' => 'Cisneros Mora', 'sucursal' => 'CUE01', 'departamento' => 'Ventas', 'genero' => Genero::Femenino],
            ['email' => 'colaborador6@mrlana.test', 'nombre' => 'Iván', 'apellidos' => 'Paredes Luna', 'sucursal' => 'CUE01', 'departamento' => 'Recursos Humanos', 'genero' => Genero::Masculino],
            ['email' => 'colaborador7@mrlana.test', 'nombre' => 'Renata', 'apellidos' => 'Ochoa Vega', 'sucursal' => 'ATC01', 'departamento' => 'Ventas', 'genero' => Genero::Femenino],
            ['email' => 'colaborador8@mrlana.test', 'nombre' => 'Emilio', 'apellidos' => 'Guzmán Solís', 'sucursal' => 'ATC01', 'departamento' => 'Sistemas', 'genero' => Genero::Masculino],
            ['email' => 'colaborador9@mrlana.test', 'nombre' => 'Ximena', 'apellidos' => 'Beltrán Rico', 'sucursal' => 'ATC01', 'departamento' => 'Operaciones', 'genero' => Genero::Femenino],
        ];

        $colaboradores = collect();
        $movimientos = app(MovimientoLaboralService::class);
        $sistema = User::query()->where('email', 'superadmin@mrlana.test')->first();

        foreach ($definiciones as $indice => $definicion) {
            $yaExistia = User::where('email', $definicion['email'])->exists();

            $usuario = User::firstOrCreate(
                ['email' => $definicion['email']],
                [
                    'name' => $definicion['nombre'],
                    'apellidos' => $definicion['apellidos'],
                    'genero' => $definicion['genero'],
                    'password' => $passwordDesarrollo,
                    'email_verified_at' => now(),
                    'sucursal_principal_id' => $sucursales[$definicion['sucursal']]->id,
                    'departamento_id' => $departamentos[$definicion['departamento']]->id,
                    'puesto_id' => $puestoPorDepartamento[$definicion['departamento']]?->id,
                    'fecha_ingreso' => now()->subMonths(1 + ($indice % 24)),
                    'estatus' => EstadoUsuario::Activo,
                    'zona_horaria' => 'America/Mexico_City',
                ],
            );

            if ($usuario->genero === null) {
                $usuario->update(['genero' => $definicion['genero']]);
            }

            $usuario->syncRoles(['colaborador']);
            $colaboradores->push($usuario);

            if (! $yaExistia && $sistema !== null && ! $usuario->movimientosLaborales()->where('tipo_movimiento', 'alta')->exists()) {
                $movimientos->registrarAlta($usuario, $sistema);
            }
        }

        // Los colaboradores ya conocidos (Miguel, Daniela) van primero para
        // que caigan en los primeros patrones de asignarYProgresar()
        // (completada/en_progreso) y su dashboard de ejemplo no se vea vacío.
        return User::whereIn('email', ['colaborador1@mrlana.test', 'colaborador2@mrlana.test'])->get()
            ->merge($colaboradores)
            ->values();
    }

    private function crearCursoAtencionAlCliente(): Curso
    {
        $curso = Curso::firstOrCreate(
            ['titulo' => 'Atención al Cliente y Ventas'],
            [
                'descripcion' => 'Curso práctico sobre atención al cliente, manejo de objeciones y cierre de ventas.',
                'objetivo' => 'Aplicar técnicas de atención al cliente en el trato diario con clientes de Mr. Lana.',
                'duracion_estimada_minutos' => 120,
                'estado' => EstadoCurso::Publicado,
                'calificacion_minima' => 80,
                'intentos_maximos' => 3,
                'requiere_orden' => false,
                'genera_constancia' => true,
                'alcance_global' => true,
                'publicado_en' => now()->subMonths(4),
            ],
        );

        if ($curso->modulos()->exists()) {
            return $curso;
        }

        $modulo = $curso->modulos()->create(['titulo' => 'Fundamentos de atención al cliente', 'orden' => 1]);

        $modulo->lecciones()->create([
            'titulo' => 'Video: los 5 pilares de la atención al cliente',
            'tipo' => TipoLeccion::Video,
            'contenido' => 'Video simulado de la biblioteca multimedia.',
            'obligatoria' => true,
            'orden' => 1,
            'duracion_estimada_minutos' => 12,
        ]);

        $modulo->lecciones()->create([
            'titulo' => 'Cuestionario de atención al cliente',
            'tipo' => TipoLeccion::Cuestionario,
            'obligatoria' => true,
            'orden' => 2,
            'duracion_estimada_minutos' => 10,
        ]);

        $modulo->lecciones()->create([
            'titulo' => 'Actividad: simulación de atención a cliente',
            'tipo' => TipoLeccion::Actividad,
            'obligatoria' => true,
            'orden' => 3,
            'duracion_estimada_minutos' => 20,
        ]);

        return $curso;
    }

    /**
     * Reparte a los colaboradores en los 4 estados obligatorios de una
     * asignación (completada/en_progreso/pendiente/vencida), con su
     * inscripción y avance de lecciones a juego, para que cumplimiento,
     * top-cursos-por-avance, cursos-con-abandono y evolución mensual
     * tengan variedad real que graficar.
     *
     * @param  Collection<int, User>  $colaboradores
     */
    private function asignarYProgresar(Curso $curso, Collection $colaboradores, User $responsable): void
    {
        $asignacion = Asignacion::firstOrCreate(
            ['nombre' => "Asignación demo: {$curso->titulo}", 'asignable_type' => Curso::class, 'asignable_id' => $curso->id],
            ['responsable_id' => $responsable->id, 'obligatoria' => true, 'activa' => true, 'fecha_inicio' => now()->subMonths(6)],
        );

        $lecciones = $curso->modulos()->with('lecciones')->get()->flatMap(fn ($modulo) => $modulo->lecciones);

        foreach ($colaboradores->values() as $indice => $colaborador) {
            $patron = $indice % 4;

            [$estadoAsignacion, $estadoInscripcion, $fechaLimite, $completadoEn] = match ($patron) {
                0 => [EstadoAsignacion::Completada, EstadoProgreso::Completada, now()->subMonths(2), now()->subMonths($indice % 6)],
                1 => [EstadoAsignacion::EnProgreso, EstadoProgreso::EnProgreso, now()->addWeeks(2), null],
                2 => [EstadoAsignacion::Pendiente, EstadoProgreso::Pendiente, now()->addMonth(), null],
                default => [EstadoAsignacion::Vencida, EstadoProgreso::EnProgreso, now()->subWeek(), null],
            };

            $asignacionUsuario = AsignacionUsuario::firstOrCreate(
                ['asignacion_id' => $asignacion->id, 'user_id' => $colaborador->id],
                ['estado' => $estadoAsignacion, 'fecha_limite' => $fechaLimite, 'completado_en' => $completadoEn],
            );

            InscripcionCurso::firstOrCreate(
                ['user_id' => $colaborador->id, 'curso_id' => $curso->id],
                [
                    'asignacion_usuario_id' => $asignacionUsuario->id,
                    'estado' => $estadoInscripcion,
                    'iniciado_en' => $patron === 2 ? null : now()->subMonths(2),
                    'completado_en' => $completadoEn,
                    'calificacion_final' => $estadoInscripcion === EstadoProgreso::Completada ? 80 + ($indice % 21) : null,
                ],
            );

            $leccionesACompletar = match ($patron) {
                0 => $lecciones->count(),
                1 => (int) ceil($lecciones->count() / 2),
                default => 0,
            };

            foreach ($lecciones->take($leccionesACompletar) as $leccion) {
                ProgresoLeccion::firstOrCreate(
                    ['user_id' => $colaborador->id, 'leccion_id' => $leccion->id],
                    ['estado' => EstadoProgreso::Completada, 'iniciado_en' => now()->subMonths(2), 'completado_en' => $completadoEn ?? now()->subWeek()],
                );
            }
        }
    }

    /**
     * @param  Collection<int, User>  $colaboradores
     */
    private function sembrarCuestionario(Curso $curso, Collection $colaboradores): void
    {
        $leccion = $this->buscarLeccionPorTipo($curso, TipoLeccion::Cuestionario);

        if ($leccion === null) {
            return;
        }

        $cuestionario = Cuestionario::firstOrCreate(
            ['leccion_id' => $leccion->id],
            ['titulo' => $leccion->titulo, 'calificacion_minima' => 80],
        );

        foreach ($colaboradores->take(6)->values() as $indice => $colaborador) {
            $aprobado = $indice % 3 !== 0;

            IntentoCuestionario::firstOrCreate(
                ['cuestionario_id' => $cuestionario->id, 'user_id' => $colaborador->id, 'numero_intento' => 1],
                [
                    'estado' => EstadoIntentoCuestionario::Calificado,
                    'iniciado_en' => now()->subWeeks(2),
                    'enviado_en' => now()->subWeeks(2),
                    'calificado_en' => now()->subWeeks(2),
                    'calificacion' => $aprobado ? 80 + ($indice % 21) : 40 + ($indice % 40),
                    'aprobado' => $aprobado,
                ],
            );
        }
    }

    /**
     * @param  Collection<int, User>  $colaboradores
     */
    private function sembrarActividad(Curso $curso, Collection $colaboradores): void
    {
        $leccion = $this->buscarLeccionPorTipo($curso, TipoLeccion::Actividad);

        if ($leccion === null) {
            return;
        }

        $actividad = Actividad::firstOrCreate(
            ['leccion_id' => $leccion->id],
            ['titulo' => $leccion->titulo, 'instrucciones' => 'Describe cómo atenderías a un cliente insatisfecho.', 'tipo_entrega' => TipoEntregaActividad::Texto, 'calificacion_minima' => 80],
        );

        // Antigüedades variadas: reciente, atrasada y crítica (buckets del panorama general del dashboard).
        $antiguedades = [now()->subDay(), now()->subDays(4), now()->subDays(10)];

        foreach ($colaboradores->take(3)->values() as $indice => $colaborador) {
            EntregaActividad::firstOrCreate(
                ['actividad_id' => $actividad->id, 'user_id' => $colaborador->id, 'version' => 1],
                ['estado' => EstadoEntregaActividad::Entregada, 'contenido_texto' => 'El cliente llegó molesto por un retraso en su pedido. Lo saludé con calma, reconocí la falla, le ofrecí una disculpa clara y le expliqué los siguientes pasos para resolverlo, dándole seguimiento hasta confirmar que quedó satisfecho.', 'entregado_en' => $antiguedades[$indice]],
            );
        }
    }

    /**
     * @param  Collection<int, User>  $colaboradores
     */
    private function sembrarSesionYAsistencia(Curso $curso, Collection $colaboradores, User $creador): void
    {
        $modulo = $curso->modulos()->first();

        if ($modulo === null) {
            return;
        }

        $leccionSesion = Leccion::firstOrCreate(
            ['curso_modulo_id' => $modulo->id, 'titulo' => 'Sesión en vivo: resolución de dudas'],
            ['tipo' => TipoLeccion::SesionEnVivo, 'obligatoria' => false, 'orden' => 99, 'duracion_estimada_minutos' => 45],
        );

        $sesion = SesionEnVivo::firstOrCreate(
            ['leccion_id' => $leccionSesion->id],
            [
                'titulo' => 'Resolución de dudas: Atención al Cliente',
                'proveedor' => ProveedorSesion::Manual,
                'fecha_inicio' => now()->subDays(5),
                'duracion_minutos' => 45,
                'enlace_reunion' => 'https://meet.example.test/demo-atencion-cliente',
                'estado' => EstadoSesionEnVivo::Finalizada,
                'creado_por' => $creador->id,
            ],
        );

        $estados = [EstadoAsistencia::Presente, EstadoAsistencia::Presente, EstadoAsistencia::AsistenciaParcial, EstadoAsistencia::Ausente, EstadoAsistencia::PendienteRevision];

        foreach ($colaboradores->values() as $indice => $colaborador) {
            $estado = $estados[$indice % count($estados)];

            Asistencia::firstOrCreate(
                ['sesion_en_vivo_id' => $sesion->id, 'user_id' => $colaborador->id],
                [
                    'estado' => $estado,
                    'unido_en' => $estado !== EstadoAsistencia::Ausente ? $sesion->fecha_inicio : null,
                    'minutos_totales' => $estado === EstadoAsistencia::Presente ? 45 : ($estado === EstadoAsistencia::AsistenciaParcial ? 20 : null),
                    'porcentaje_sesion' => $estado === EstadoAsistencia::Presente ? 100 : ($estado === EstadoAsistencia::AsistenciaParcial ? 44 : 0),
                ],
            );
        }
    }

    private function buscarLeccionPorTipo(Curso $curso, TipoLeccion $tipo): ?Leccion
    {
        return $curso->modulos()->with('lecciones')->get()
            ->flatMap(fn ($modulo) => $modulo->lecciones)
            ->firstWhere('tipo', $tipo);
    }
}
