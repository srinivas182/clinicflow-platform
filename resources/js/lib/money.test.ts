import { describe, expect, it } from 'vitest';
import { rand } from './money';

describe('rand', () => {
    it('groups thousands with spaces', () => {
        expect(rand(2990)).toBe('R2 990');
        expect(rand(1234567.5, 2)).toBe('R1 234 567.50');
        expect(rand(490)).toBe('R490');
    });
});
