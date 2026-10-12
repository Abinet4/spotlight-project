import { useOutletContext } from 'react-router-dom'
import { Alert } from '../../components/ui'
import { ORDER_STATUS_LABELS } from '../../lib/format'

/** Order status names as the store reads them: the store is the one being waited for. */
export const SELLER_ORDER_LABELS = { ...ORDER_STATUS_LABELS, pending_confirmation: 'To confirm' }

/** The /seller/summary the seller layout loaded (counts, balance, store status). */
export function useSellerSummary() {
  return useOutletContext()
}

/** Shown while a store is not approved: what that means and what the seller can still do. */
export function StoreStatus({ store }) {
  if (!store || store.status === 'approved') return null
  const reason = store.status_reason ? ` Reason: ${store.status_reason}` : ''
  const copy = {
    pending: ['warning', 'Your store is waiting for approval. You can prepare drafts now and submit them once approved.'],
    rejected: ['error', `Your application was not approved.${reason} Contact support for help.`],
    suspended: ['error', `Your store is suspended and your listings are hidden.${reason}`],
  }[store.status]
  return copy ? <div className="mb-4"><Alert tone={copy[0]}>{copy[1]}</Alert></div> : null
}
