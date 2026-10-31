const shortDay = (iso) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'Africa/Addis_Ababa' })

/**
 * What a listing, or one of its options, costs now and what it cost before the store's sale.
 * `was` and `percent` are null when there is no sale.
 */
export function priceOf(product, variant = null) {
  const item = variant ?? product
  const was = item.price_santim
  const now = item.price_now_santim ?? was
  const onSale = now < was
  return { now, was: onSale ? was : null, percent: onSale ? product.discount?.percent ?? null : null, endsAt: onSale ? product.discount?.ends_at ?? null : null }
}

/** "15% off" with the sale's last day when it has one, for the product page and quick view. */
export function saleNote(product) {
  const d = product.discount
  if (!d) return null
  return d.ends_at ? `${d.percent}% off until ${shortDay(d.ends_at)}` : `${d.percent}% off`
}
