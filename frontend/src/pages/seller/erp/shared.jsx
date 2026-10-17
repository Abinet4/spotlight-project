import { Download } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, parseError } from '../../../lib/api'
import { formatETB, toQuery } from '../../../lib/format'

export const money = (s) => (s === null || s === undefined ? 'Not set' : formatETB(s) || 'ETB 0')

export const MOVEMENT_LABELS = {
  opening: 'Starting stock',
  edit: 'Changed on listing',
  count: 'Stock count',
  damaged: 'Damaged',
  lost: 'Lost',
  returned: 'Returned',
  found: 'Found',
  order_reserved: 'Ordered',
  order_released: 'Order cancelled',
}

/** Adjustments a store records by hand, with what the number means for each. */
export const ADJUSTMENTS = [
  { type: 'count', label: 'Stock count', hint: 'How many you counted at this location. The difference is recorded.' },
  { type: 'damaged', label: 'Damaged', hint: 'Units that can no longer be sold.' },
  { type: 'lost', label: 'Lost or stolen', hint: 'Units that are missing.' },
  { type: 'returned', label: 'Returned by a customer', hint: 'Units back in stock after a return.' },
  { type: 'found', label: 'Found or added', hint: 'Extra units, for example found in a count.' },
]

const STATE = {
  ok: ['In stock', 'bg-emerald-100 text-emerald-800'],
  low: ['Low stock', 'bg-amber-100 text-amber-800'],
  out: ['Out of stock', 'bg-red-100 text-red-800'],
  made_to_order: ['Made to order', 'bg-stone-200 text-stone-700'],
}

export function StockBadge({ state }) {
  const [label, tone] = STATE[state] || [state, 'bg-stone-100 text-stone-700']
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${tone}`}>{label}</span>
}

/** "+3" or "-2" with a color; 0 for a count that matched. */
export function Change({ n }) {
  return <span className={`font-semibold tabular-nums ${n > 0 ? 'text-emerald-700' : n < 0 ? 'text-red-700' : 'text-stone-500'}`}>{n > 0 ? `+${n}` : n}</span>
}

const PERIODS = [['today', 'Today'], ['7d', '7 days'], ['30d', '30 days'], ['custom', 'Custom']]

/**
 * Today / 7 days / 30 days / custom dates, kept in the address bar so a refresh or a shared link
 * shows the same range. Returns the query string for the API.
 */
export function usePeriod(defaultPeriod = '30d') {
  const [params, setParams] = useSearchParams()
  const period = params.get('period') || defaultPeriod
  const from = params.get('from') || ''
  const to = params.get('to') || ''
  const query = toQuery(period === 'custom' ? { period, from, to } : { period })
  const set = (next) => setParams(toQuery({ ...Object.fromEntries(params), ...next }), { replace: true })
  return { period, from, to, query, set }
}

export function PeriodPicker({ value }) {
  const [form, setForm] = useState({ from: value.from, to: value.to })
  return (
    <div className="flex flex-wrap items-end gap-2">
      <div role="group" aria-label="Period" className="inline-flex rounded-xl border border-stone-300 bg-white p-0.5">
        {PERIODS.map(([key, label]) => (
          <button key={key} type="button" aria-pressed={value.period === key}
            onClick={() => value.set({ period: key, ...(key === 'custom' ? form : { from: '', to: '' }) })}
            className={`rounded-lg px-3 py-1.5 text-sm ${value.period === key ? 'bg-brand-700 font-semibold text-white' : 'text-stone-700 hover:bg-stone-100'}`}>
            {label}
          </button>
        ))}
      </div>
      {value.period === 'custom' && (
        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); value.set({ period: 'custom', ...form }) }}>
          <label className="text-sm">From<input type="date" className="input mt-1 w-auto" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} /></label>
          <label className="text-sm">To<input type="date" className="input mt-1 w-auto" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} /></label>
          <button type="submit" className="btn-secondary">Show</button>
        </form>
      )}
    </div>
  )
}

/** Download a CSV from the API (it needs the sign-in token, so a plain link would not work). */
export function CsvButton({ url, filename, label = 'Download CSV' }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  async function download() {
    setBusy(true)
    setError(null)
    try {
      const r = await api.get(url, { responseType: 'blob' })
      const href = URL.createObjectURL(r.data)
      const a = document.createElement('a')
      a.href = href
      a.download = filename
      a.click()
      URL.revokeObjectURL(href)
    } catch (err) {
      setError(parseError(err).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <span className="inline-flex flex-col">
      <button type="button" className="btn-secondary" onClick={download} disabled={busy}>
        <Download className="size-4" aria-hidden="true" />{busy ? 'Preparing...' : label}
      </button>
      {error && <span role="alert" className="mt-1 text-xs text-red-700">{error}</span>}
    </span>
  )
}

/**
 * Simple bar chart of one value per day, with the numbers available to screen readers as a table.
 * `days`: [{ date, value }].
 */
export function DayBars({ days, format = (v) => v, label }) {
  const max = Math.max(1, ...days.map((d) => d.value))
  const total = days.reduce((n, d) => n + d.value, 0)
  if (total === 0) return <p className="py-6 text-center text-sm text-stone-500">Nothing in this period yet.</p>
  return (
    <figure>
      <div className="flex h-36 items-end gap-px" aria-hidden="true">
        {days.map((d) => (
          <div key={d.date} className="group relative flex h-full flex-1 items-end">
            <div className="w-full rounded-t bg-brand-600 group-hover:bg-brand-800" style={{ height: `${Math.max(d.value ? 3 : 0, (100 * d.value) / max)}%` }} />
            <span className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded bg-stone-900 px-2 py-1 text-xs whitespace-nowrap text-white group-hover:block">
              {d.date}: {format(d.value)}
            </span>
          </div>
        ))}
      </div>
      <div className="mt-1 flex justify-between text-xs text-stone-500" aria-hidden="true"><span>{days[0]?.date}</span><span>{days[days.length - 1]?.date}</span></div>
      <table className="sr-only">
        <caption>{label}</caption>
        <tbody>{days.filter((d) => d.value).map((d) => <tr key={d.date}><th scope="row">{d.date}</th><td>{format(d.value)}</td></tr>)}</tbody>
      </table>
    </figure>
  )
}
