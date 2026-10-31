import { formatETB } from '../lib/format'
import { priceOf } from '../lib/price'

const SIZES = {
  card: ['text-[15px] sm:text-base', 'text-xs'],
  quick: ['text-2xl', 'text-sm'],
  page: ['text-3xl tracking-tight sm:text-4xl', 'text-base'],
}

/** The price now in bold, and the price before the sale struck through beside it. */
export default function Price({ product, variant = null, size = 'card', multiply = 1, className = '' }) {
  const { now, was } = priceOf(product, variant)
  const [big, small] = SIZES[size]
  return (
    <span className={`inline-flex flex-wrap items-baseline gap-x-2 ${className}`}>
      <span className={`font-bold whitespace-nowrap ${was ? 'text-red-700' : 'text-stone-900'} ${big}`}>
        {was && <span className="sr-only">Now </span>}{formatETB(now * multiply)}
      </span>
      {was && <s className={`whitespace-nowrap text-stone-500 ${small}`}><span className="sr-only">was </span>{formatETB(was * multiply)}</s>}
    </span>
  )
}
