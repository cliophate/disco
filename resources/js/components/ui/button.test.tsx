import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Button } from './button';

describe('Button', () => {
    it('retains native accessible button behavior', () => {
        render(<Button disabled>Open record</Button>);
        expect(screen.getByRole('button', { name: 'Open record' })).toBeDisabled();
    });
});
