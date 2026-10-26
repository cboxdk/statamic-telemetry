import { describe, it, expect, beforeEach } from 'vitest';
import { count, ms, percent, clock, dateClock, timeLabel, setTimeZoneChoice, timeZoneChoice, timeZoneLabel } from '../../resources/js/lib/format.js';

describe('numbers', () => {
    it('abbreviates counts', () => {
        expect(count(0)).toBe('0');
        expect(count(999)).toBe('999');
        expect(count(1200)).toBe('1.2k');
        expect(count(2_000_000)).toBe('2M');
        expect(count(null)).toBe('—');
    });

    it('formats durations by magnitude', () => {
        expect(ms(0.4)).toBe('400 µs');
        expect(ms(9.96)).toBe('10.0 ms');
        expect(ms(243)).toBe('243 ms');
        expect(ms(1234)).toBe('1.23 s');
        expect(ms(undefined)).toBe('—');
    });

    it('keeps a decimal on small percentages only', () => {
        expect(percent(0.0042)).toBe('0.4%');
        expect(percent(0.456)).toBe('46%');
    });
});

describe('time zone', () => {
    beforeEach(() => window.localStorage.clear());

    it('defaults to the browser zone and persists a UTC choice', () => {
        expect(timeZoneChoice()).toBe('local');
        setTimeZoneChoice('UTC');
        expect(timeZoneChoice()).toBe('UTC');
        expect(timeZoneLabel()).toBe('UTC');
    });

    it('formats every clock in the chosen zone', () => {
        setTimeZoneChoice('UTC');
        const noon = Date.UTC(2026, 9, 25, 12, 5);
        expect(clock(noon)).toMatch(/12:05/);
        expect(dateClock(noon)).toMatch(/25 Oct.*12:05|Oct 25.*12:05/);
    });

    it('adds the date only when the span is longer than a day and a half', () => {
        setTimeZoneChoice('UTC');
        const t = Date.UTC(2026, 9, 25, 12, 5);
        expect(timeLabel(t, 6 * 3600e3)).toBe(clock(t));
        expect(timeLabel(t, 3 * 86400e3)).toBe(dateClock(t));
    });
});
