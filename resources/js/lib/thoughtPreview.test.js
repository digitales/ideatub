import { describe, expect, it } from 'vitest';
import { shouldShowReadMoreToggle } from './thoughtPreview.js';

describe('shouldShowReadMoreToggle', () => {
  it('returns false when heights are equal (short content, no clamp overflow)', () => {
    expect(shouldShowReadMoreToggle(120, 120)).toBe(false);
    expect(shouldShowReadMoreToggle(100, 100)).toBe(false);
  });

  it('returns false when scroll height is less than client height', () => {
    expect(shouldShowReadMoreToggle(80, 100)).toBe(false);
  });

  it('returns false for non-finite values', () => {
    expect(shouldShowReadMoreToggle(NaN, 100)).toBe(false);
    expect(shouldShowReadMoreToggle(100, NaN)).toBe(false);
    expect(shouldShowReadMoreToggle(Infinity, 100)).toBe(false);
  });

  it('returns true when scroll height exceeds client height beyond epsilon', () => {
    expect(shouldShowReadMoreToggle(200, 100)).toBe(true);
    expect(shouldShowReadMoreToggle(102, 100)).toBe(true);
  });

  it('returns false when difference is within default epsilon', () => {
    expect(shouldShowReadMoreToggle(100.5, 100)).toBe(false);
  });

  it('respects custom epsilon', () => {
    expect(shouldShowReadMoreToggle(101, 100, 2)).toBe(false);
    expect(shouldShowReadMoreToggle(103, 100, 2)).toBe(true);
  });
});
