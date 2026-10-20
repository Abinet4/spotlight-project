import { useEffect, useState } from 'react'
import { Field } from './ui'

/** Seconds left of a wait, ticking once a second. `restart(seconds)` starts a new wait. */
function useCountdown(initialSeconds) {
  const [clock, setClock] = useState(() => ({ now: Date.now(), until: Date.now() + initialSeconds * 1000 }))
  useEffect(() => {
    if (clock.now >= clock.until) return undefined
    const t = setTimeout(() => setClock((c) => ({ ...c, now: Date.now() })), 1000)
    return () => clearTimeout(t)
  }, [clock])
  const restart = (seconds) => setClock({ now: Date.now(), until: Date.now() + seconds * 1000 })
  return [Math.max(0, Math.ceil((clock.until - clock.now) / 1000)), restart]
}

/**
 * The six-digit code box, with the phone it went to and a resend link that waits out the cooldown.
 * `resendIn` is seconds from the server; `onResend` returns the next wait, or null when no more codes can be sent.
 */
export function CodeEntry({ id, phone, value, onChange, error, resendIn, resendsLeft, onResend, busy }) {
  const [wait, restart] = useCountdown(resendIn)

  async function resend() {
    const next = await onResend()
    if (next != null) restart(next)
  }

  return (
    <div className="space-y-2">
      <Field label="Code" name={id} error={error} hint={`We texted a 6-digit code to ${phone}. It works for 5 minutes.`}>
        <input
          id={id}
          className="input max-w-[12rem] text-center font-mono text-lg tracking-[0.3em]"
          inputMode="numeric"
          autoComplete="one-time-code"
          pattern="[0-9 ]*"
          maxLength={7}
          autoFocus
          value={value}
          onChange={(e) => onChange(e.target.value.replace(/[^0-9 ]/g, ''))}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-error` : undefined}
        />
      </Field>
      <p className="text-sm text-stone-600">
        {resendsLeft === 0
          ? 'No more codes can be sent for this step.'
          : wait > 0
            ? `No code? You can ask for a new one in ${wait} s.`
            : <button type="button" className="font-semibold text-brand-700 hover:underline disabled:opacity-50" disabled={busy} onClick={resend}>Text me a new code</button>}
      </p>
    </div>
  )
}
