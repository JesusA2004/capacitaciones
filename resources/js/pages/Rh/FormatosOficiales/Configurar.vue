<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { RefreshCw, Save, Settings2 } from '@lucide/vue';
import { reactive, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import FormatosTabsNav from '@/components/Rh/FormatosTabsNav.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    configuracion,
    index,
    original,
    vistaPreviaConfiguracion,
} from '@/routes/rh/formatos-oficiales';
import type { CampoDisponible, CampoOverlay, OverlayConfig } from '@/types';

const props = defineProps<{
    formato: {
        id: number;
        slug: string;
        nombre: string;
        tipo_etiqueta: string;
        file_type: string;
        overlay_config: OverlayConfig;
    };
    camposDisponibles: CampoDisponible[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Formatos', href: index.url() },
            { title: 'Configurar', href: '' },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

function valorPorDefecto(): CampoOverlay {
    return {
        pagina: 1,
        x: 20,
        y: 20,
        font_size: 11,
        align: 'left',
        max_width: 80,
        color: '#111111',
        enabled: false,
    };
}

const config = reactive<OverlayConfig>(
    Object.fromEntries(
        props.camposDisponibles.map((campo) => {
            const guardado = props.formato.overlay_config[campo.clave];
            const valor = { ...valorPorDefecto(), ...guardado };

            // El Input numerico no acepta null: si el backend guardo
            // max_width en null, se usa el default en vez de romper el
            // v-model.
            valor.max_width ??= valorPorDefecto().max_width;

            return [campo.clave, valor];
        }),
    ),
);

const cargandoPreview = ref(false);
const previewPdfBase64 = ref<string | null>(null);
const guardando = ref(false);

async function actualizarVistaPrevia() {
    cargandoPreview.value = true;

    try {
        const respuesta = await postJson<{ pdf_base64: string }>(
            vistaPreviaConfiguracion.url(props.formato.id),
            { overlay_config: config },
        );

        previewPdfBase64.value = respuesta.pdf_base64;
    } catch {
        mostrarError('No se pudo generar la vista previa.');
    } finally {
        cargandoPreview.value = false;
    }
}

function guardar() {
    guardando.value = true;
    router.post(
        configuracion.url(props.formato.id),
        { overlay_config: config },
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Configuración guardada.'),
            onError: () => mostrarError('No se pudo guardar la configuración.'),
            onFinish: () => (guardando.value = false),
        },
    );
}
</script>

<template>
    <div class="flex w-full min-w-0 flex-col gap-6 p-4 sm:p-6">
        <CrudPageHeader
            detalle
            :titulo="`Configurar «${formato.nombre}»`"
            descripcion="Define dónde se pintan los datos del colaborador sobre el PDF oficial."
            :icono="Settings2"
        >
            <Button as-child variant="outline" size="sm">
                <Link :href="index.url()">Volver a formatos</Link>
            </Button>
        </CrudPageHeader>

        <FormatosTabsNav activa="oficiales" />

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <Card class="overflow-hidden">
                <CardHeader>
                    <CardTitle class="text-base">PDF original</CardTitle>
                    <CardDescription>{{ formato.tipo_etiqueta }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <iframe
                        v-if="formato.file_type === 'pdf'"
                        :src="original.url(formato.id)"
                        title="PDF original del formato"
                        class="h-[520px] w-full rounded-lg border"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        Este formato es DOCX; el configurador visual solo aplica a formatos PDF.
                    </p>
                </CardContent>
            </Card>

            <Card class="overflow-hidden">
                <CardHeader class="flex-row items-center justify-between gap-2 space-y-0">
                    <div>
                        <CardTitle class="text-base">Vista previa con datos de ejemplo</CardTitle>
                        <CardDescription>Usa datos ficticios, nunca un colaborador real.</CardDescription>
                    </div>
                    <Button size="sm" variant="outline" :disabled="cargandoPreview" @click="actualizarVistaPrevia">
                        <Spinner v-if="cargandoPreview" />
                        <RefreshCw v-else class="size-4" />
                        Actualizar vista previa
                    </Button>
                </CardHeader>
                <CardContent>
                    <iframe
                        v-if="previewPdfBase64"
                        :src="`data:application/pdf;base64,${previewPdfBase64}`"
                        title="Vista previa con overlay"
                        class="h-[520px] w-full rounded-lg border"
                    />
                    <div
                        v-else
                        class="flex h-[520px] items-center justify-center rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                    >
                        Haz clic en «Actualizar vista previa» para ver cómo queda con los campos activados.
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Campos</CardTitle>
                <CardDescription>
                    Coordenadas en milímetros desde la esquina superior izquierda de la página. Activa solo los campos que este formato necesita.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <div
                    v-for="campo in camposDisponibles"
                    :key="campo.clave"
                    class="rounded-xl border p-3"
                >
                    <label class="flex cursor-pointer items-center gap-3">
                        <Checkbox
                            :model-value="config[campo.clave].enabled"
                            @update:model-value="
                                (v) => (config[campo.clave].enabled = v === true)
                            "
                        />
                        <span class="font-medium">{{ campo.etiqueta }}</span>
                    </label>

                    <div
                        v-if="config[campo.clave].enabled"
                        class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7"
                    >
                        <div class="grid gap-1">
                            <Label class="text-xs">Página</Label>
                            <Input
                                v-model.number="config[campo.clave].pagina"
                                type="number"
                                min="1"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">X (mm)</Label>
                            <Input
                                v-model.number="config[campo.clave].x"
                                type="number"
                                step="0.5"
                                min="0"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Y (mm)</Label>
                            <Input
                                v-model.number="config[campo.clave].y"
                                type="number"
                                step="0.5"
                                min="0"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Tamaño</Label>
                            <Input
                                v-model.number="config[campo.clave].font_size"
                                type="number"
                                step="0.5"
                                min="4"
                                max="72"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Alinear</Label>
                            <Select v-model="config[campo.clave].align">
                                <SelectTrigger class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="left">Izquierda</SelectItem>
                                    <SelectItem value="center">Centro</SelectItem>
                                    <SelectItem value="right">Derecha</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Ancho máx (mm)</Label>
                            <Input
                                v-model.number="config[campo.clave].max_width"
                                type="number"
                                step="1"
                                min="1"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Color</Label>
                            <Input
                                v-model="config[campo.clave].color"
                                type="color"
                                class="h-9 w-full p-1"
                            />
                        </div>
                    </div>
                </div>
            </CardContent>
            <CardFooter class="justify-end gap-2">
                <Button variant="outline" :disabled="cargandoPreview" @click="actualizarVistaPrevia">
                    <Spinner v-if="cargandoPreview" />
                    <RefreshCw v-else class="size-4" />
                    Actualizar vista previa
                </Button>
                <Button :disabled="guardando" @click="guardar">
                    <Spinner v-if="guardando" />
                    <Save v-else class="size-4" />
                    Guardar configuración
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
