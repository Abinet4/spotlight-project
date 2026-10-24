import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import DownloadButton from '../../components/DownloadButton'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner } from '../../components/ui'
import { formatDateTime, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const FILTERS = ['action', 'q', 'from', 'to']

export default function AdminAudit() {
  const [params, setParams] = useSearchParams()
  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k) || '']))
  const [form, setForm] = useState(filters)
  const { data, error, loading, reload } = useFetch(`/admin/audit-logs?${toQuery({ ...filters, page: params.get('page') || '' })}`)
  const groups = data?.groups || {}
  const apply = (next) => setParams(toQuery(next))

  return (
    <>
      <PageHeader title="Audit log" subtitle="Who did what, in Addis Ababa time. The download holds every row these filters match."
        actions={<DownloadButton path="/admin/audit-logs" query={toQuery(filters)} filename={`audit-log${filters.from ? `-${filters.from}` : ''}${filters.to ? `-to-${filters.to}` : ''}`} />} />
      <form className="mb-4 grid grid-cols-2 items-end gap-2 sm:flex sm:flex-wrap" onSubmit={(e) => { e.preventDefault(); apply({ ...form, q: form.q.trim() }) }}>
        <label className="col-span-2 text-sm">What
          <select className="input mt-1 sm:w-auto" value={form.action} onChange={(e) => { setForm({ ...form, action: e.target.value }); apply({ ...filters, action: e.target.value }) }}>
            <option value="">Everything</option>
            {Object.entries(groups).map(([key, label]) => <option key={key} value={`${key}.`}>{label}</option>)}
          </select>
        </label>
        <label className="col-span-2 text-sm sm:w-64">Who
          <input className="input mt-1" type="search" placeholder="Name or email" value={form.q} maxLength={100} onChange={(e) => setForm({ ...form, q: e.target.value })} />
        </label>
        <label className="text-sm">From<input type="date" className="input mt-1 sm:w-auto" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} /></label>
        <label className="text-sm">To<input type="date" className="input mt-1 sm:w-auto" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} /></label>
        <button type="submit" className="btn-secondary">Show</button>
        {FILTERS.some((k) => filters[k]) && (
          <button type="button" className="btn-ghost" onClick={() => { setForm(Object.fromEntries(FILTERS.map((k) => [k, '']))); apply({}) }}>Clear</button>
        )}
      </form>
      {loading && !data && <Spinner />}
      {error && <ErrorState error={error} onRetry={reload} />}
      {data && (data.data.length ? (
        <>
          <div className="card relative overflow-x-auto">
            <table className="rtable w-full text-left text-sm">
              <thead className="border-b border-stone-200 text-xs text-stone-500 uppercase">
                <tr><th className="px-4 py-3">When</th><th className="px-4 py-3">Who</th><th className="px-4 py-3">What happened</th><th className="px-4 py-3">On</th><th className="px-4 py-3">Details</th></tr>
              </thead>
              <tbody className="divide-y divide-stone-100">
                {data.data.map((log) => (
                  <tr key={log.id} className="align-top">
                    <td data-label="When" className="px-4 py-2.5 whitespace-nowrap text-stone-600">{formatDateTime(log.created_at)}</td>
                    <td data-label="Who" className="max-w-56 px-4 py-2.5 max-sm:max-w-none">
                      {log.user ? (
                        <span className="block min-w-0">
                          <span className="block font-medium text-stone-900">{log.user.name}</span>
                          <span className="block truncate text-xs text-stone-500" title={log.user.email}>{log.user.email}</span>
                        </span>
                      ) : <span className="text-stone-500">System</span>}
                    </td>
                    <td className="rtable-title px-4 py-2.5">
                      <span className="block font-medium text-stone-900">{log.action_label}</span>
                      <span className="block font-mono text-[11px] text-stone-400">{log.action}</span>
                    </td>
                    <td data-label={log.subject_label ? 'On' : undefined} className="px-4 py-2.5 text-stone-700">{log.subject_label || ''}</td>
                    <td data-label={log.details ? 'Details' : undefined} className="max-w-sm px-4 py-2.5 text-stone-700 max-sm:max-w-none">
                      {log.details && <Details text={log.details} meta={log.meta} />}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination meta={{ current_page: data.current_page, last_page: data.last_page }} onPage={(p) => setParams(toQuery({ ...filters, page: p }))} />
        </>
      ) : <EmptyState title={FILTERS.some((k) => filters[k]) ? 'Nothing matches these filters' : 'No activity yet'} />)}
    </>
  )
}

/** The readable summary; long ones fold, and the saved data is one click away. */
function Details({ text, meta }) {
  const long = text.length > 140
  return (
    <details className="group min-w-0">
      <summary className="cursor-pointer list-none break-words [&::-webkit-details-marker]:hidden">
        <span className={long ? 'line-clamp-2 group-open:line-clamp-none' : ''}>{text}</span>
        <span className="mt-0.5 block text-xs text-stone-500 underline-offset-2 group-hover:underline">
          <span className="group-open:hidden">{long ? 'Show all' : 'Show saved data'}</span>
          <span className="hidden group-open:inline">Hide saved data</span>
        </span>
      </summary>
      <pre className="mt-1 max-h-64 overflow-auto rounded bg-stone-50 p-2 text-xs whitespace-pre-wrap break-words text-stone-700">{JSON.stringify(meta, null, 2)}</pre>
    </details>
  )
}
