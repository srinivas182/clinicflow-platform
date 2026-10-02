import type { InputHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
    label: string;
    error?: string;
    hint?: ReactNode;
}

/**
 * Labelled input with inline validation message. Errors are announced to screen readers.
 */
export function Field({ label, error, hint, id, className, ...rest }: FieldProps) {
    const inputId = id ?? rest.name;
    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <label htmlFor={inputId} className="text-xs font-medium text-ink">
                {label}
            </label>
            <input
                id={inputId}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${inputId}-error` : undefined}
                className={cn(
                    'min-h-10 rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:border-teal focus:ring-2 focus:ring-mint-2',
                    error ? 'border-status-danger' : 'border-[#CBD5D2]',
                )}
                {...rest}
            />
            {error ? (
                <p id={`${inputId}-error`} role="alert" className="text-xs text-status-danger">
                    {error}
                </p>
            ) : (
                hint && <p className="text-xs text-muted">{hint}</p>
            )}
        </div>
    );
}

export function Checkbox({ label, checked, onChange, error, name }: { label: ReactNode; checked: boolean; onChange: (v: boolean) => void; error?: string; name: string }) {
    return (
        <div>
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" name={name} checked={checked} onChange={(e) => onChange(e.target.checked)} className="size-4 accent-teal" />
                {label}
            </label>
            {error && (
                <p role="alert" className="mt-1 text-xs text-status-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
