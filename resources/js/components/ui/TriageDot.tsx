import { cn } from '@/lib/cn';

export type TriageColour = 'red' | 'orange' | 'yellow' | 'green';

const colours: Record<TriageColour, string> = {
    red: 'bg-triage-red',
    orange: 'bg-triage-orange',
    yellow: 'bg-triage-yellow',
    green: 'bg-triage-green',
};

const labels: Record<TriageColour, string> = {
    red: 'Red — emergency',
    orange: 'Orange — very urgent',
    yellow: 'Yellow — urgent',
    green: 'Green — routine',
};

/**
 * South African Triage Scale colour. These colours are reserved for triage only.
 */
export function TriageDot({ colour, showLabel = false }: { colour: TriageColour; showLabel?: boolean }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-sm">
            <span aria-hidden="true" className={cn('inline-block size-2.5 rounded-[3px]', colours[colour])} />
            <span className={showLabel ? '' : 'sr-only'}>{labels[colour]}</span>
        </span>
    );
}
