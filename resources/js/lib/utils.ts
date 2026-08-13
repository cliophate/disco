import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';
import type { PartialDate } from './types';

const dateFormatters = new Map<string, Intl.DateTimeFormat>();
const partialMonthFormatter = new Intl.DateTimeFormat(undefined, { month: 'short', timeZone: 'UTC' });

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function formatDuration(milliseconds: number | null) {
    if (milliseconds === null) return null;
    const totalSeconds = Math.max(0, Math.round(milliseconds / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

export function titleCase(value: string) {
    return value.replaceAll('_', ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

export function requiresTextContainment(value: string) {
    const characters = Array.from(value).length;
    const marks = value.match(/\p{Mark}/gu)?.length ?? 0;

    return characters > 120 || marks >= 12;
}

export function formatLongDuration(milliseconds: number | null) {
    if (!milliseconds) return null;
    const minutes = Math.round(milliseconds / 60_000);
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return hours ? `${hours} hr ${remainder} min` : `${minutes} min`;
}

export function formatDate(value: string | null, options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' }) {
    if (!value) return null;
    const date = new Date(value);
    const key = JSON.stringify(options);
    const formatter = dateFormatters.get(key) ?? new Intl.DateTimeFormat(undefined, options);
    dateFormatters.set(key, formatter);

    return Number.isNaN(date.getTime()) ? value : formatter.format(date);
}

export function formatPartialDate(value: PartialDate | null) {
    if (!value) return null;
    if (value.precision === 'year' || value.month === null) return String(value.year);
    const month = partialMonthFormatter.format(new Date(Date.UTC(2000, value.month - 1, 1)));
    if (value.precision === 'month' || value.day === null) return `${month} ${value.year}`;
    return `${value.day} ${month} ${value.year}`;
}
