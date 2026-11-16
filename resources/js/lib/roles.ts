export const roleLabels: Record<string, string> = {
    operator: 'Superadmin',
    admin: 'Admin',
    supervisor: 'Supervisor',
    technician: 'Teknisi',
    warehouse: 'Gudang',
    customer: 'Customer',
};

export function getRoleLabel(role: string): string {
    return roleLabels[role] ?? role;
}
