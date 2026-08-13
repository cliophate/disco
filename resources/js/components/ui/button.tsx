import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import type { ButtonHTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

const buttonVariants = cva(
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-4 text-sm font-semibold transition-colors outline-none focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2 focus-visible:ring-offset-paper disabled:pointer-events-none disabled:opacity-45',
    {
        variants: {
            variant: {
                default: 'bg-cobalt text-cream hover:bg-cobalt-deep',
                secondary: 'border border-line bg-panel text-ink hover:border-cobalt hover:text-cobalt',
                ghost: 'text-fog hover:bg-raised hover:text-ink',
                danger: 'bg-coral text-cream hover:bg-coral-deep',
            },
            size: {
                default: 'h-11 px-4',
                sm: 'h-11 px-3 text-xs',
                icon: 'size-11 p-0',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
}

export function Button({ className, variant, size, asChild, ...props }: ButtonProps) {
    const Component = asChild ? Slot : 'button';
    return <Component className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
