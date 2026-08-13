import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { Disc3 } from 'lucide-react';
import { MemoryRouter } from 'react-router-dom';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CollectionStat } from './collection-stat';
import { CoverCard } from './cover-card';
import { EditorialHeader } from './editorial-header';
import { EditorialTile } from './editorial-tile';
import { EntityPortraitLink } from './entity-portrait-link';
import { FactList } from './fact-list';
import { FilterBar } from './filter-bar';

afterEach(cleanup);

describe('EditorialHeader', () => {
    it('renders one display heading and safely wraps an unbroken title', () => {
        render(
            <EditorialHeader
                eyebrow="Album file"
                title="AnExtremelyLongAlbumTitleWithoutAnyNaturalBreakOpportunities"
                identity={<a href="/artists/1">The Artist</a>}
                actions={<button type="button">Open in Plex</button>}
            />,
        );

        expect(screen.getByRole('heading', { level: 1 })).toHaveClass('break-words', '[overflow-wrap:anywhere]');
        expect(screen.getByRole('link', { name: 'The Artist' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Open in Plex' })).toBeVisible();
    });

    it('uses the split title and description layout by default', () => {
        render(<EditorialHeader eyebrow="Collection" title="Album library" description="Browse the complete collection." />);

        const heading = screen.getByRole('heading', { name: 'Album library' });
        const description = screen.getByText('Browse the complete collection.');
        expect(heading.closest('header')).toHaveClass('md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]');
        expect(description.parentElement).not.toBe(heading.parentElement);
    });
});

describe('FactList', () => {
    it('uses a definition list while omitting sparse values and retaining zero', () => {
        const { container } = render(<FactList label="Release facts" facts={[
            { label: 'Released', value: null },
            { label: 'Label', value: '   ' },
            { label: 'Tracks', value: 0 },
            { label: 'Format', value: 'Album' },
        ]} />);

        const list = container.querySelector('dl');
        expect(list).not.toBeNull();
        if (!list) return;
        expect(within(list).queryByText('Released')).not.toBeInTheDocument();
        expect(within(list).queryByText('Label')).not.toBeInTheDocument();
        expect(within(list).getByText('Tracks').tagName).toBe('DT');
        expect(within(list).getByText('0').tagName).toBe('DD');
    });

    it('renders no empty definition list unless an empty state is supplied', () => {
        const { container, rerender } = render(<FactList facts={[{ label: 'Area', value: null }]} />);
        expect(container.querySelector('dl')).not.toBeInTheDocument();

        rerender(<FactList facts={[]} empty="No facts available" />);
        expect(screen.getByText('No facts available')).toBeVisible();
        expect(container.querySelector('dl')).not.toBeInTheDocument();
    });
});

describe('FilterBar', () => {
    it('labels pressed count filters and its optional native sort control', () => {
        const onFilterChange = vi.fn();
        const onSortChange = vi.fn();
        render(
            <FilterBar
                label="Release type"
                filters={[
                    { id: 'albums', label: 'Albums', count: 12 },
                    { id: 'singles', label: 'Singles', count: 3 },
                ]}
                selected="albums"
                onFilterChange={onFilterChange}
                tabs={<nav aria-label="Catalog views">Catalog tabs</nav>}
                controls={<button type="button">Shuffle again</button>}
                disclosure={<button type="button">More filters</button>}
                sort={{
                    label: 'Sort by',
                    value: 'newest',
                    options: [{ value: 'newest', label: 'Newest' }, { value: 'title', label: 'Title' }],
                    onChange: onSortChange,
                }}
            />,
        );

        expect(screen.getByRole('group', { name: 'Release type' })).toBeVisible();
        expect(screen.getByRole('navigation', { name: 'Catalog views' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Shuffle again' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'More filters' })).toBeVisible();
        expect(screen.getByRole('group', { name: 'Release type' }).parentElement?.parentElement).toHaveClass('border-b');
        expect(screen.getByRole('group', { name: 'Release type' }).parentElement?.parentElement).not.toHaveClass('border-y');
        expect(screen.getByRole('button', { name: /Albums.*12/ })).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(screen.getByRole('button', { name: /Singles.*3/ }));
        expect(onFilterChange).toHaveBeenCalledWith('singles');
        fireEvent.change(screen.getByRole('combobox', { name: 'Sort by' }), { target: { value: 'title' } });
        expect(onSortChange).toHaveBeenCalledWith('title');
    });
});

describe('CoverCard', () => {
    it('keeps the canonical link separate from its action and handles long fallback titles', () => {
        const title = 'ACompletelyUnbrokenAlbumTitleThatMustNotWidenThePage';
        render(
            <MemoryRouter>
                <CoverCard to="/albums/album-1" title={title} artwork={null} artist="Artist" variant="compact" action={<button type="button">Remove</button>} />
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: new RegExp(title) })).toHaveAttribute('href', '/albums/album-1');
        expect(screen.getByRole('heading', { name: title })).toHaveClass('break-words', '[overflow-wrap:anywhere]');
        expect(screen.getByRole('button', { name: 'Remove' }).closest('a')).toBeNull();
        expect(screen.getByTestId('artwork-fallback')).toHaveAccessibleName(`${title} by Artist artwork unavailable`);
    });

    it('falls back when supplied artwork fails', () => {
        render(
            <MemoryRouter>
                <CoverCard to="/albums/album-2" title="Failed cover" artwork={{ id: 'art-2', url: '/failed.jpg', width: 600, height: 600 }} />
            </MemoryRouter>,
        );

        fireEvent.error(screen.getByRole('img', { name: 'Failed cover artwork' }));
        expect(screen.getByRole('img', { name: 'Failed cover artwork unavailable' })).toBeVisible();
    });
});

describe('EditorialTile', () => {
    it('keeps source attribution distinct from its canonical title link', () => {
        render(
            <MemoryRouter>
                <EditorialTile
                    to="/albums/album-3"
                    title="An editorial selection"
                    excerpt="A concise attributed excerpt."
                    attribution={{ label: 'MusicBrainz', href: 'https://musicbrainz.org' }}
                />
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: 'An editorial selection' })).toHaveAttribute('href', '/albums/album-3');
        const source = screen.getByRole('link', { name: 'MusicBrainz' });
        expect(source).toHaveAttribute('rel', 'noreferrer');
        expect(source.closest('a')?.querySelector('a')).toBeNull();
    });
});

describe('EntityPortraitLink', () => {
    it('uses ArtistPortrait and preserves its failure fallback inside one canonical link', () => {
        render(
            <MemoryRouter>
                <EntityPortraitLink to="/artists/artist-1" name="An Artist" portrait={{ id: 'portrait-1', url: '/failed-portrait.jpg', width: 400, height: 400 }} detail="Dublin, Ireland" />
            </MemoryRouter>,
        );

        fireEvent.error(screen.getByRole('img', { name: 'An Artist portrait' }));
        expect(screen.getByRole('img', { name: 'An Artist portrait unavailable' })).toBeVisible();
        expect(screen.getByRole('link', { name: /An Artist/ })).toHaveAttribute('href', '/artists/artist-1');
    });
});

describe('CollectionStat', () => {
    it('formats numeric values and hides decorative icons from accessibility APIs', () => {
        const { container } = render(<CollectionStat label="Albums" value={1234} icon={<Disc3 data-testid="stat-icon" />} />);

        expect(screen.getByText((1234).toLocaleString())).toBeVisible();
        expect(screen.getByRole('group', { name: 'Albums' })).toBeVisible();
        expect(within(container).getByText('Albums')).toBeVisible();
        expect(container.querySelector('[aria-hidden="true"]')).toContainElement(screen.getByTestId('stat-icon'));
    });
});
