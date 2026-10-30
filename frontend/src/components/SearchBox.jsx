import { Search } from 'lucide-react'
import { useEffect, useId, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api } from '../lib/api'
import { categoryIcon, useCategories } from '../lib/categories'
import { formatETB } from '../lib/format'

// Suggestions already fetched this visit, so typing back and forth does not ask again.
const cache = new Map()

/** The typed text in bold wherever it appears in a suggestion. */
function Highlight({ text, term }) {
  const at = text.toLowerCase().indexOf(term.toLowerCase())
  if (!term || at < 0) return text
  return <>{text.slice(0, at)}<strong className="font-semibold text-stone-900">{text.slice(at, at + term.length)}</strong>{text.slice(at + term.length)}</>
}

/**
 * The site search box. While the customer types it suggests matching categories and listings
 * underneath; arrow keys move through them, Enter opens the chosen one or searches for the text.
 */
export default function SearchBox({ className = '', id = 'site-search', onClose }) {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [q, setQ] = useState(params.get('q') || '')
  // Bumped when a fetched answer arrives, so the render below reads it from the cache.
  const [, setFetched] = useState(0)
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(-1)
  const { data: categoryList } = useCategories()
  const listId = useId()

  useEffect(() => setQ(params.get('q') || ''), [params])

  const term = q.trim()
  const key = term.toLowerCase()
  const products = (term.length >= 2 && cache.get(key)) || []
  useEffect(() => {
    if (term.length < 2 || cache.has(key)) return undefined
    // Wait for a pause in typing before asking the server.
    const timer = setTimeout(() => {
      api.get('/search/suggest', { params: { q: term } })
        .then((res) => {
          cache.set(key, res.data.data)
          setFetched((n) => n + 1)
        })
        .catch(() => {})
    }, 200)
    return () => clearTimeout(timer)
  }, [term, key])

  const needle = term.toLowerCase()
  const categories = term.length < 2 ? []
    : (categoryList || []).flatMap((c) => [c, ...(c.children || [])]).filter((c) => c.name.toLowerCase().includes(needle)).slice(0, 3)

  const options = term.length < 2 ? [] : [
    ...categories.map((c) => ({ key: `c${c.id}`, to: `/category/${c.slug}`, category: c })),
    ...products.map((p) => ({ key: `p${p.slug}`, to: `/products/${p.slug}`, product: p })),
    { key: 'all', to: `/?q=${encodeURIComponent(term)}`, all: true },
  ]
  const showList = open && options.length > 0

  function go(to) {
    setOpen(false)
    setActive(-1)
    navigate(to)
    onClose?.()
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') {
      if (showList) {
        e.stopPropagation()
        setOpen(false)
      } else {
        onClose?.()
      }
    } else if (e.key === 'ArrowDown' && options.length) {
      e.preventDefault()
      setOpen(true)
      setActive((i) => (i + 1) % options.length)
    } else if (e.key === 'ArrowUp' && options.length) {
      e.preventDefault()
      setOpen(true)
      setActive((i) => (i <= 0 ? options.length - 1 : i - 1))
    }
  }

  return (
    <form
      role="search"
      className={className}
      onSubmit={(e) => {
        e.preventDefault()
        if (showList && active >= 0 && options[active]) return go(options[active].to)
        go(term ? `/?q=${encodeURIComponent(term)}` : '/')
      }}
    >
      <label htmlFor={id} className="sr-only">Search furniture</label>
      <div className="relative">
        <input
          id={id}
          type="search"
          value={q}
          onChange={(e) => { setQ(e.target.value); setOpen(true); setActive(-1) }}
          onFocus={() => setOpen(true)}
          onBlur={() => setOpen(false)}
          onKeyDown={onKeyDown}
          className="input rounded-full border-stone-200 bg-stone-100/80 pr-12 pl-4 focus:bg-white"
          maxLength={100}
          autoComplete="off"
          role="combobox"
          aria-expanded={showList}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={showList && active >= 0 ? `${listId}-${active}` : undefined}
          autoFocus
        />
        <button type="submit" className="absolute inset-y-1 right-1 flex aspect-square items-center justify-center rounded-full bg-brand-700 text-white hover:bg-brand-800" aria-label="Search">
          <Search className="size-4" aria-hidden="true" />
        </button>
        {showList && (
          <ul id={listId} role="listbox" aria-label="Suggestions"
            className="absolute top-full right-0 z-40 mt-2 max-h-[70vh] w-full min-w-72 overflow-y-auto rounded-2xl border border-stone-200 bg-white py-1.5 text-sm shadow-lift"
            // Keep focus in the box, so choosing a suggestion is not lost to the input closing the list on blur.
            onMouseDown={(e) => e.preventDefault()}>
            {options.map((o, i) => {
              const Icon = o.category ? categoryIcon(o.category.slug) : Search
              return (
                <li key={o.key} id={`${listId}-${i}`} role="option" aria-selected={i === active}
                  className={`flex cursor-pointer items-center gap-3 px-3 py-2 ${i === active ? 'bg-brand-50' : 'hover:bg-stone-50'} ${o.all && options.length > 1 ? 'mt-1 border-t border-stone-100' : ''}`}
                  onMouseEnter={() => setActive(i)} onClick={() => go(o.to)}>
                  {o.product ? (
                    o.product.image
                      ? <img src={o.product.image} alt="" className="size-10 shrink-0 rounded-lg bg-stone-100 object-cover" />
                      : <span className="size-10 shrink-0 rounded-lg bg-stone-100" />
                  ) : (
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-stone-100 text-stone-500"><Icon className="size-4" aria-hidden="true" /></span>
                  )}
                  <span className="min-w-0 flex-1 text-stone-600">
                    {o.all && <>Search for <strong className="font-semibold text-stone-900">“{term}”</strong></>}
                    {o.category && <><span className="block truncate"><Highlight text={o.category.name} term={term} /></span><span className="block text-xs text-stone-500">Category</span></>}
                    {o.product && <><span className="block truncate"><Highlight text={o.product.name} term={term} /></span><span className="block text-xs text-stone-500">{formatETB(o.product.price_now_santim ?? o.product.price_santim)}</span></>}
                  </span>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </form>
  )
}
