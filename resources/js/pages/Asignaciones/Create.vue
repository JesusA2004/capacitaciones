<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import DestinoSelector from '@/components/Asignaciones/DestinoSelector.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { postJson } from '@/lib/http';
import { dashboard } from '@/routes';
import {
    index,
    previsualizar as previsualizarRoute,
    store,
} from '@/routes/asignaciones';
import type {
    CursoRequisitoItem,
    DestinoAsignacionForm,
    OpcionRol,
    OpcionSimple,
    PrevisualizacionAsignacion,
    ResponsableOpcion,
} from '@/types';

defineProps<{
    cursosDisponibles: CursoRequisitoItem[];
    sucursalesDisponibles: OpcionSimple[];
    departamentosDisponibles: OpcionSimple[];
    puestosDisponibles: OpcionSimple[];
    rolesDisponibles: OpcionRol[];
    usuariosDisponibles: ResponsableOpcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Asignaciones', href: index.url() },
            { title: 'Nueva asignación', href: '#' },
        ],
    },
});

const { confirmarAsignacionMasiva, mostrarExito, mostrarError } = useAlertas();

const form = useForm({
    nombre: '',
    curso_id: '',
    fecha_inicio: '',
    fecha_limite: '',
    obligatoria: true,
    destinos: [{ tipo: 'todos', id: null }] as DestinoAsignacionForm[],
});

const previsualizacion = ref<PrevisualizacionAsignacion | null>(null);
const previsualizando = ref(false);

function agregarDestino() {
    form.destinos.push({ tipo: 'sucursal', id: null });
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

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Nueva asignación"
            description="Asigna un curso a uno o varios colaboradores"
        />

        <form class="grid max-w-2xl gap-6" @submit.prevent="enviar">
            <div class="grid gap-2">
                <Label for="nombre">Nombre de la asignación</Label>
                <Input
                    id="nombre"
                    v-model="form.nombre"
                    placeholder="p. ej. Inducción nuevos ingresos 2026"
                />
                <InputError :message="form.errors.nombre" />
            </div>

            <div class="grid gap-2">
                <Label>Curso</Label>
                <Select v-model="form.curso_id">
                    <SelectTrigger class="w-full"
                        ><SelectValue placeholder="Selecciona un curso"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="curso in cursosDisponibles"
                            :key="curso.id"
                            :value="String(curso.id)"
                        >
                            {{ curso.titulo }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.curso_id" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="fecha_inicio">Fecha de inicio</Label>
                    <Input
                        id="fecha_inicio"
                        v-model="form.fecha_inicio"
                        type="date"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="fecha_limite">Fecha límite</Label>
                    <Input
                        id="fecha_limite"
                        v-model="form.fecha_limite"
                        type="date"
                    />
                    <InputError :message="form.errors.fecha_limite" />
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="form.obligatoria"
                    @update:model-value="(v) => (form.obligatoria = !!v)"
                />
                Asignación obligatoria
            </label>

            <div class="grid gap-2">
                <Label>Destinatarios</Label>
                <div class="flex flex-col gap-2">
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
                </div>
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
            </div>

            <div
                v-if="previsualizacion"
                class="rounded-md border bg-muted/30 p-4 text-sm"
            >
                <p class="font-medium">
                    Esta asignación afectará a
                    {{ previsualizacion.total }} colaborador(es).
                </p>
                <p
                    v-if="previsualizacion.posibles_duplicados > 0"
                    class="text-muted-foreground"
                >
                    {{ previsualizacion.posibles_duplicados }} coincidencia(s)
                    entre destinatarios (ya deduplicadas).
                </p>
                <ul
                    class="mt-2 max-h-32 list-disc overflow-y-auto pl-4 text-muted-foreground"
                >
                    <li
                        v-for="usuario in previsualizacion.muestra"
                        :key="usuario.id"
                    >
                        {{ usuario.nombre }} — {{ usuario.email }}
                    </li>
                </ul>
            </div>

            <div class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="previsualizando"
                    @click="previsualizar"
                >
                    <Spinner v-if="previsualizando" />
                    Ver vista previa
                </Button>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Crear asignación
                </Button>
            </div>
        </form>
    </div>
</template>
