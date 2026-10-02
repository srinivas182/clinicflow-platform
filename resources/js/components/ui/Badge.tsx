import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type BadgeTone = 'neutral' | 'teal' | 'network' | 'success' | 'warning' | 'danger';

const tones: Record<BadgeTone, string> = {
    neutral: 'bg-paper text-muted',
    teal: 'bg-mint text-teal-deep',
    network: 'bg-jacaranda-wash text-jacaranda-deep',
    success: 'bg-status-success-wash text-status-success',
    warning: 'bg-status-warning-wash text-status-warning',
    danger: 'bg-status-danger-wash text-status-danger',
};

export function Badge({ tone = 'neutral', icon, children }: { tone?: BadgeTone; icon?: ReactNode; children: ReactNode }) {
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', tones[tone])}>
            {icon}
            {children}
        </span>
    );
}
