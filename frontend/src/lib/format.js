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

export const INQUIRY_TYPES = {
  question: 'Question',
  availability: 'Availability',
  negotiation: 'Price offer',
  custom_request: 'Custom request',
}

export const QUOTE_STATUS_LABELS = {
  pending: 'Waiting for your answer',
  accepted: 'Accepted',
  declined: 'Declined',
  withdrawn: 'Withdrawn',
  expired: 'Expired',
}

/**
 * Short relative time for message lists: "now", "5 min", "3 h", or a date.
 */
export function timeAgo(iso) {
  if (!iso) return ''
  const diff = (Date.now() - new Date(iso).getTime()) / 1000
  if (diff < 60) return 'now'
  if (diff < 3600) return `${Math.floor(diff / 60)} min`
  if (diff < 86400) return `${Math.floor(diff / 3600)} h`
  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}

/**
 * Convert a santim integer to the birr string the API accepts, e.g. 150050 -> "1500.50".
 */
export function santimToBirr(santim) {
  if (santim === null || santim === undefined) return ''
  const n = Math.round(Number(santim))
  return `${Math.floor(n / 100)}.${String(n % 100).padStart(2, '0')}`
}
