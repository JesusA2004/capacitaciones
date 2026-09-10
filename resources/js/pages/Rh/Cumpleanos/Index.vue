<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Cake, Gift, Plus, Sparkles, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import ColaboradorCumpleanosCard from '@/components/Rh/ColaboradorCumpleanosCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { felicitacion, index } from '@/routes/rh/cumpleanos';
import {
    destroy as destroyFrase,
    store as storeFrase,
    update as updateFrase,
} from '@/routes/rh/cumpleanos/frases';

type Colaborador = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    sucursal: string | null;
    departamento: string | null;
    puesto: string | null;
    estatus: string | null;
    dia: number;
    mes: number;
    edad: number | null;
    tiene_foto: boolean;
    foto_url: string | null;
};

type Frase = {
    id: number;
    texto: string;
    categoria: string | null;
    activo: boolean;
    usado_count: number;
};

const MESES = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

const props = defineProps<{
    mes: number;
    filtros: {
        sucursal_id?: string;
        departamento_id?: string;
        estatus?: string;
        busqueda?: string;
    };
    delMes: Colaborador[];
    hoy: Colaborador[];
    proximos7: Colaborador[];
    proximos30: Colaborador[];
    calendario: Record<number, Colaborador[]> | null;
    opciones: {
        sucursales: { id: number; nombre: string }[];
        departamentos: { id: number; nombre: string }[];
        frases: Frase[];
    };
    config: {
        enabled: boolean;
        notify_employee: boolean;
        notify_rh: boolean;
        show_age: boolean;
        show_branch: boolean;
        show_employee_photo: boolean;
        auto_generate_cards: boolean;
    };
    permisos: {
        calendario: boolean;
        descargarImagen: boolean;
        configurar: boolean;
        gestionarFrases: boolean;
        gestionarNotificaciones: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cumpleaños', href: index.url() },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

const { filtros, aplicar, aplicarConDebounce } = useFiltros(index.url(), {
    sucursal_id: props.filtros.sucursal_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    estatus: props.filtros.estatus ?? '',
    busqueda: props.filtros.busqueda ?? '',
});

const mesSeleccionado = ref(String(props.mes));

function cambiarMes() {
    router.get(
        index.url(),
        { ...filtros, mes: mesSeleccionado.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

async function copiarMensaje(colaborador: { nombre: string }) {
    const texto = `¡Feliz cumpleaños, ${colaborador.nombre}! De parte de todo el equipo MR. LANA. 🎉`;

    try {
        await navigator.clipboard.writeText(texto);
        mostrarExito('Mensaje copiado al portapapeles.');
    } catch {
        mostrarError('No se pudo copiar el mensaje.');
    }
}

const diasDelMesConDatos = computed(() => {
    if (!props.calendario) {
        return [];
    }

    return Object.entries(props.calendario)
        .map(([dia, colaboradores]) => ({
            dia: Number(dia),
            colaboradores,
        }))
        .sort((a, b) => a.dia - b.dia);
});

const modalDia = ref<number | null>(null);

// --- Frases ---
const nuevaFrase = useForm({ texto: '', categoria: '' });

function agregarFrase() {
    nuevaFrase.post(storeFrase.url(), {
        preserveScroll: true,
        onSuccess: () => nuevaFrase.reset(),
    });
}

function alternarFrase(frase: Frase) {
    router.put(
        updateFrase.url(frase.id),
        { activo: !frase.activo },
        { preserveScroll: true, preserveState: true },
    );
}

async function eliminarFrase(frase: Frase) {
    if (!confirm(`¿Eliminar la frase "${frase.texto.slice(0, 40)}..."?`)) {
        return;
    }

    router.delete(destroyFrase.url(frase.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <CrudPageHeader
        titulo="Cumpleaños"
        descripcion="Calendario, felicitaciones y tarjetas descargables de los colaboradores."
        :icono="Cake"
    />

    <div v-if="!config.enabled" class="mt-4">
        <Card class="border-dashed">
            <CardContent class="py-6 text-sm text-muted-foreground">
                El módulo de cumpleaños está deshabilitado
                (<code>CUMPLEANOS_ENABLED=false</code>). Los colaboradores no
                recibirán felicitaciones automáticas mientras esté apagado.
            </CardContent>
        </Card>
    </div>

    <template v-else>
        <!-- Cumpleaños de hoy -->
        <Card
            v-if="hoy.length > 0"
            class="mt-4 border-[var(--success)]/40 bg-[var(--success)]/5"
        >
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <Sparkles class="size-5 text-[var(--success)]" />
                    Hoy cumplen años ({{ hoy.length }})
                </CardTitle>
            </CardHeader>
            <CardContent
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="colaborador in hoy"
                    :key="colaborador.id"
                    class="flex items-center justify-between gap-3 rounded-lg border bg-background p-3"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ colaborador.nombre }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{
                                [colaborador.sucursal, colaborador.puesto]
                                    .filter(Boolean)
                                    .join(' · ') || 'Sin sucursal'
                            }}
                        </p>
                    </div>
                    <Link :href="felicitacion.url(colaborador.id)">
                        <Button size="sm" variant="outline">
                            <Gift class="size-4" /> Felicitar
                        </Button>
                    </Link>
                </div>
            </CardContent>
        </Card>

        <!-- Filtros -->
        <Card class="mt-4">
            <CardContent
                class="grid grid-cols-1 gap-3 pt-6 sm:grid-cols-2 lg:grid-cols-5"
            >
                <div class="grid gap-1.5">
                    <Label>Mes</Label>
                    <Select
                        v-model="mesSeleccionado"
                        @update:model-value="cambiarMes"
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(nombre, i) in MESES"
                                :key="i"
                                :value="String(i + 1)"
                            >
                                {{ nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-1.5">
                    <Label>Sucursal</Label>
                    <Select
                        v-model="filtros.sucursal_id"
                        @update:model-value="aplicar"
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Todas" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Todas</SelectItem>
                            <SelectItem
                                v-for="s in opciones.sucursales"
                                :key="s.id"
                                :value="String(s.id)"
                            >
                                {{ s.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-1.5">
                    <Label>Departamento</Label>
                    <Select
                        v-model="filtros.departamento_id"
                        @update:model-value="aplicar"
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Todos" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Todos</SelectItem>
                            <SelectItem
                                v-for="d in opciones.departamentos"
                                :key="d.id"
                                :value="String(d.id)"
                            >
                                {{ d.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-1.5">
                    <Label>Estatus</Label>
                    <Select
                        v-model="filtros.estatus"
                        @update:model-value="aplicar"
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Activos" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Activos</SelectItem>
                            <SelectItem value="activo">Activo</SelectItem>
                            <SelectItem value="en_incorporacion"
                                >En incorporación</SelectItem
                            >
                            <SelectItem value="inactivo">Inactivo</SelectItem>
                            <SelectItem value="suspendido"
                                >Suspendido</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-1.5">
                    <Label>Buscar</Label>
                    <Input
                        v-model="filtros.busqueda"
                        placeholder="Nombre o número de empleado"
                        @input="aplicarConDebounce()"
                    />
                </div>
            </CardContent>
        </Card>

        <Tabs default-value="proximos7" class="mt-4">
            <TabsList>
                <TabsTrigger value="proximos7"
                    >Próximos 7 días ({{ proximos7.length }})</TabsTrigger
                >
                <TabsTrigger value="proximos30"
                    >Próximos 30 días ({{ proximos30.length }})</TabsTrigger
                >
                <TabsTrigger value="delMes"
                    >{{ MESES[mes - 1] }} ({{ delMes.length }})</TabsTrigger
                >
                <TabsTrigger v-if="permisos.calendario" value="calendario"
                    >Calendario</TabsTrigger
                >
                <TabsTrigger v-if="permisos.gestionarFrases" value="frases"
                    >Frases</TabsTrigger
                >
            </TabsList>

            <TabsContent value="proximos7">
                <div
                    v-if="proximos7.length === 0"
                    class="mt-4 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    No hay cumpleaños en los próximos 7 días.
                </div>
                <div
                    v-else
                    class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <ColaboradorCumpleanosCard
                        v-for="colaborador in proximos7"
                        :key="colaborador.id"
                        :colaborador="colaborador"
                        :puede-descargar="permisos.descargarImagen"
                        @copiar="copiarMensaje"
                    />
                </div>
            </TabsContent>

            <TabsContent value="proximos30">
                <div
                    v-if="proximos30.length === 0"
                    class="mt-4 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    No hay cumpleaños en los próximos 30 días.
                </div>
                <div
                    v-else
                    class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <ColaboradorCumpleanosCard
                        v-for="colaborador in proximos30"
                        :key="colaborador.id"
                        :colaborador="colaborador"
                        :puede-descargar="permisos.descargarImagen"
                        @copiar="copiarMensaje"
                    />
                </div>
            </TabsContent>

            <TabsContent value="delMes">
                <div
                    v-if="delMes.length === 0"
                    class="mt-4 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    Nadie cumple años en {{ MESES[mes - 1] }} con los filtros
                    actuales.
                </div>
                <div
                    v-else
                    class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <ColaboradorCumpleanosCard
                        v-for="colaborador in delMes"
                        :key="colaborador.id"
                        :colaborador="colaborador"
                        :puede-descargar="permisos.descargarImagen"
                        @copiar="copiarMensaje"
                    />
                </div>
            </TabsContent>

            <TabsContent v-if="permisos.calendario" value="calendario">
                <div
                    v-if="diasDelMesConDatos.length === 0"
                    class="mt-4 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    Sin cumpleaños que mostrar en el calendario de
                    {{ MESES[mes - 1] }}.
                </div>

                <!-- Escritorio: grid de días -->
                <div v-else class="mt-4 hidden grid-cols-7 gap-2 sm:grid">
                    <div
                        v-for="dia in 31"
                        :key="dia"
                        class="min-h-20 rounded-lg border p-2 text-xs"
                        :class="
                            calendario?.[dia]?.length
                                ? 'border-primary/30 bg-primary/5'
                                : 'border-transparent'
                        "
                    >
                        <p class="mb-1 font-medium text-muted-foreground">
                            {{ dia }}
                        </p>
                        <template v-if="calendario?.[dia]?.length">
                            <p
                                v-for="c in calendario[dia].slice(0, 2)"
                                :key="c.id"
                                class="truncate"
                            >
                                {{ c.nombre }}
                            </p>
                            <button
                                v-if="calendario[dia].length > 2"
                                type="button"
                                class="text-primary underline underline-offset-2"
                                @click="modalDia = dia"
                            >
                                +{{ calendario[dia].length - 2 }} más
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Móvil: lista agrupada por día -->
                <div class="mt-4 flex flex-col gap-3 sm:hidden">
                    <div
                        v-for="grupo in diasDelMesConDatos"
                        :key="grupo.dia"
                        class="rounded-lg border p-3"
                    >
                        <p class="mb-2 text-sm font-semibold">
                            {{ grupo.dia }} de {{ MESES[mes - 1] }}
                        </p>
                        <div class="flex flex-col gap-2">
                            <ColaboradorCumpleanosCard
                                v-for="colaborador in grupo.colaboradores"
                                :key="colaborador.id"
                                :colaborador="colaborador"
                                :puede-descargar="permisos.descargarImagen"
                                compacto
                                @copiar="copiarMensaje"
                            />
                        </div>
                    </div>
                </div>

                <!-- Modal "+N más" del grid de escritorio -->
                <div
                    v-if="modalDia !== null"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    @click.self="modalDia = null"
                >
                    <Card class="max-h-[80vh] w-full max-w-md overflow-y-auto">
                        <CardHeader>
                            <CardTitle
                                >{{ modalDia }} de
                                {{ MESES[mes - 1] }}</CardTitle
                            >
                        </CardHeader>
                        <CardContent class="flex flex-col gap-2">
                            <ColaboradorCumpleanosCard
                                v-for="colaborador in calendario?.[modalDia] ??
                                []"
                                :key="colaborador.id"
                                :colaborador="colaborador"
                                :puede-descargar="permisos.descargarImagen"
                                compacto
                                @copiar="copiarMensaje"
                            />
                            <Button
                                variant="outline"
                                class="mt-2"
                                @click="modalDia = null"
                                >Cerrar</Button
                            >
                        </CardContent>
                    </Card>
                </div>
            </TabsContent>

            <TabsContent v-if="permisos.gestionarFrases" value="frases">
                <Card class="mt-4">
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Agregar frase de felicitación</CardTitle
                        >
                        <CardDescription
                            >Las frases activas rotan automáticamente para no
                            repetir siempre la misma.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3 sm:flex-row">
                        <Input
                            v-model="nuevaFrase.texto"
                            placeholder="Escribe una nueva frase..."
                            class="flex-1"
                        />
                        <Button
                            :disabled="
                                nuevaFrase.processing || !nuevaFrase.texto
                            "
                            @click="agregarFrase"
                        >
                            <Spinner v-if="nuevaFrase.processing" />
                            <Plus v-else class="size-4" />
                            Agregar
                        </Button>
                    </CardContent>
                </Card>

                <div class="mt-4 flex flex-col gap-2">
                    <div
                        v-for="frase in opciones.frases"
                        :key="frase.id"
                        class="flex items-center justify-between gap-3 rounded-lg border p-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm">{{ frase.texto }}</p>
                            <p class="text-xs text-muted-foreground">
                                Usada {{ frase.usado_count }} veces
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Badge
                                :variant="frase.activo ? 'default' : 'outline'"
                                class="cursor-pointer"
                                @click="alternarFrase(frase)"
                            >
                                {{ frase.activo ? 'Activa' : 'Inactiva' }}
                            </Badge>
                            <Button
                                size="icon"
                                variant="ghost"
                                @click="eliminarFrase(frase)"
                            >
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </div>
                    </div>
                </div>
            </TabsContent>
        </Tabs>
    </template>
</template>
