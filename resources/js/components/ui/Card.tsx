import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export function Card({ title, aside, className, children }: { title?: ReactNode; aside?: ReactNode; className?: string; children: ReactNode }) {
    return (
        <section className={cn('rounded-xl border border-line bg-white px-5 py-4', className)}>
            {title && (
                <header className="mb-3 flex items-center gap-2">
                    <h3 className="text-[15px] font-semibold">{title}</h3>
                    {aside && <span className="ml-auto text-xs text-muted">{aside}</span>}
                </header>
            )}
            {children}
        </section>
    );
}

export function Kpi({ label, value, hint, trend }: { label: string; value: string; hint?: string; trend?: 'up' | 'down' }) {
    return (
        <div className="flex-1 rounded-xl border border-line bg-white px-4 py-3.5">
            <div className="text-xs text-muted">{label}</div>
            <div className="mt-1 text-2xl font-semibold tracking-tight">{value}</div>
            {hint && (
                <div className={cn('mt-0.5 text-xs', trend === 'up' && 'text-status-success', trend === 'down' && 'text-status-danger', !trend && 'text-muted')}>
                    {hint}
                </div>
            )}
        </div>
    );
}
