<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarRange,
    Eye,
    GraduationCap,
    Plus,
    Send,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DestinoSelector from '@/components/Asignaciones/DestinoSelector.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { postJson } from '@/lib/http';
import { dashboard } from '@/routes';
import {
    index,
    previsualizar as previsualizarRoute,
    store,
} from '@/routes/asignaciones';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import type {
    CursoRequisitoItem,
    DestinoAsignacionForm,
    OpcionRol,
    OpcionSimple,
    PrevisualizacionAsignacion,
    ResponsableOpcion,
} from '@/types';

const props = defineProps<{
    cursosDisponibles: CursoRequisitoItem[];
    sucursalesDisponibles: OpcionSimple[];
    departamentosDisponibles: OpcionSimple[];
    puestosDisponibles: OpcionSimple[];
    rolesDisponibles: OpcionRol[];
    usuariosDisponibles: ResponsableOpcion[];
    cursoPreseleccionado: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Asignaciones', href: index.url() },
            { title: 'Nueva asignación', href: '#' },
        ],
    },
});

const { confirmarAsignacionMasiva, mostrarExito, mostrarError } = useAlertas();

const cursoInicial = props.cursosDisponibles.find(
    (c) => c.id === props.cursoPreseleccionado,
);

const form = useForm({
    nombre: cursoInicial ? cursoInicial.titulo : '',
    curso_id: cursoInicial ? String(cursoInicial.id) : '',
    fecha_inicio: '',
    fecha_limite: '',
    obligatoria: true,
    destinos: [{ tipo: 'todos', id: null }] as DestinoAsignacionForm[],
});

const opcionesCurso = computed(() =>
    props.cursosDisponibles.map((c) => ({
        value: String(c.id),
        label: c.titulo,
    })),
);

const previsualizacion = ref<PrevisualizacionAsignacion | null>(null);
const previsualizando = ref(false);

function agregarDestino() {
    form.destinos.push({ tipo: 'sucursal', id: null });
    previsualizacion.value = null;
}

function quitarDestino(indice: number) {
    form.destinos.splice(indice, 1);
    previsualizacion.value = null;
}

async function previsualizar() {
    previsualizando.value = true;

    try {
        previsualizacion.value = await postJson<PrevisualizacionAsignacion>(
            previsualizarRoute.url(),
            {
                destinos: form.destinos,
            },
        );
    } catch {
        mostrarError(
            'No fue posible calcular la vista previa de la asignación.',
        );
    } finally {
        previsualizando.value = false;
    }
}

async function enviar() {
    if (!previsualizacion.value) {
        await previsualizar();
    }

    const total = previsualizacion.value?.total ?? 0;
    const confirmado = await confirmarAsignacionMasiva(total);

    if (!confirmado) {
        return;
    }

    form.post(store.url(), {
        onSuccess: () => mostrarExito('La asignación se creó correctamente.'),
    });
}
</script>

<template>
    <Head title="Nueva asignación" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Nueva asignación"
            descripcion="Elige el curso, a quién va dirigido y el plazo. Antes de crearla verás a cuántas personas les llegará."
            :icono="Send"
            detalle
        >
            <Button variant="outline" as-child>
                <Link :href="index()">
                    <ArrowLeft class="size-4" /> Volver a asignaciones
                </Link>
            </Button>
        </CrudPageHeader>

        <form
            class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)]"
            @submit.prevent="enviar"
        >
            <div class="flex flex-col gap-5">
                <!-- 1. Qué curso -->
                <section
                    class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
                >
                    <header class="flex items-center gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-xl bg-salvia text-primary"
                        >
                            <GraduationCap class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold">Curso</h2>
                            <p class="text-xs text-muted-foreground">
                                Qué van a tomar y con qué nombre se verá la
                                asignación.
                            </p>
                        </div>
                    </header>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label>Curso</Label>
                            <SelectSimple
                                v-model="form.curso_id"
                                :opciones="opcionesCurso"
                                placeholder="Selecciona un curso"
                                placeholder-busqueda="Buscar curso…"
                            />
                            <InputError :message="form.errors.curso_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="nombre">Nombre de la asignación</Label>
                            <Input
                                id="nombre"
                                v-model="form.nombre"
                                placeholder="p. ej. Inducción nuevos ingresos 2026"
                            />
                            <InputError :message="form.errors.nombre" />
                        </div>
                    </div>
                </section>

                <!-- 2. Plazo -->
                <section
                    class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
                >
                    <header class="flex items-center gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-xl bg-crema text-bronce"
                        >
                            <CalendarRange class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold">Plazo</h2>
                            <p class="text-xs text-muted-foreground">
                                Opcional. Con fecha límite, la gente verá
                                cuántos días le quedan.
                            </p>
                        </div>
                    </header>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="fecha_inicio">Fecha de inicio</Label>
                            <DatePicker
                                id="fecha_inicio"
                                v-model="form.fecha_inicio"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="fecha_limite">Fecha límite</Label>
                            <DatePicker
                                id="fecha_limite"
                                v-model="form.fecha_limite"
                            />
                            <InputError :message="form.errors.fecha_limite" />
                        </div>
                    </div>
                    <label
                        class="flex w-fit cursor-pointer items-center gap-2 rounded-xl border border-border/60 bg-crema/30 px-3 py-2 text-sm"
                    >
                        <Checkbox
                            :model-value="form.obligatoria"
                            @update:model-value="
                                (v) => (form.obligatoria = !!v)
                            "
                        />
                        Asignación obligatoria
                    </label>
                </section>

                <!-- 3. Destinatarios -->
                <section
                    class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
                >
                    <header class="flex items-center gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-xl bg-menta text-esmeralda"
                        >
                            <Users class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold">Destinatarios</h2>
                            <p class="text-xs text-muted-foreground">
                                Combina sucursales, puestos, roles o personas;
                                si alguien coincide dos veces solo se inscribe
                                una.
                            </p>
                        </div>
                    </header>
                    <ListaAnimada
                        tag="div"
                        :cantidad="form.destinos.length"
                        class="flex flex-col gap-2"
                    >
                        <DestinoSelector
                            v-for="(destino, indice) in form.destinos"
                            :key="indice"
                            :model-value="destino"
                            :sucursales="sucursalesDisponibles"
                            :departamentos="departamentosDisponibles"
                            :puestos="puestosDisponibles"
                            :roles="rolesDisponibles"
                            :usuarios="usuariosDisponibles"
                            @update:model-value="
                                (valor) => {
                                    form.destinos[indice] = valor;
                                    previsualizacion = null;
                                }
                            "
                            @eliminar="quitarDestino(indice)"
                        />
                    </ListaAnimada>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        @click="agregarDestino"
                    >
                        <Plus class="size-4" />
                        Agregar destinatario
                    </Button>
                    <InputError :message="form.errors.destinos" />
                </section>
            </div>

            <!-- Vista previa: a quién le llegará -->
            <aside
                class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm lg:sticky lg:top-20"
            >
                <header class="flex items-center gap-3">
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-info-soft text-primary"
                    >
                        <Eye class="size-5" />
                    </span>
                    <div>
                        <h2 class="font-semibold">A quién le llegará</h2>
                        <p class="text-xs text-muted-foreground">
                            Calculado con los destinatarios actuales.
                        </p>
                    </div>
                </header>

                <CambioSuave>
                    <div
                        v-if="previsualizando"
                        key="cargando"
                        class="flex flex-col gap-2"
                        aria-busy="true"
                    >
                        <Skeleton class="h-16 rounded-xl" />
                        <Skeleton
                            v-for="n in 4"
                            :key="n"
                            class="h-8 rounded-lg"
                        />
                    </div>

                    <div
                        v-else-if="previsualizacion"
                        key="resultado"
                        class="flex flex-col gap-3"
                    >
                        <div
                            class="rounded-xl bg-linear-to-br from-salvia to-menta p-4"
                        >
                            <p
                                class="text-3xl font-bold text-primary tabular-nums"
                            >
                                <NumeroAnimado
                                    :valor="previsualizacion.total"
                                />
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    previsualizacion.total === 1
                                        ? 'persona recibirá el curso'
                                        : 'personas recibirán el curso'
                                }}
                            </p>
                            <p
                                v-if="previsualizacion.posibles_duplicados > 0"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ previsualizacion.posibles_duplicados }}
                                coincidencia(s) entre destinatarios ya
                                descontadas.
                            </p>
                        </div>
                        <ul
                            v-if="previsualizacion.muestra.length"
                            class="flex max-h-80 flex-col gap-1 overflow-y-auto pr-1"
                        >
                            <li
                                v-for="usuario in previsualizacion.muestra"
                                :key="usuario.id"
                                class="rounded-lg px-2 py-1.5 text-sm odd:bg-crema/30"
                            >
                                <p class="truncate font-medium">
                                    {{ usuario.nombre }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ usuario.email }}
                                </p>
                            </li>
                        </ul>
                        <p
                            v-if="
                                previsualizacion.total >
                                previsualizacion.muestra.length
                            "
                            class="text-xs text-muted-foreground"
                        >
                            Mostrando {{ previsualizacion.muestra.length }} de
                            {{ previsualizacion.total }}.
                        </p>
                    </div>

                    <EmptyState
                        v-else
                        key="vacio"
                        :icono="Users"
                        titulo="Aún sin calcular"
                        descripcion="Pulsa «Ver vista previa» para saber cuántas personas recibirán el curso."
                        class="py-6"
                    />
                </CambioSuave>

                <div class="flex flex-col gap-2 border-t border-border/60 pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="previsualizando"
                        @click="previsualizar"
                    >
                        <Spinner v-if="previsualizando" />
                        <Eye v-else class="size-4" />
                        Ver vista previa
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        <Send v-else class="size-4" />
                        Crear asignación
                    </Button>
                </div>
            </aside>
        </form>
    </div>
</template>
