<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Award, Copy, Download, Eye, Settings2, Sparkles } from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AccionesCelebracionHoy from '@/components/Celebraciones/AccionesCelebracionHoy.vue';
import CelebracionesTabsNav from '@/components/Celebraciones/CelebracionesTabsNav.vue';
import TarjetaCelebracionPersona from '@/components/Celebraciones/TarjetaCelebracionPersona.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import { configuracion, index as indexAniversarios } from '@/routes/rh/aniversarios';
import { tarjeta } from '@/routes/rh/celebraciones';
import { regenerar } from '@/routes/rh/celebraciones/tarjeta';

type Fila = {
    colaborador_id: number;
    nombre: string;
    puesto: string | null;
    sucursal: string | null;
    departamento: string | null;
    foto_url: string | null;
    fecha: string;
    es_hoy: boolean;
    anios: number;
    anios_texto: string;
    celebracion_id: number | null;
    enviada_at: string | null;
    avisada_todos_at: string | null;
};

const props = defineProps<{
    hoy: Fila[];
    proximos: Fila[];
    rango: { desde: string; hasta: string };
    filtros: { empresa_id: number | null; sucursal_id: number | null; departamento_id: number | null; busqueda: string | null };
    catalogos: {
        empresas: { id: number; nombre: string }[];
        sucursales: { id: number; nombre: string }[];
        departamentos: { id: number; nombre: string }[];
    };
    permisos: { gestionar: boolean; enviar: boolean; moderar: boolean };
    configuracionActiva: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Aniversarios', href: '' },
        ],
    },
});

const { getInitials } = useInitials();
const empresa = ref(props.filtros.empresa_id ? String(props.filtros.empresa_id) : '');
const sucursal = ref(props.filtros.sucursal_id ? String(props.filtros.sucursal_id) : '');
const departamento = ref(props.filtros.departamento_id ? String(props.filtros.departamento_id) : '');
const busqueda = ref(props.filtros.busqueda ?? '');
const desde = ref(props.rango.desde);
const hasta = ref(props.rango.hasta);
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch([empresa, sucursal, departamento, busqueda, desde, hasta], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            indexAniversarios.url(),
            {
                empresa_id: empresa.value || undefined,
                sucursal_id: sucursal.value || undefined,
                departamento_id: departamento.value || undefined,
                busqueda: busqueda.value || undefined,
                desde: desde.value,
                hasta: hasta.value,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 350);
});

// Vista previa real de la tarjeta (PNG del servidor).
const preview = ref<{ nombre: string; url: string } | null>(null);
const generando = ref<number | null>(null);

function verTarjeta(fila: Fila) {
    preview.value = { nombre: fila.nombre, url: `${tarjeta.url([fila.colaborador_id, 'aniversario_laboral'])}?ver=1&v=${Date.now()}` };
}

function generar(fila: Fila) {
    generando.value = fila.colaborador_id;
    router.post(regenerar.url([fila.colaborador_id, 'aniversario_laboral']), {}, {
        preserveScroll: true,
        onFinish: () => (generando.value = null),
    });
}

/**
 * Copiar la IMAGEN al portapapeles cuando el navegador lo permite; si no,
 * se copia el texto de felicitación y se avisa.
 */
async function copiar(fila: Fila) {
    const texto = `¡Felicidades ${fila.nombre} por tus ${fila.anios_texto} con MR. LANA!`;

    try {
        if (typeof ClipboardItem !== 'undefined' && navigator.clipboard?.write) {
            const respuesta = await fetch(`${tarjeta.url([fila.colaborador_id, 'aniversario_laboral'])}?ver=1`, { credentials: 'same-origin' });
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': await respuesta.blob() })]);
            toast.success('Tarjeta copiada: pégala en WhatsApp o en un correo.');

            return;
        }

        await navigator.clipboard.writeText(texto);
        toast.info('Tu navegador no permite copiar imágenes; se copió el mensaje de felicitación.');
    } catch {
        toast.error('No se pudo copiar. Usa «Descargar».');
    }
}

function fecha(valor: string): string {
    return new Date(`${valor}T12:00:00`).toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' });
}
</script>

<template>
    <Head title="Aniversarios" />

    <div class="flex w-full min-w-0 flex-col gap-4 p-4 sm:px-6 lg:px-8">
        <CrudPageHeader titulo="Aniversarios" :icono="Award">
            <Button v-if="permisos.gestionar" as-child variant="outline" size="sm">
                <Link :href="configuracion()"><Settings2 class="size-4" />Configuración</Link>
            </Button>
        </CrudPageHeader>

        <CelebracionesTabsNav activa="aniversarios" />

        <p v-if="!configuracionActiva" class="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
            Los aniversarios están desactivados en Configuración: no se preparan tarjetas automáticamente.
        </p>

        <!-- Hoy -->
        <section v-if="hoy.length > 0" class="rounded-2xl border border-[var(--success)]/40 bg-[var(--success)]/5 p-4">
            <h2 class="mb-3 flex items-center gap-2 font-semibold">
                <Sparkles class="size-5 text-[var(--success)]" />
                Hoy cumplen aniversario ({{ hoy.length }})
            </h2>
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-3">
                <TarjetaCelebracionPersona
                    v-for="fila in hoy"
                    :key="fila.colaborador_id"
                    :nombre="fila.nombre"
                    :detalle="`${fila.anios_texto} con MR. LANA · ${[fila.sucursal, fila.puesto].filter(Boolean).join(' · ')}`"
                    :foto-url="fila.foto_url"
                    destacado
                >
                    <template #icono><Award class="size-3.5 shrink-0 text-amber-500" /></template>
                    <template #acciones>
                        <Button size="sm" variant="secondary" @click="verTarjeta(fila)"><Eye class="size-4" />Ver tarjeta</Button>
                        <Button v-if="permisos.gestionar" size="sm" variant="outline" :disabled="generando === fila.colaborador_id" @click="generar(fila)">
                            <Spinner v-if="generando === fila.colaborador_id" />
                            <Sparkles v-else class="size-4" />
                            Generar
                        </Button>
                        <Button as-child size="sm" variant="outline">
                            <a :href="tarjeta.url([fila.colaborador_id, 'aniversario_laboral'])"><Download class="size-4" />Descargar</a>
                        </Button>
                        <Button size="sm" variant="outline" @click="copiar(fila)"><Copy class="size-4" />Copiar</Button>
                        <AccionesCelebracionHoy
                            :colaborador-id="fila.colaborador_id"
                            tipo="aniversario_laboral"
                            :nombre="fila.nombre"
                            :anios="fila.anios"
                            :avisada-todos-at="fila.avisada_todos_at"
                        />
                    </template>
                </TarjetaCelebracionPersona>
            </div>
        </section>

        <!-- Filtros -->
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-7">
            <Input v-model="busqueda" placeholder="Buscar persona…" class="lg:col-span-2" />
            <NativeSelect v-model="empresa" class="w-full">
                <option value="">Todas las empresas</option>
                <option v-for="e in catalogos.empresas" :key="e.id" :value="String(e.id)">{{ e.nombre }}</option>
            </NativeSelect>
            <NativeSelect v-model="sucursal" class="w-full">
                <option value="">Todas las sucursales</option>
                <option v-for="s in catalogos.sucursales" :key="s.id" :value="String(s.id)">{{ s.nombre }}</option>
            </NativeSelect>
            <NativeSelect v-model="departamento" class="w-full">
                <option value="">Todos los departamentos</option>
                <option v-for="d in catalogos.departamentos" :key="d.id" :value="String(d.id)">{{ d.nombre }}</option>
            </NativeSelect>
            <Input v-model="desde" type="date" aria-label="Desde" />
            <Input v-model="hasta" type="date" aria-label="Hasta" />
        </div>

        <!-- Próximos -->
        <section class="rounded-2xl border">
            <h2 class="border-b px-4 py-2 text-sm font-semibold">Próximos aniversarios · {{ proximos.length }}</h2>
            <CrudEmptyState v-if="proximos.length === 0" :icono="Award" titulo="Sin aniversarios en el rango elegido" />
            <ul v-else class="divide-y">
                <li v-for="fila in proximos" :key="`${fila.colaborador_id}-${fila.fecha}`" class="flex items-center gap-3 px-4 py-2.5 text-sm">
                    <Avatar class="size-9 shrink-0">
                        <AvatarImage v-if="fila.foto_url" :src="fila.foto_url" :alt="fila.nombre" />
                        <AvatarFallback>{{ getInitials(fila.nombre) }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ fila.nombre }}</p>
                        <p class="truncate text-xs text-muted-foreground">{{ [fila.sucursal, fila.puesto].filter(Boolean).join(' · ') }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="font-medium">{{ fila.anios_texto }}</p>
                        <p class="text-xs text-muted-foreground capitalize">{{ fecha(fila.fecha) }}</p>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <Dialog :open="preview !== null" @update:open="(v) => !v && (preview = null)">
        <DialogContent class="w-[calc(100vw-2rem)] sm:max-w-md">
            <DialogHeader><DialogTitle>Tarjeta de {{ preview?.nombre }}</DialogTitle></DialogHeader>
            <img v-if="preview" :src="preview.url" alt="Tarjeta de aniversario" class="w-full rounded-lg border" />
        </DialogContent>
    </Dialog>
</template>
