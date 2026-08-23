/**
 * Shared formatting helpers, so dates and counts read the same everywhere.
 */

/** "2026-08-31 · in 8 days", matching the round dashboard. */
export function formatDeadline(iso) {
  if (!iso) return 'No deadline'

  const date = new Date(iso)
  const dateText = date.toISOString().slice(0, 10)
  const days = Math.ceil((date - new Date()) / 86400000)

  if (days < 0) return `${dateText} · closed`
  if (days === 0) return `${dateText} · closes today`

  return `${dateText} · in ${days} day${days === 1 ? '' : 's'}`
}

export function formatDate(iso) {
  return iso ? new Date(iso).toISOString().slice(0, 10) : '—'
}

export function formatNumber(value) {
  return typeof value === 'number' ? value.toLocaleString() : '0'
}

/** Pixel counts read better as megapixels once they get large. */
export function formatPixels(pixels) {
  if (!pixels) return '—'
  if (pixels >= 1_000_000) return `${(pixels / 1_000_000).toFixed(1)} MP`

  return formatNumber(pixels)
}
