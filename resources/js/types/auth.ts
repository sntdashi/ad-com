export type User = {
    id: number;
    name: string;
    username?: string | null;
    email: string;
    role:
        | 'operator'
        | 'admin'
        | 'supervisor'
        | 'technician'
        | 'warehouse'
        | 'customer';
    phone?: string | null;
    address?: string | null;
    avatar_url?: string | null;
    specialty?: string | null;
    bio?: string | null;
    show_phone_publicly?: boolean;
    is_active?: boolean;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
