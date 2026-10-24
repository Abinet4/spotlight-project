import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import DownloadButton from '../../components/DownloadButton'
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
  // One line per kind of problem, so a provider account issue is explained once, not under every text.
  const problems = [...new Set((list.data?.data || []).filter((m) => m.status === 'failed' && m.explanation).map((m) => m.explanation))]

  return (
    <>
      <PageHeader title="Text messages"
        actions={<DownloadButton path="/admin/sms" query={toQuery({ status, q })} filename={`text-messages${status ? `-${status}` : ''}`} />} />
      {problems.length > 0 && (
        <div className="mb-4">
          <Alert tone="error">
            <span className="font-semibold">Some texts on this page did not go out.</span>
            {problems.map((p) => <span key={p} className="mt-1 block">{p}</span>)}
          </Alert>
        </div>
      )}
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
                  {m.error && (
                    <details className="mt-1 text-xs">
                      <summary className="cursor-pointer font-medium text-red-700">{m.status === 'failed' ? 'Not sent' : 'An earlier try failed'} · show the provider's message</summary>
                      <p className="mt-1 rounded bg-stone-50 p-2 font-mono break-words text-stone-600">{m.error}</p>
                    </details>
                  )}
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
