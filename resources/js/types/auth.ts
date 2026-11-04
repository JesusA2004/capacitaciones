export type User = {
    id: number;
    name: string;
    username: string;
    email: string | null;
    /** URL protegida de su foto de perfil (null = iniciales). */
    avatar?: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    roles?: string[];
    permissions?: string[];
    /** Expediente vinculado (null = cuenta sin colaborador). */
    colaborador_id?: number | null;
    preferencias_ui?: {
        tema_color: string;
        avatar_color: string;
        animaciones: boolean;
    } | null;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */
