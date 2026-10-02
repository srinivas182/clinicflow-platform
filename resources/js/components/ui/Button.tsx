import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

type Variant = 'primary' | 'secondary' | 'network' | 'danger' | 'ghost';
type Size = 'sm' | 'md' | 'lg';

const variants: Record<Variant, string> = {
    primary: 'bg-teal text-white border-teal hover:bg-teal-deep',
    secondary: 'bg-white text-ink border-line hover:border-muted',
    network: 'bg-jacaranda text-white border-jacaranda hover:bg-jacaranda-deep',
    danger: 'bg-status-danger text-white border-status-danger',
    ghost: 'bg-transparent text-teal-deep border-transparent hover:bg-mint',
};

const sizes: Record<Size, string> = {
    sm: 'px-2.5 py-1.5 text-xs',
    md: 'px-3.5 py-2 text-sm',
    lg: 'px-5 py-2.5 text-base',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    size?: Size;
    icon?: ReactNode;
}

export function Button({ variant = 'primary', size = 'md', icon, className, children, type = 'button', ...rest }: ButtonProps) {
    return (
        <button
            type={type}
            className={cn(
                'inline-flex items-center gap-2 rounded-lg border font-medium whitespace-nowrap transition-colors',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal disabled:opacity-45',
                variants[variant],
                sizes[size],
                className,
            )}
            {...rest}
        >
            {icon}
            {children}
        </button>
    );
}
