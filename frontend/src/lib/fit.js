// "Will it fit?": can a piece be carried through the customer's door, stairs and lift?
// A piece goes through an opening on its side, so its two smallest sides must pass the
// opening's width and height. Doors and lift doors are taken as 200 cm high.

export const OPENING_HEIGHT_CM = 200
// Less room than this on either side is "tight": it goes through, but only just.
export const TIGHT_CM = 5

const num = (v) => {
  const n = Number(v)
  return Number.isFinite(n) && n > 0 ? n : null
}

/** Room left when carrying the piece through an opening (negative: it does not fit). */
export function clearance(dims, widthCm, heightCm = OPENING_HEIGHT_CM) {
  const sides = [dims.width_cm, dims.depth_cm, dims.height_cm].map(num)
  const w = num(widthCm)
  if (!w || sides.some((s) => s === null)) return null
  const [a, b] = sides.sort((x, y) => x - y)
  const h = num(heightCm) || OPENING_HEIGHT_CM
  // Narrow side through the width and the middle side through the height, or the other way round.
  return Math.max(Math.min(w - a, h - b), Math.min(h - a, w - b))
}

const verdict = (room) => (room === null ? 'unknown' : room < 0 ? 'no' : room < TIGHT_CM ? 'tight' : 'fits')

/**
 * Checks every opening on the way in. space: { door_width_cm, stair_width_cm, lift_door_width_cm,
 * floor, has_lift }. Returns { status: 'fits'|'tight'|'no'|'unknown', checks: [{ key, label, status, room }], note }.
 */
export function checkFit(dims, space) {
  if (!dims || !space) return { status: 'unknown', checks: [], note: null }
  const checks = []
  const add = (key, label, width) => {
    if (num(width)) {
      const room = clearance(dims, width)
      checks.push({ key, label, status: verdict(room), room })
    }
  }
  add('door', 'Front door', space.door_width_cm)
  const upstairs = Number(space.floor) > 0
  let note = null
  if (upstairs && space.has_lift) {
    add('lift', 'Lift door', space.lift_door_width_cm)
    const lift = checks.find((c) => c.key === 'lift')
    if (lift?.status === 'no') {
      add('stairs', 'Stairs', space.stair_width_cm)
      note = num(space.stair_width_cm) ? 'Too big for the lift, so it goes up the stairs.' : 'Too big for the lift. Add your stair width to check the stairs.'
    }
  } else if (upstairs || num(space.stair_width_cm)) {
    add('stairs', 'Stairs', space.stair_width_cm)
  }
  if (checks.length === 0 || checks.every((c) => c.status === 'unknown')) return { status: 'unknown', checks, note }

  // The way in is as tight as its tightest opening; a lift that is too small does not count when the stairs work.
  const onPath = checks.filter((c) => !(c.key === 'lift' && c.status === 'no' && checks.some((s) => s.key === 'stairs' && s.status !== 'no')))
  const order = ['no', 'tight', 'unknown', 'fits']
  const status = onPath.map((c) => c.status).sort((x, y) => order.indexOf(x) - order.indexOf(y))[0]
  return { status: status === 'unknown' ? 'fits' : status, checks, note }
}

const KEY = 'mp_space'

/** Door and stair sizes kept in this browser for visitors without a saved address. */
export function readSpace() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || 'null')
    return v && typeof v === 'object' ? v : null
  } catch {
    return null
  }
}

export function writeSpace(space) {
  try {
    localStorage.setItem(KEY, JSON.stringify(space))
  } catch {
    // Not saved; the check still works on this page.
  }
}
