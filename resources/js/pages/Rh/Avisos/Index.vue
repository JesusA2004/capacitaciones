<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BellRing, ImageIcon, Loader2, Send, Users2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { colaboradores, imagen, index, store } from '@/routes/rh/avisos';
import type { AvisoItem, ColaboradorBusqueda, RespuestaPaginada } from '@/types';

const props = defineProps<{
    avisos: RespuestaPaginada<AvisoItem>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Avisos', href: index.url() },
        ],
    },
});

const colaboradorElegido = ref<ColaboradorBusqueda | null>(null);
const previsualizacion = ref<string | null>(null);

const form = useForm<{
    titulo: string;
    mensaje: string;
    alcance: 'todos' | 'colaborador';
    colaborador_objetivo_id: number | null;
    imagen: File | null;
}>({
    titulo: '',
    mensaje: '',
    alcance: 'todos',
    colaborador_objetivo_id: null,
    imagen: null,
});

const faltaColaborador = computed(
    () => form.alcance === 'colaborador' && colaboradorElegido.value === null,
);

function elegirImagen(evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    form.imagen = archivo;
    previsualizacion.value = archivo ? URL.createObjectURL(archivo) : null;
}

function quitarImagen() {
    form.imagen = null;
    previsualizacion.value = null;
}

function enviar() {
    form.colaborador_objetivo_id = colaboradorElegido.value?.id ?? null;
    form
        .transform((datos) => ({
            ...datos,
            colaborador_objetivo_id:
                form.alcance === 'colaborador' ? datos.colaborador_objetivo_id : null,
        }))
        .post(store.url(), {
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                colaboradorElegido.value = null;
                previsualizacion.value = null;
            },
        });
}
</script>

<template>
    <Head title="Avisos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Avisos"
            descripcion="Manda un mensaje con imagen a toda la empresa o a un colaborador específico."
            :icono="BellRing"
        />

        <div class="grid gap-6 lg:grid-cols-5">
            <!-- Componer -->
            <Card class="lg:col-span-2">
                <CardContent class="flex flex-col gap-4 pt-6">
                    <div class="flex flex-col gap-1.5">
                        <Label for="aviso-alcance">A quién le llega</Label>
                        <Select v-model="form.alcance">
                            <SelectTrigger id="aviso-alcance" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="todos">
                                    Toda la empresa
                                </SelectItem>
                                <SelectItem value="colaborador">
                                    Un colaborador
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div
                        v-if="form.alcance === 'colaborador'"
                        class="flex flex-col gap-1.5"
                    >
                        <Label>Colaborador</Label>
                        <BuscadorColaborador
                            v-model="colaboradorElegido"
                            :buscar-url="
                                (texto) => colaboradores.url({ query: { q: texto } })
                            "
                        />
                        <p
                            v-if="form.errors.colaborador_objetivo_id"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.colaborador_objetivo_id }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="aviso-titulo">Título</Label>
                        <Input
                            id="aviso-titulo"
                            v-model="form.titulo"
                            maxlength="150"
                            placeholder="Ej. Cambio de horario de caja"
                        />
                        <p v-if="form.errors.titulo" class="text-xs text-destructive">
                            {{ form.errors.titulo }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="aviso-mensaje">Mensaje</Label>
                        <Textarea
                            id="aviso-mensaje"
                            v-model="form.mensaje"
                            rows="5"
                            maxlength="2000"
                            placeholder="Escribe el aviso tal como quieres que lo lean."
                        />
                        <p
                            v-if="form.errors.mensaje"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.mensaje }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label>Imagen (opcional)</Label>
                        <div
                            v-if="previsualizacion"
                            class="relative overflow-hidden rounded-xl border border-border"
                        >
                            <img
                                :src="previsualizacion"
                                alt=""
                                class="h-40 w-full object-cover"
                            />
                            <Button
                                size="icon"
                                variant="secondary"
                                class="absolute top-2 right-2 size-7"
                                type="button"
                                @click="quitarImagen"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                        <label
                            v-else
                            class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground transition-colors hover:border-primary hover:bg-muted/40"
                        >
                            <ImageIcon class="size-6" />
                            Subir imagen (JPG, PNG o WEBP, máx. 8 MB)
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                @change="elegirImagen"
                            />
                        </label>
                        <p v-if="form.errors.imagen" class="text-xs text-destructive">
                            {{ form.errors.imagen }}
                        </p>
                    </div>

                    <Button
                        class="mt-2 w-full"
                        :disabled="
                            form.processing ||
                            !form.titulo ||
                            !form.mensaje ||
                            faltaColaborador
                        "
                        @click="enviar"
                    >
                        <Loader2
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        <Send v-else class="size-4" />
                        Enviar aviso
                    </Button>
                </CardContent>
            </Card>

            <!-- Historial -->
            <div class="flex flex-col gap-3 lg:col-span-3">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Avisos enviados
                </h2>

                <p
                    v-if="!props.avisos.data.length"
                    class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
                >
                    Todavía no se ha enviado ningún aviso.
                </p>

                <Card v-for="aviso in props.avisos.data" :key="aviso.id">
                    <CardContent class="flex gap-4 pt-6">
                        <img
                            v-if="aviso.imagen_path"
                            :src="imagen.url(aviso.id)"
                            alt=""
                            class="size-20 shrink-0 rounded-lg object-cover"
                        />
                        <Avatar v-else class="size-20 shrink-0 rounded-lg">
                            <AvatarFallback class="rounded-lg bg-primary/10 text-primary">
                                <BellRing class="size-6" />
                            </AvatarFallback>
                        </Avatar>

                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-semibold">{{ aviso.titulo }}</p>
                                <Badge variant="outline" class="gap-1.5">
                                    <Users2 class="size-3" />
                                    {{
                                        aviso.alcance === 'todos'
                                            ? 'Toda la empresa'
                                            : (aviso.colaborador_objetivo
                                                  ? `${aviso.colaborador_objetivo.name} ${aviso.colaborador_objetivo.apellidos ?? ''}`
                                                  : 'Un colaborador')
                                    }}
                                </Badge>
                            </div>
                            <p class="line-clamp-2 text-sm text-muted-foreground">
                                {{ aviso.mensaje }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    aviso.enviado_en
                                        ? formatearFecha(aviso.enviado_en)
                                        : '—'
                                }}
                                · {{ aviso.creado_por?.name ?? 'RH' }}
                                · {{ aviso.lecturas_count ?? 0 }} vista(s)
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
