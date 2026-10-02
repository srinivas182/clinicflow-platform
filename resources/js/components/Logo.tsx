import { Activity } from 'lucide-react';

export function Logo({ tone = 'dark' }: { tone?: 'dark' | 'light' }) {
    return (
        <span className={`inline-flex items-center gap-2 text-lg font-semibold ${tone === 'light' ? 'text-white' : 'text-ink'}`}>
            <span className="grid size-7 place-items-center rounded-lg bg-teal text-white">
                <Activity className="size-4" strokeWidth={2.2} aria-hidden="true" />
            </span>
            Clinic Flow
        </span>
    );
}
