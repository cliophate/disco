import type { InputHTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            className={cn('h-11 w-full rounded-full border border-line bg-panel px-4 text-sm text-ink outline-none transition placeholder:text-fog focus:border-cobalt focus:ring-2 focus:ring-cobalt/20 disabled:opacity-50', className)}
            {...props}
        />
    );
}
