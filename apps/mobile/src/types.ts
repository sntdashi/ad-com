export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    role: 'customer' | 'technician';
    username: string | null;
    phone: string | null;
    address: string | null;
    bio: string | null;
    avatar_url: string | null;
    specialty: string | null;
    show_phone_publicly: boolean;
    two_factor_enabled: boolean;
};
export type Service = {
    id: number;
    code: string;
    slug: string;
    name: string;
    summary: string;
    description: string;
    pricing_label: string;
    starting_price: number;
    service_mode: string;
    icon: string;
    warranty_days: number;
};
export type Technician = {
    id: number;
    name: string;
    avatar_url: string | null;
    phone: string | null;
    specialty: string | null;
    bio: string | null;
    rating_average: number | null;
    reviews_count: number;
};
export type Review = {
    id: number;
    rating: number;
    quality_rating: number;
    communication_rating: number;
    punctuality_rating: number;
    comment: string | null;
};
export type Order = {
    id: string;
    ticket_number: string;
    status: string;
    status_label: string;
    service: Service;
    customer_name: string;
    phone: string;
    email: string | null;
    address: string;
    complaint: string;
    service_mode: string;
    urgency: string;
    created_at: string;
    scheduled_at: string | null;
    completed_at: string | null;
    estimated_total: number | null;
    technician: Technician | null;
    technician_team: Technician[];
    timeline: {
        id: number;
        status: string;
        title: string;
        description: string;
        occurred_at: string;
    }[];
    finance: {
        estimated_total: number | null;
        verified_income: number;
        pending_income: number;
    };
    allowed_statuses: { value: string; label: string }[];
    can_chat: boolean;
    can_review: boolean;
    reviewable_technicians: (Technician & { review: Review | null })[];
};
export type Message = {
    id: number;
    body: string;
    created_at: string;
    is_mine: boolean;
    sender: {
        id: number;
        name: string;
        role: string;
        avatar_url: string | null;
    } | null;
};
export type Page<T> = {
    data: T[];
    meta?: { current_page: number; last_page: number };
    links?: { next: string | null };
};
export type AuthResponse =
    | { token: string; expires_at: string; user: User }
    | {
          two_factor: true;
          challenge_token: string;
          expires_in: number;
          method: 'authenticator';
          user: User;
      };
