import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AttributedNarrative } from './attributed-narrative';

describe('AttributedNarrative', () => {
    it('renders readable text with source and licence attribution', () => {
        render(<AttributedNarrative
            description={{
                text: 'First paragraph.\n\nSecond paragraph.',
                language: 'fr',
                provider: 'wikipedia',
                provider_name: 'Wikipedia',
                source_url: 'https://fr.wikipedia.org/wiki/Fixture',
                license_name: 'CC BY-SA 4.0',
                license_url: 'https://creativecommons.org/licenses/by-sa/4.0/',
            }}
            eyebrow="Artist context"
            title="About Fixture Artist"
            titleId="artist-description-title"
        />);

        expect(screen.getByRole('heading', { name: 'About Fixture Artist' })).toBeVisible();
        expect(screen.getByText(/First paragraph/)).toHaveClass('whitespace-pre-line', 'leading-7');
        expect(screen.getByRole('link', { name: 'Wikipedia' })).toHaveAttribute('href', 'https://fr.wikipedia.org/wiki/Fixture');
        expect(screen.getByRole('link', { name: 'CC BY-SA 4.0' })).toHaveAttribute('href', 'https://creativecommons.org/licenses/by-sa/4.0/');
        expect(screen.getByRole('link', { name: 'Wikipedia' }).parentElement).toHaveTextContent('FR');
    });

    it('renders no empty section when a narrative is unavailable', () => {
        const { container } = render(<AttributedNarrative description={null} eyebrow="Artist context" title="About Fixture Artist" titleId="artist-description-title" />);

        expect(container).toBeEmptyDOMElement();
    });
});
