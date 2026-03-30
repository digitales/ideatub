/**
 * Whether the main thought text region is visually truncated (e.g. line-clamped)
 * and should expose a read-more control.
 *
 * @param {number} scrollHeight
 * @param {number} clientHeight
 * @param {number} [epsilon=1] Sub-pixel tolerance
 * @returns {boolean}
 */
export function shouldShowReadMoreToggle(scrollHeight, clientHeight, epsilon = 1) {
  if (!Number.isFinite(scrollHeight) || !Number.isFinite(clientHeight)) {
    return false;
  }
  return scrollHeight > clientHeight + epsilon;
}
