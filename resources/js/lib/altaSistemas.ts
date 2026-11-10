import {
    AtSign,
    Briefcase,
    Building2,
    Cake,
    CalendarDays,
    FileText,
    Hash,
    Hospital,
    IdCard,
    KeyRound,
    Mail,
    MapPin,
    Network,
    Phone,
    ReceiptText,
    UserCog,
    UserRound,
    VenusAndMars,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';

/**
 * Íconos de los datos de «Alta en sistemas» (bandeja y detalle). Las
 * etiquetas vienen del backend (AvisoAltaSistemasService::datosBasicos).
 */
export const ICONO_SECCION_ALTA: Record<string, LucideIcon> = {
    persona: UserRound,
    contacto: Phone,
    puesto: Briefcase,
};

const ICONO_DATO: Record<string, LucideIcon> = {
    'Nombre completo': UserRound,
    CURP: IdCard,
    RFC: ReceiptText,
    NSS: Hospital,
    'Fecha de nacimiento': Cake,
    Género: VenusAndMars,
    Teléfono: Phone,
    'Correo personal': Mail,
    'Correo corporativo': AtSign,
    'Número de empleado': Hash,
    Puesto: Briefcase,
    Departamento: Network,
    Sucursal: MapPin,
    'Empresa (razón social)': Building2,
    'Jefe directo': UserCog,
    'Fecha de ingreso': CalendarDays,
    'Tipo de contratación': FileText,
    'Usuario de PEOPLE': KeyRound,
};

export function iconoDatoAlta(etiqueta: string): LucideIcon {
    return ICONO_DATO[etiqueta] ?? FileText;
}
