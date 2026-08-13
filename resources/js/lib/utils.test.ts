import { describe, expect, it } from 'vitest';
import { cn, formatDuration, formatPartialDate, requiresTextContainment, titleCase } from './utils';

describe('frontend utilities', () => {
    it('formats track durations and rounds milliseconds', () => {
        expect(formatDuration(65_499)).toBe('1:05');
        expect(formatDuration(0)).toBe('0:00');
    });

    it('turns metadata keys into labels', () => {
        expect(titleCase('missing_artwork')).toBe('Missing Artwork');
    });

    it('resolves conflicting Tailwind classes', () => {
        expect(cn('px-2 text-sm', 'px-4')).toContain('px-4');
        expect(cn('px-2 text-sm', 'px-4')).not.toContain('px-2');
    });

    it('preserves MusicBrainz date precision without inventing a day', () => {
        expect(formatPartialDate({ year: 2021, month: null, day: null, precision: 'year' })).toBe('2021');
        expect(formatPartialDate({ year: 2021, month: 9, day: null, precision: 'month' })).toMatch(/Sep 2021/);
    });

    it('contains unusually long or combining-mark-heavy display text', () => {
        expect(requiresTextContainment('A normal album title')).toBe(false);
        expect(requiresTextContainment('x'.repeat(121))).toBe(true);
        expect(requiresTextContainment(`x${'\u035c'.repeat(12)}`)).toBe(true);
    });
});
