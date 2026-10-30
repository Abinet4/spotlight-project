import { Link } from 'react-router-dom'
import { QUOTE_STATUS_LABELS, formatETB } from '../lib/format'

const shortDate = (iso) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })

/**
 * An earlier price offer, from Betoch Support or a quote a store sent in an old chat. Betoch now sells at
 * listing prices only, so offers are shown for the record and can no longer be answered or ordered.
 */
export default function OfferCard({ quote, noun = 'offer' }) {
  const tone = quote.status === 'accepted' ? 'border-emerald-300 bg-emerald-50' : 'border-stone-200 bg-stone-50'
  const label = noun === 'offer' ? 'Offer' : 'Quote'

  return (
    <div className={`rounded-xl border-2 p-4 text-sm ${tone}`}>
      <div className="flex items-start justify-between gap-3">
        <p className="font-semibold text-stone-900">{label}: {quote.title}</p>
        <span className="shrink-0 text-xs font-semibold text-stone-600">{QUOTE_STATUS_LABELS[quote.status]}</span>
      </div>
      {quote.store && <p className="text-xs text-stone-500">Made and delivered by {quote.store.store_name}</p>}
      <p className="mt-1 whitespace-pre-line text-stone-700">{quote.description}</p>
      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
        <div><dt className="text-xs text-stone-500">Price</dt><dd className="font-medium">{formatETB(quote.price_santim)}{quote.quantity > 1 && ` x ${quote.quantity}`}</dd></div>
        <div><dt className="text-xs text-stone-500">Delivery</dt><dd className="font-medium">{quote.delivery_fee_santim === null ? 'Priced at checkout' : quote.delivery_fee_santim === 0 ? 'Free' : formatETB(quote.delivery_fee_santim)}</dd></div>
        <div><dt className="text-xs text-stone-500">Total</dt><dd className="font-bold text-stone-900">{formatETB(quote.total_santim)}</dd></div>
        <div><dt className="text-xs text-stone-500">Valid until</dt><dd className="font-medium">{shortDate(`${quote.valid_until}T00:00:00`)}</dd></div>
      </dl>
      {quote.ordered
        ? <p className="mt-2 text-emerald-800">Ordered. Track it under <Link to="/orders" className="font-medium underline">My orders</Link>.</p>
        : <p className="mt-2 text-stone-600">Betoch now sells at the listing price only, so this offer can no longer be ordered.</p>}
    </div>
  )
}
