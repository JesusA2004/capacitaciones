<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BellRing, CircleAlert, CircleCheck, TriangleAlert } from '@lucide/vue';
import { reactive } from 'vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';

/**
 * «Notificaciones del sistema»: consulta administrativa de SOLO LECTURA
 * (permiso notificaciones.ver_todas). Nunca marca como leída la
 * notificación de otra persona; la campana de cada quien sigue mostrando
 * solo las suyas. Arriba, el estado de colas, Reverb y Expo.
 */
type Tarjeta = {
    clave: string;
    titulo: string;
    estado: 'ok' | 'alerta' | 'error' | string;
    detalle: string;
};

type Fila = {
    id: string;
    destinatario: { id: number; nombre: string } | null;
    titulo: string;
    mensaje: string;
    tipo: string | null;
    categoria: string | null;
    modulo: string | null;
    leida: boolean;
    creada_en: string | null;
    push_dispositivos: number | null;
    origen: string | null;
};

type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    tarjetas: Tarjeta[];
    notificaciones: Paginado<Fila>;
    filtros: Record<string, string | null>;
    opciones: { tipos: string[]; modulos: string[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            {
                title: 'Notificaciones del sistema',
                href: '/administracion/notificaciones',
            },
        ],
    },
});

const filtros = reactive({
    usuario: props.filtros.usuario ?? '',
    tipo: props.filtros.tipo ?? '',
    modulo: props.filtros.modulo ?? '',
    estado: props.filtros.estado ?? '',
    entrega: props.filtros.entrega ?? '',
    desde: props.filtros.desde ?? '',
    hasta: props.filtros.hasta ?? '',
});

const ESTILO_TARJETA: Record<string, string> = {
    ok: 'bg-success-soft text-success',
    alerta: 'bg-warning-soft text-warning',
    error: 'bg-danger-soft text-danger',
};

const ETIQUETA_CATEGORIA: Record<string, string> = {
    confirmacion: 'Confirmación',
    pendiente: 'Tarea',
    resultado: 'Resultado',
    prueba: 'Prueba',
};

function aplicar() {
    const limpios = Object.fromEntries(
        Object.entries(filtros).filter(([, valor]) => valor !== ''),
    );

    router.get('/administracion/notificaciones', limpios, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function limpiar() {
    Object.keys(filtros).forEach(
        (clave) => (filtros[clave as keyof typeof filtros] = ''),
    );
    aplicar();
}

function fecha(valor: string | null): string {
    return valor
        ? new Date(valor).toLocaleString('es-MX', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
}
</script>

<template>
    <Head title="Notificaciones del sistema" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Notificaciones del sistema"
            descripcion="Consulta de solo lectura de los avisos de todas las personas y estado de la entrega (colas, Reverb y push)."
            :icono="BellRing"
        />

        <section
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5"
            aria-label="Estado de la entrega de avisos"
        >
            <article
                v-for="tarjeta in tarjetas"
                :key="tarjeta.clave"
                class="flex gap-3 rounded-xl border bg-card p-4"
                :data-estado="tarjeta.estado"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                    :class="ESTILO_TARJETA[tarjeta.estado] ?? 'bg-muted'"
                >
                    <CircleCheck
                        v-if="tarjeta.estado === 'ok'"
                        class="size-5"
                    />
                    <TriangleAlert
                        v-else-if="tarjeta.estado === 'alerta'"
                        class="size-5"
                    />
                    <CircleAlert v-else class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold">
                        {{ tarjeta.titulo }}
                        <span class="ml-1 text-xs font-normal uppercase">{{
                            tarjeta.estado === 'ok' ? 'OK' : tarjeta.estado
                        }}</span>
                    </p>
                    <p class="text-xs text-pretty text-muted-foreground">
                        {{ tarjeta.detalle }}
                    </p>
                </div>
            </article>
        </section>

        <form
            class="grid grid-cols-1 gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4"
            @submit.prevent="aplicar"
        >
            <Input
                v-model="filtros.usuario"
                placeholder="Destinatario (nombre o correo)"
                aria-label="Destinatario"
            />
            <NativeSelect
                v-model="filtros.tipo"
                aria-label="Tipo"
                class="w-full"
            >
                <NativeSelectOption value=""
                    >Todos los tipos</NativeSelectOption
                >
                <NativeSelectOption
                    v-for="tipo in opciones.tipos"
                    :key="tipo"
                    :value="tipo"
                    >{{ tipo }}</NativeSelectOption
                >
            </NativeSelect>
            <NativeSelect
                v-model="filtros.modulo"
                aria-label="Módulo"
                class="w-full"
            >
                <NativeSelectOption value=""
                    >Todos los módulos</NativeSelectOption
                >
                <NativeSelectOption
                    v-for="modulo in opciones.modulos"
                    :key="modulo"
                    :value="modulo"
                    >{{ modulo }}</NativeSelectOption
                >
            </NativeSelect>
            <NativeSelect
                v-model="filtros.estado"
                aria-label="Leída o no leída"
                class="w-full"
            >
                <NativeSelectOption value=""
                    >Leídas y no leídas</NativeSelectOption
                >
                <NativeSelectOption value="no_leidas"
                    >No leídas</NativeSelectOption
                >
                <NativeSelectOption value="leidas">Leídas</NativeSelectOption>
            </NativeSelect>
            <NativeSelect
                v-model="filtros.entrega"
                aria-label="Estado de entrega"
                class="w-full"
            >
                <NativeSelectOption value=""
                    >Cualquier entrega</NativeSelectOption
                >
                <NativeSelectOption value="con_push"
                    >Con push a la app</NativeSelectOption
                >
                <NativeSelectOption value="sin_push"
                    >Sin push (sin dispositivo)</NativeSelectOption
                >
                <NativeSelectOption value="reconciliada"
                    >Repuesta por el reconciliador</NativeSelectOption
                >
            </NativeSelect>
            <Input v-model="filtros.desde" type="date" aria-label="Desde" />
            <Input v-model="filtros.hasta" type="date" aria-label="Hasta" />
            <div class="flex gap-2">
                <Button type="submit">Filtrar</Button>
                <Button type="button" variant="outline" @click="limpiar"
                    >Limpiar</Button
                >
            </div>
        </form>

        <CrudEmptyState
            v-if="notificaciones.data.length === 0"
            titulo="Sin notificaciones"
            descripcion="No hay avisos con esos filtros."
            :icono="BellRing"
        />

        <div v-else class="overflow-x-auto rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Fecha</TableHead>
                        <TableHead>Destinatario</TableHead>
                        <TableHead>Aviso</TableHead>
                        <TableHead>Tipo / módulo</TableHead>
                        <TableHead>Entrega</TableHead>
                        <TableHead>Estado</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="fila in notificaciones.data"
                        :key="fila.id"
                    >
                        <TableCell class="whitespace-nowrap tabular-nums">{{
                            fecha(fila.creada_en)
                        }}</TableCell>
                        <TableCell>{{
                            fila.destinatario?.nombre ?? '—'
                        }}</TableCell>
                        <TableCell class="max-w-md">
                            <p class="font-medium">{{ fila.titulo }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ fila.mensaje }}
                            </p>
                        </TableCell>
                        <TableCell class="text-xs">
                            <p>{{ fila.tipo ?? '—' }}</p>
                            <p class="text-muted-foreground">
                                {{ fila.modulo ?? '' }}
                                <span v-if="fila.categoria"
                                    >·
                                    {{
                                        ETIQUETA_CATEGORIA[fila.categoria] ??
                                        fila.categoria
                                    }}</span
                                >
                            </p>
                        </TableCell>
                        <TableCell class="text-xs">
                            <span v-if="fila.push_dispositivos"
                                >Push a
                                {{
                                    fila.push_dispositivos
                                }}
                                dispositivo(s)</span
                            >
                            <span v-else class="text-muted-foreground"
                                >Solo web</span
                            >
                            <p
                                v-if="fila.origen === 'reconciliador'"
                                class="text-muted-foreground"
                            >
                                Repuesta por el reconciliador
                            </p>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="fila.leida ? 'secondary' : 'default'"
                                >{{ fila.leida ? 'Leída' : 'No leída' }}</Badge
                            >
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <PaginacionTabla :datos="notificaciones" />
    </div>
</template>
