import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

const roleLabels: Record<string, string> = {
    operator: 'Superadmin',
    admin: 'Admin',
    supervisor: 'Supervisor',
    technician: 'Teknisi',
    warehouse: 'Gudang',
    customer: 'Pelanggan',
};

export function UserInfo({
    user,
    showEmail = false,
}: {
    user: User;
    showEmail?: boolean;
}) {
    const getInitials = useInitials();
    const roleLabel = user.role ? (roleLabels[user.role] ?? user.role) : null;

    return (
        <>
            <Avatar className="size-8 shrink-0 overflow-hidden rounded-full border border-[var(--border)]">
                <AvatarImage src={user.avatar} alt={user.name} />
                <AvatarFallback className="bg-primary/12 rounded-full text-xs font-bold text-[var(--brand-text)]">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div className="grid min-w-0 flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
                <span className="text-foreground truncate font-medium">
                    {user.name}
                </span>
                {showEmail ? (
                    <span className="text-muted-foreground truncate text-xs">
                        {user.email}
                    </span>
                ) : (
                    roleLabel && (
                        <span className="text-muted-foreground truncate text-[11px]">
                            {roleLabel}
                        </span>
                    )
                )}
            </div>
        </>
    );
}
