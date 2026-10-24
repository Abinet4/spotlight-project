import { Download } from 'lucide-react'
import { useState } from 'react'
import { api, parseError } from '../lib/api'

/**
 * Downloads a list as an Excel workbook, with the same file as CSV one click away. Excel keeps every value
 * as saved (leading zeros, +251 phone numbers, Amharic), which a CSV opened in Excel does not.
 *
 * `path` is the download address without its extension, `query` its filters, `filename` the name without extension.
 */
export default function DownloadButton({ path, query = '', filename, label = 'Download Excel' }) {
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  async function download(ext) {
    setBusy(ext)
    setError(null)
    try {
      const r = await api.get(`${path}.${ext}${query ? `?${query}` : ''}`, { responseType: 'blob' })
      const href = URL.createObjectURL(r.data)
      const a = document.createElement('a')
      a.href = href
      a.download = `${filename}.${ext}`
      a.click()
      URL.revokeObjectURL(href)
    } catch (err) {
      // A failed blob request carries its JSON error inside the blob.
      if (err?.response?.data instanceof Blob) {
        try { err.response.data = JSON.parse(await err.response.data.text()) } catch { /* not JSON */ }
      }
      setError(parseError(err).message)
    } finally {
      setBusy(null)
    }
  }
  return (
    <span className="inline-flex flex-col items-start">
      <span className="inline-flex items-center gap-2">
        <button type="button" className="btn-secondary" onClick={() => download('xlsx')} disabled={busy !== null}>
          <Download className="size-4" aria-hidden="true" />{busy === 'xlsx' ? 'Preparing...' : label}
        </button>
        <button type="button" className="text-xs text-stone-500 underline-offset-2 hover:text-stone-800 hover:underline disabled:opacity-50" onClick={() => download('csv')} disabled={busy !== null} aria-label="Download as CSV">
          {busy === 'csv' ? 'Preparing...' : 'CSV'}
        </button>
      </span>
      {error && <span role="alert" className="mt-1 text-xs text-red-700">{error}</span>}
    </span>
  )
}
