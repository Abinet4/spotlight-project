const whole = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 })
const cents = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/**
 * Format a price given in santim (integer) as "ETB 12,500" or "ETB 12,500.50".
 */
export function formatETB(santim) {
  if (santim === null || santim === undefined || Number.isNaN(Number(santim))) return ''
  const n = Math.round(Number(santim))
  // Show decimals only when there are santim, and never "12,500.5".
  return `ETB ${n % 100 === 0 ? whole.format(n / 100) : cents.format(n / 100)}`
}

export function formatDimensions(d) {
  if (!d) return ''
  const parts = [d.width_cm, d.depth_cm, d.height_cm]
  if (parts.every((p) => p == null)) return ''
  return `${parts.map((p) => (p == null ? '?' : p)).join(' x ')} cm (W x D x H)`
}

export const CONDITION_LABELS = { new: 'New', used: 'Used', refurbished: 'Refurbished' }

export const STATUS_LABELS = {
  draft: 'Draft',
  pending_review: 'In review',
  published: 'Published',
  rejected: 'Rejected',
  archived: 'Archived',
  pending: 'Pending approval',
  approved: 'Approved',
  suspended: 'Suspended',
}

export const BUSINESS_TYPES = {
  shop: 'Furniture shop',
  manufacturer: 'Manufacturer',
  workshop: 'Workshop',
  individual: 'Individual seller',
}

/**
 * Build a clean query string, dropping empty values.
 */
export function toQuery(params) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== '' && v !== null && v !== undefined && v !== false) q.set(k, v === true ? '1' : String(v))
  }
  return q.toString()
}
