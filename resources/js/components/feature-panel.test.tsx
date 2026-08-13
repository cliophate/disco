import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import type { Recommendation } from '../lib/types';
import { FeaturePanel } from './feature-panel';

describe('FeaturePanel', () => {
    it('presents the daily lens and reason without provider pills', () => {
        const recommendation = {
            album: {
                id: 'album-1', title: 'AVeryLongAlbumTitleWithoutBreakOpportunitiesThatMustStillWrap', artist: null, artwork: null,
                first_release_date: null, year: 2026,
            },
            reasons: [{ code: 'recently_added', text: 'Added to the library recently.', source: 'plex' }],
            lens: 'Latest additions',
        } as Recommendation;

        render(<MemoryRouter><FeaturePanel recommendation={recommendation} /></MemoryRouter>);

        expect(screen.getByText('Latest additions')).toHaveClass('uppercase');
        expect(screen.getByRole('heading', { level: 1 })).toHaveClass('break-words');
        expect(screen.getByText('Added to the library recently.')).toBeVisible();
        expect(screen.queryByText('plex')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Explore the album' })).toHaveAttribute('href', '/albums/album-1');
        expect(screen.queryByRole('button', { name: 'Open in Plex' })).not.toBeInTheDocument();
        expect(screen.getByTestId('artwork-fallback')).toHaveAccessibleName(/artwork unavailable/i);
    });
});
