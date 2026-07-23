<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    Calendar,
    ClipboardList,
    FileSignature,
    FileText,
    History,
    IdCard,
    MapPinned,
    ScrollText,
    User,
} from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import InputError from '@/components/InputError.vue';
import ExpedienteDocumentos from '@/components/Rh/ExpedienteDocumentos.vue';
import ProximamenteTab from '@/components/Rh/ProximamenteTab.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { update as actualizarDatosPersonales } from '@/routes/rh/expedientes/datos-personales';
import type {
    DocumentoExpedienteItem,
    ExpedienteColaborador,
    ResumenExpediente,
} from '@/types';

const props = defineProps<{
    esPropio: boolean;
    puedeEditar: boolean;
    puedeRevisarDocumentos: boolean;
    colaborador: ExpedienteColaborador;
    resumenExpediente: ResumenExpediente;
    documentosRequeridos: DocumentoExpedienteItem[];
}>();

const form = useForm({
    fecha_nacimiento: props.colaborador.fecha_nacimiento ?? '',
    curp: props.colaborador.curp ?? '',
    rfc: props.colaborador.rfc ?? '',
    nss: props.colaborador.nss ?? '',
    domicilio: props.colaborador.domicilio ?? '',
    correo_personal: props.colaborador.correo_personal ?? '',
    contacto_emergencia_nombre:
        props.colaborador.contacto_emergencia_nombre ?? '',
    contacto_emergencia_telefono:
        props.colaborador.contacto_emergencia_telefono ?? '',
});

function guardarDatosPersonales() {
    form.put(actualizarDatosPersonales.url(props.colaborador.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Expediente de ${colaborador.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Card class="rounded-3xl border-border/60">
            <CardContent
                class="flex flex-col gap-4 sm:flex-row sm:items-center"
            >
                <span
                    class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-primary/10 text-primary"
                >
                    <img
                        v-if="colaborador.foto_path"
                        :src="colaborador.foto_path"
                        alt=""
                        class="size-full object-cover"
                    />
                    <User v-else class="size-7" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-lg font-semibold">
                            {{ colaborador.name }} {{ colaborador.apellidos }}
                        </h1>
                        <EstadoBadge :estado="colaborador.estatus" />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{
                            colaborador.numero_empleado ??
                            'Sin número de empleado'
                        }}
                        · {{ colaborador.puesto?.nombre ?? 'Sin puesto' }}
                    </p>
                    <div
                        class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                    >
                        <span class="flex items-center gap-1"
                            ><Building2 class="size-3.5" />{{
                                colaborador.empresa?.nombre ?? '—'
                            }}</span
                        >
                        <span class="flex items-center gap-1"
                            ><MapPinned class="size-3.5" />{{
                                colaborador.sucursal?.nombre ?? '—'
                            }}</span
                        >
                        <span class="flex items-center gap-1"
                            ><Briefcase class="size-3.5" />{{
                                colaborador.departamento?.nombre ?? '—'
                            }}</span
                        >
                    </div>
                </div>

                <div class="flex flex-col items-end gap-1">
                    <span class="text-xs text-muted-foreground"
                        >Expediente</span
                    >
                    <div class="flex items-center gap-2">
                        <Progress
                            :model-value="resumenExpediente.porcentaje"
                            class="h-2 w-28"
                        />
                        <span class="text-sm font-semibold tabular-nums"
                            >{{ resumenExpediente.porcentaje }}%</span
                        >
                    </div>
                </div>
            </CardContent>
        </Card>

        <Tabs default-value="resumen">
            <TabsList>
                <TabsTrigger value="resumen">Resumen</TabsTrigger>
                <TabsTrigger value="personales">Datos personales</TabsTrigger>
                <TabsTrigger value="laborales">Datos laborales</TabsTrigger>
                <TabsTrigger value="documentos">Documentos</TabsTrigger>
                <TabsTrigger value="contrato">Contrato</TabsTrigger>
                <TabsTrigger value="avisos">Avisos</TabsTrigger>
                <TabsTrigger value="vacaciones">Vacaciones</TabsTrigger>
                <TabsTrigger value="solicitudes">Solicitudes</TabsTrigger>
                <TabsTrigger value="historial">Historial RH</TabsTrigger>
                <TabsTrigger value="bitacora">Bitácora</TabsTrigger>
            </TabsList>

            <TabsContent value="resumen" class="pt-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Card class="rounded-2xl border-border/60">
                        <CardContent class="pt-6 text-center">
                            <p class="text-2xl font-semibold tabular-nums">
                                {{ resumenExpediente.requeridos_aprobados }}/{{
                                    resumenExpediente.requeridos_total
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Documentos requeridos aprobados
                            </p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border-border/60">
                        <CardContent class="pt-6 text-center">
                            <p
                                class="text-2xl font-semibold text-warning tabular-nums"
                            >
                                {{ resumenExpediente.pendientes }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Pendientes de cargar/revisar
                            </p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border-border/60">
                        <CardContent class="pt-6 text-center">
                            <p
                                class="text-2xl font-semibold text-destructive tabular-nums"
                            >
                                {{ resumenExpediente.rechazados }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Rechazados o con corrección pendiente
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Card class="rounded-2xl border-border/60">
                        <CardHeader>
                            <CardTitle class="text-sm">Contacto</CardTitle>
                        </CardHeader>
                        <CardContent class="grid gap-1 text-sm">
                            <p>{{ colaborador.email }}</p>
                            <p class="text-muted-foreground">
                                {{ colaborador.telefono ?? 'Sin teléfono' }}
                            </p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border-border/60">
                        <CardHeader>
                            <CardTitle class="text-sm">Jefe directo</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm">
                            <p v-if="colaborador.jefe">
                                {{ colaborador.jefe.name }}
                                {{ colaborador.jefe.apellidos }}
                            </p>
                            <p v-else class="text-muted-foreground">
                                Sin asignar
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </TabsContent>

            <TabsContent value="personales" class="pt-4">
                <Card class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <IdCard class="size-4" />
                            Datos personales
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                            @submit.prevent="guardarDatosPersonales"
                        >
                            <div class="grid gap-2">
                                <Label for="fecha_nacimiento"
                                    >Fecha de nacimiento</Label
                                >
                                <Input
                                    id="fecha_nacimiento"
                                    v-model="form.fecha_nacimiento"
                                    type="date"
                                    :disabled="!puedeEditar"
                                />
                                <InputError
                                    :message="form.errors.fecha_nacimiento"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="curp">CURP</Label>
                                <Input
                                    id="curp"
                                    v-model="form.curp"
                                    class="uppercase"
                                    maxlength="18"
                                    :disabled="!puedeEditar"
                                />
                                <InputError :message="form.errors.curp" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="rfc">RFC</Label>
                                <Input
                                    id="rfc"
                                    v-model="form.rfc"
                                    class="uppercase"
                                    maxlength="13"
                                    :disabled="!puedeEditar"
                                />
                                <InputError :message="form.errors.rfc" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="nss">NSS</Label>
                                <Input
                                    id="nss"
                                    v-model="form.nss"
                                    maxlength="11"
                                    :disabled="!puedeEditar"
                                />
                                <InputError :message="form.errors.nss" />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="domicilio">Domicilio</Label>
                                <Input
                                    id="domicilio"
                                    v-model="form.domicilio"
                                    :disabled="!puedeEditar"
                                />
                                <InputError :message="form.errors.domicilio" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="correo_personal"
                                    >Correo personal</Label
                                >
                                <Input
                                    id="correo_personal"
                                    v-model="form.correo_personal"
                                    type="email"
                                    :disabled="!puedeEditar"
                                />
                                <InputError
                                    :message="form.errors.correo_personal"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="contacto_emergencia_nombre"
                                    >Contacto de emergencia</Label
                                >
                                <Input
                                    id="contacto_emergencia_nombre"
                                    v-model="form.contacto_emergencia_nombre"
                                    :disabled="!puedeEditar"
                                />
                                <InputError
                                    :message="
                                        form.errors.contacto_emergencia_nombre
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="contacto_emergencia_telefono"
                                    >Teléfono de emergencia</Label
                                >
                                <Input
                                    id="contacto_emergencia_telefono"
                                    v-model="form.contacto_emergencia_telefono"
                                    :disabled="!puedeEditar"
                                />
                                <InputError
                                    :message="
                                        form.errors.contacto_emergencia_telefono
                                    "
                                />
                            </div>

                            <div v-if="puedeEditar" class="sm:col-span-2">
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                >
                                    <Spinner v-if="form.processing" />
                                    Guardar datos personales
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="laborales" class="pt-4">
                <Card class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Briefcase class="size-4" />
                            Datos laborales
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Fecha de ingreso
                            </p>
                            <p class="text-sm font-medium">
                                {{ colaborador.fecha_ingreso ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Estado laboral
                            </p>
                            <EstadoBadge :estado="colaborador.estatus" />
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Empresa</p>
                            <p class="text-sm font-medium">
                                {{ colaborador.empresa?.nombre ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Sucursal
                            </p>
                            <p class="text-sm font-medium">
                                {{ colaborador.sucursal?.nombre ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Departamento
                            </p>
                            <p class="text-sm font-medium">
                                {{ colaborador.departamento?.nombre ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Puesto</p>
                            <p class="text-sm font-medium">
                                {{ colaborador.puesto?.nombre ?? '—' }}
                            </p>
                        </div>
                        <p class="text-xs text-muted-foreground sm:col-span-2">
                            Estos datos se editan desde Administración →
                            Colaboradores.
                        </p>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="documentos" class="pt-4">
                <ExpedienteDocumentos
                    :colaborador-id="colaborador.id"
                    :documentos="documentosRequeridos"
                    :puede-subir="esPropio || puedeEditar"
                    :puede-revisar="puedeRevisarDocumentos"
                />
            </TabsContent>

            <TabsContent value="contrato" class="pt-4">
                <ProximamenteTab
                    :icono="FileSignature"
                    titulo="Contrato"
                    descripcion="La gestión de contrato laboral estará disponible en una fase siguiente del Portal RH."
                />
            </TabsContent>
            <TabsContent value="avisos" class="pt-4">
                <ProximamenteTab
                    :icono="ScrollText"
                    titulo="Avisos y consentimientos"
                    descripcion="Aviso de privacidad, consentimientos y acuses de firma llegan en la Fase 2 del roadmap."
                />
            </TabsContent>
            <TabsContent value="vacaciones" class="pt-4">
                <ProximamenteTab
                    :icono="Calendar"
                    titulo="Vacaciones"
                    descripcion="Saldos, solicitudes y calendario de vacaciones llegan en la Fase 3 del roadmap."
                />
            </TabsContent>
            <TabsContent value="solicitudes" class="pt-4">
                <ProximamenteTab
                    :icono="ClipboardList"
                    titulo="Solicitudes"
                    descripcion="El historial de solicitudes RH de este colaborador llega en la Fase 3 del roadmap."
                />
            </TabsContent>
            <TabsContent value="historial" class="pt-4">
                <ProximamenteTab
                    :icono="History"
                    titulo="Historial RH"
                    descripcion="Movimientos laborales (altas, cambios de puesto/sucursal, bajas) llegan en un checkpoint siguiente."
                />
            </TabsContent>
            <TabsContent value="bitacora" class="pt-4">
                <ProximamenteTab
                    :icono="FileText"
                    titulo="Bitácora"
                    descripcion="Bitácora de auditoría del expediente, en preparación."
                />
            </TabsContent>
        </Tabs>
    </div>
</template>
