import {
    BellRing,
    Building2,
    ClipboardCheck,
    ClipboardList,
    FileSignature,
    FolderKanban,
    Landmark,
    MapPinned,
    Megaphone,
    Monitor,
    PartyPopper,
    QrCode,
    ReceiptText,
    RotateCcw,
    Settings2,
    Sparkles,
    UserMinus,
    UserPlus,
    UserRound,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Los datos de la guía guardan el ícono como nombre (para que Node pueda
 * leerlos sin Vue al generar los Markdown); aquí se resuelve el componente.
 */
const ICONOS: Record<string, Component> = {
    BellRing,
    Building2,
    ClipboardCheck,
    ClipboardList,
    FileSignature,
    FolderKanban,
    Landmark,
    MapPinned,
    Megaphone,
    Monitor,
    PartyPopper,
    QrCode,
    ReceiptText,
    RotateCcw,
    Settings2,
    Sparkles,
    UserMinus,
    UserPlus,
    UserRound,
    Users,
};

export function iconoGuia(nombre: string): Component {
    return ICONOS[nombre] ?? Sparkles;
}
