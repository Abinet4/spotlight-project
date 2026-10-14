import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner, Stats } from '../../components/ui'
import { formatDateTime, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const STATUS = {
  sent: ['Sent', 'bg-emerald-100 text-emerald-800'],
  logged: ['Logged only', 'bg-stone-200 text-stone-700'],
  pending: ['Sending', 'bg-amber-100 text-amber-800'],
  failed: ['Failed', 'bg-red-100 text-red-800'],
}

export default function AdminSms() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const q = params.get('q') || ''
  const [search, setSearch] = useState(q)
  const list = useFetch(`/admin/sms?${toQuery({ status, q, page: params.get('page') || '' })}`)
  const meta = list.data?.meta

  return (
    <>
      <PageHeader title="Text messages" />
      {meta && !meta.delivers && (
        <div className="mb-4"><Alert tone="warning">No SMS provider is set up, so texts are only logged here, not sent. The README says how to connect AfroMessage.</Alert></div>
      )}
      {meta && !meta.sms_enabled && <div className="mb-4"><Alert tone="warning">Text messages are paused in Settings.</Alert></div>}
      {meta && (
        <Stats className="mb-4" items={Object.entries(STATUS).map(([key, [label]]) => ({
          label: `${label}, 30 days`, value: meta.last_30_days[key], tone: key === 'failed' && meta.last_30_days[key] > 0 ? 'bad' : undefined,
        }))} />
      )}

      <div className="mb-4 flex flex-col gap-2 sm:flex-row">
        <select className="input sm:w-48" aria-label="Status" value={status} onChange={(e) => setParams(toQuery({ status: e.target.value, q }))}>
          <option value="">All statuses</option>
          {Object.entries(STATUS).map(([key, [label]]) => <option key={key} value={key}>{label}</option>)}
        </select>
        <form className="flex flex-1 gap-2" onSubmit={(e) => { e.preventDefault(); setParams(toQuery({ status, q: search.trim() })) }}>
          <input className="input min-w-0 flex-1" type="search" placeholder="Phone number or email" aria-label="Search by phone or email" value={search} onChange={(e) => setSearch(e.target.value)} maxLength={40} />
          <button type="submit" className="btn-secondary">Search</button>
        </form>
      </div>

      {list.loading && !list.data ? <Spinner /> : list.error ? <ErrorState error={list.error} onRetry={list.reload} /> : list.data.data.length === 0 ? (
        <EmptyState title="No text messages">Nothing matches these filters.</EmptyState>
      ) : (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((m) => {
              const [label, tone] = STATUS[m.status] || [m.status, 'bg-stone-100 text-stone-700']
              return (
                <li key={m.id} className="min-w-0 px-4 py-2.5">
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                    <span className={`inline-flex rounded-full px-2 py-0.5 font-semibold ${tone}`}>{label}</span>
                    <span className="font-medium text-stone-900">{m.to}</span>
                    {m.user && <span className="min-w-0 break-all text-stone-600" title={m.user.email}>{m.user.name}</span>}
                    <span className="text-stone-500">{formatDateTime(m.created_at)}</span>
                    {m.attempts > 1 && <span className="text-stone-500">{m.attempts} attempts</span>}
                  </div>
                  <p className="mt-1 break-words text-sm text-stone-800">{m.body}</p>
                  {m.error && <p className="mt-1 break-words text-xs font-medium text-red-700">{m.error}</p>}
                </li>
              )
            })}
          </ul>
          <Pagination meta={meta} onPage={(p) => setParams(toQuery({ status, q, page: p }))} />
        </>
      )}
    </>
  )
}
