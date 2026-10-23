import { useEffect, useMemo, useState } from 'react'
import { Check, Package, Search } from 'lucide-react'
import { api } from '../lib/api'
import { orderStatusLabel, timeAgo } from '../lib/format'
import { useFetch } from '../lib/useFetch'
import { useRecentlyViewed } from '../lib/useRecent'
import { ProductImage } from './ProductCard'

/** Topics about one piece of furniture, and those about an order. The rest need neither. */
export const ITEM_TOPICS = ['product', 'availability', 'price', 'custom', 'delivery']
export const ORDER_TOPICS = ['order', 'return', 'refund']
/** For these the support team cannot answer without knowing which item or order, so a choice is asked for. */
export const CHOICE_NEEDED = ['product', 'availability', 'price', 'order', 'return', 'refund']

const asOption = (p, store) => p && p.slug ? { slug: p.slug, name: p.name, image: p.image, store: store ?? p.seller?.store_name ?? null } : null

function Row({ selected, onSelect, image, icon: Icon, title, detail }) {
  return (
    <li>
      <button type="button" role="radio" aria-checked={selected} onClick={onSelect}
        className={`flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-left text-sm ${selected ? 'border-brand-700 bg-brand-50 ring-1 ring-brand-700' : 'border-stone-200 bg-white hover:border-stone-300'}`}>
        {Icon ? (
          <span className="grid size-10 shrink-0 place-items-center rounded-md bg-sand-100 text-stone-500"><Icon className="size-5" aria-hidden="true" /></span>
        ) : image !== undefined ? (
          <ProductImage src={image} alt="" className="size-10 shrink-0 rounded-md" />
        ) : null}
        <span className="min-w-0 flex-1">
          <span className="block truncate font-medium text-stone-900">{title}</span>
          {detail && <span className="block truncate text-xs text-stone-500">{detail}</span>}
        </span>
        {selected && <Check className="size-4 shrink-0 text-brand-700" aria-hidden="true" />}
      </button>
    </li>
  )
}

/** Furniture the customer has in the cart, saved or looked at, plus a search over all listings. */
function ItemChoices({ value, onChange }) {
  const cart = useFetch('/cart')
  const saved = useFetch('/favorites')
  const recent = useRecentlyViewed()
  const [term, setTerm] = useState('')
  const [found, setFound] = useState({ term: '', items: [] })

  const mine = useMemo(() => {
    const list = [
      ...(cart.data?.data.groups || []).flatMap((g) => g.lines.map((l) => asOption(l.product, g.seller?.store_name))),
      ...(saved.data?.data || []).map((p) => asOption(p)),
      ...recent.map((p) => asOption(p)),
    ].filter(Boolean)
    const seen = new Set()
    return list.filter((o) => !seen.has(o.slug) && seen.add(o.slug)).slice(0, 6)
  }, [cart.data, saved.data, recent])

  useEffect(() => {
    const q = term.trim()
    if (q.length < 2) return undefined
    let live = true
    const timer = setTimeout(() => {
      api.get('/products', { params: { q, per_page: 6 } })
        .then((res) => live && setFound({ term: q, items: res.data.data.map((p) => asOption(p)) }))
        .catch(() => live && setFound({ term: q, items: [] }))
    }, 250)
    return () => { live = false; clearTimeout(timer) }
  }, [term])

  const q = term.trim()
  const results = q.length >= 2 && found.term === q ? found.items : null
  const shown = results ?? mine
  // Keep the chosen item on screen even when a new search no longer lists it.
  const chosen = value?.kind === 'product' && !shown.some((o) => o.slug === value.item.slug) ? [value.item] : []

  return (
    <>
      <div className="relative">
        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
        <input type="search" className="input pl-9" placeholder="Search furniture by name" value={term}
          onChange={(e) => setTerm(e.target.value)} aria-label="Search furniture by name" />
      </div>
      {!results && mine.length > 0 && <p className="text-xs text-stone-500">From your cart, saved items and what you looked at recently:</p>}
      {results && results.length === 0 && <p className="text-sm text-stone-600">No furniture matches "{q}".</p>}
      <ul className="space-y-2" role="radiogroup" aria-label="Furniture">
        {[...chosen, ...shown].map((o) => (
          <Row key={o.slug} selected={value?.kind === 'product' && value.item.slug === o.slug} onSelect={() => onChange({ kind: 'product', item: o })}
            image={o.image} title={o.name} detail={o.store} />
        ))}
      </ul>
    </>
  )
}

function OrderChoices({ value, onChange }) {
  const orders = useFetch('/orders')
  const list = orders.data?.data || []
  if (orders.loading) return <p className="text-sm text-stone-500">Loading your orders...</p>
  if (list.length === 0) return <p className="text-sm text-stone-600">You have no orders yet.</p>
  return (
    <ul className="space-y-2" role="radiogroup" aria-label="Your orders">
      {list.map((o) => (
        <Row key={o.id} selected={value?.kind === 'order' && value.item.id === o.id} onSelect={() => onChange({ kind: 'order', item: o })}
          icon={Package} title={`Order ${o.number} · ${o.seller?.store_name ?? ''}`}
          detail={[(o.items || []).map((i) => i.name).join(', '), orderStatusLabel(o.status, o.fulfillment, 'customer'), timeAgo(o.created_at)].filter(Boolean).join(' · ')} />
      ))}
    </ul>
  )
}

/**
 * "Which item is this about?" on the general Ask Betoch form, so the support team knows the listing,
 * order and store without asking back. `value` is { kind: 'product' | 'order', item } or { kind: 'none' }.
 */
export default function SupportAboutPicker({ topic, value, onChange, error }) {
  const forOrder = ORDER_TOPICS.includes(topic)
  if (!forOrder && !ITEM_TOPICS.includes(topic)) return null
  const needed = CHOICE_NEEDED.includes(topic)

  return (
    <fieldset className="min-w-0 space-y-2" aria-describedby={error ? 'support-about-error' : undefined}>
      <legend className="label">
        {forOrder ? 'Which order is it about?' : 'Which item is it about?'}
        {!needed && <span className="font-normal text-stone-500"> (optional)</span>}
      </legend>
      {forOrder ? <OrderChoices value={value} onChange={onChange} /> : <ItemChoices value={value} onChange={onChange} />}
      <ul role="radiogroup" aria-label="Other choice">
        <Row selected={value?.kind === 'none'} onSelect={() => onChange({ kind: 'none' })}
          title={forOrder ? 'It is not about one of these orders' : 'I cannot find it here'}
          detail={forOrder ? 'Tell us what it is about below.' : 'Describe it below: the name, the store, or paste the link.'} />
      </ul>
      {error && <p id="support-about-error" className="text-sm font-medium text-red-700">{error}</p>}
    </fieldset>
  )
}
