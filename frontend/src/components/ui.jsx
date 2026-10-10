import { Link } from 'react-router-dom'
import { STATUS_LABELS } from '../lib/format'

export function Spinner({ label = 'Loading' }) {
  return (
    <div role="status" className="flex items-center justify-center gap-3 py-16 text-stone-500">
      <span className="size-5 animate-spin rounded-full border-2 border-stone-300 border-t-brand-600" />
      <span className="text-sm">{label}...</span>
    </div>
  )
}

export function ErrorState({ error, onRetry }) {
  return (
    <div role="alert" className="card flex flex-col items-center gap-3 px-6 py-12 text-center">
      <p className="font-medium text-stone-900">{error?.message || 'Something went wrong.'}</p>
      {onRetry && (
        <button type="button" className="btn-secondary" onClick={onRetry}>
          Try again
        </button>
      )}
    </div>
  )
}

export function EmptyState({ title, children, action }) {
  return (
    <div className="card flex flex-col items-center gap-2 px-6 py-14 text-center">
      <p className="text-lg font-semibold text-stone-900">{title}</p>
      {children && <div className="max-w-md text-sm text-stone-600">{children}</div>}
      {action && <div className="mt-3">{action}</div>}
    </div>
  )
}

export function Alert({ tone = 'info', children }) {
  const tones = {
    info: 'border-brand-200 bg-brand-50 text-brand-900',
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
    error: 'border-red-300 bg-red-50 text-red-800',
    success: 'border-emerald-300 bg-emerald-50 text-emerald-900',
  }
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border px-4 py-3 text-sm ${tones[tone]}`}>
      {children}
    </div>
  )
}

const BADGE_TONES = {
  published: 'bg-emerald-100 text-emerald-800',
  approved: 'bg-emerald-100 text-emerald-800',
  pending_review: 'bg-amber-100 text-amber-800',
  pending: 'bg-amber-100 text-amber-800',
  draft: 'bg-stone-200 text-stone-700',
  archived: 'bg-stone-200 text-stone-700',
  rejected: 'bg-red-100 text-red-800',
  suspended: 'bg-red-100 text-red-800',
}

export function StatusBadge({ status }) {
  return (
    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${BADGE_TONES[status] || 'bg-stone-100 text-stone-700'}`}>
      {STATUS_LABELS[status] || status}
    </span>
  )
}

export function Field({ label, name, error, hint, children }) {
  return (
    <div>
      <label htmlFor={name} className="label">
        {label}
      </label>
      {children}
      {hint && !error && <p className="mt-1 text-xs text-stone-500">{hint}</p>}
      {error && (
        <p id={`${name}-error`} className="mt-1 text-xs font-medium text-red-700">
          {error}
        </p>
      )}
    </div>
  )
}

/**
 * Laravel paginator meta -> Previous / Next with page count.
 */
export function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <nav aria-label="Pagination" className="mt-8 flex items-center justify-between gap-4">
      <button type="button" className="btn-secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
        Previous
      </button>
      <span className="text-sm text-stone-600">
        Page {meta.current_page} of {meta.last_page}
      </span>
      <button type="button" className="btn-secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
        Next
      </button>
    </nav>
  )
}

export function PageHeader({ title, subtitle, actions }) {
  return (
    <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-stone-600">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}

export function NotFound() {
  return (
    <div className="container-page py-16">
      <EmptyState title="Page not found" action={<Link to="/" className="btn-primary">Back to home</Link>}>
        The page you are looking for does not exist or was moved.
      </EmptyState>
    </div>
  )
}
