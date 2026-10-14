import { Crosshair, MapPin, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useConfig } from '../context/ConfigContext'
import { loadGoogleMaps, mapsLink } from '../lib/maps'

const round6 = (n) => Math.round(n * 1e6) / 1e6

/**
 * Pick a point on a Google map: tap to drop the pin, drag to adjust, or use the phone's location.
 * Without a Maps key (or when Google fails) the map is left out and "Use my location" still works.
 * value: { latitude, longitude } or nulls.
 */
export default function MapPinPicker({ value, onChange, label = 'Map pin', hint, error, id = 'map-pin' }) {
  const config = useConfig()
  const key = config.maps?.browser_key
  const box = useRef(null)
  const map = useRef(null)
  const marker = useRef(null)
  const latest = useRef(onChange)
  const [mapState, setMapState] = useState(key ? 'loading' : 'off')
  const [locating, setLocating] = useState(false)
  const [geoError, setGeoError] = useState(null)
  const has = value?.latitude != null && value?.longitude != null

  useEffect(() => {
    latest.current = onChange
  }, [onChange])

  // Create the map once.
  useEffect(() => {
    if (!key) return undefined
    let alive = true
    loadGoogleMaps(key).then(async (maps) => {
      if (!alive || !box.current) return
      const { Map } = await maps.importLibrary('maps')
      const { AdvancedMarkerElement } = await maps.importLibrary('marker')
      const [lat, lng] = config.maps.center || [9.0108, 38.7613]
      const start = has ? { lat: Number(value.latitude), lng: Number(value.longitude) } : { lat, lng }
      map.current = new Map(box.current, {
        center: start, zoom: has ? 16 : 12, mapId: config.maps.map_id || 'DEMO_MAP_ID',
        streetViewControl: false, mapTypeControl: false, fullscreenControl: false, clickableIcons: false, gestureHandling: 'greedy',
      })
      marker.current = new AdvancedMarkerElement({ map: has ? map.current : null, position: start, gmpDraggable: true, title: label })
      const place = (ll) => latest.current({ latitude: round6(ll.lat()), longitude: round6(ll.lng()) })
      map.current.addListener('click', (e) => e.latLng && place(e.latLng))
      marker.current.addListener('dragend', () => {
        const p = marker.current.position
        latest.current({ latitude: round6(typeof p.lat === 'function' ? p.lat() : p.lat), longitude: round6(typeof p.lng === 'function' ? p.lng() : p.lng) })
      })
      setMapState('ready')
    }).catch(() => alive && setMapState('failed'))
    return () => {
      alive = false
    }
    // Only on mount: later pin changes move the marker below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  // Keep the marker where the value says.
  useEffect(() => {
    if (!marker.current || !map.current) return
    if (has) {
      const pos = { lat: Number(value.latitude), lng: Number(value.longitude) }
      marker.current.position = pos
      marker.current.map = map.current
    } else {
      marker.current.map = null
    }
  }, [has, value?.latitude, value?.longitude])

  function locate() {
    setGeoError(null)
    if (!navigator.geolocation) {
      setGeoError('This browser cannot share its location.')
      return
    }
    setLocating(true)
    // A permission prompt that is never answered leaves the request hanging; stop waiting after a while.
    let answered = false
    const giveUp = setTimeout(() => {
      if (answered) return
      answered = true
      setLocating(false)
      setGeoError('No answer from the browser. Allow location when it asks, or tap the map instead.')
    }, 20000)
    const done = () => {
      if (answered) return false
      answered = true
      clearTimeout(giveUp)
      return true
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        if (!done()) return
        setLocating(false)
        const next = { latitude: round6(pos.coords.latitude), longitude: round6(pos.coords.longitude) }
        onChange(next)
        map.current?.panTo({ lat: next.latitude, lng: next.longitude })
        map.current?.setZoom(17)
      },
      (err) => {
        if (!done()) return
        setLocating(false)
        setGeoError(err.code === 1 ? 'Location permission was refused. Allow it in the browser, or tap the map instead.' : 'Your location could not be found. Try again outside, or tap the map instead.')
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
    )
  }

  return (
    <div className="space-y-2" role="group" aria-labelledby={`${id}-label`}>
      <p id={`${id}-label`} className="text-sm font-medium text-stone-700">{label}</p>
      {hint && <p className="text-xs text-stone-500">{hint}</p>}
      {mapState !== 'off' && mapState !== 'failed' && (
        <div className="relative">
          <div ref={box} className="h-64 w-full overflow-hidden rounded-xl border border-stone-200 bg-stone-100" data-testid={`${id}-map`} />
          {mapState === 'loading' && <p className="absolute inset-0 flex items-center justify-center text-sm text-stone-500">Loading map...</p>}
          {mapState === 'ready' && !has && <p className="pointer-events-none absolute top-2 left-2 rounded-full bg-white/95 px-3 py-1 text-xs font-medium text-stone-700 shadow">Tap the map where the door is</p>}
        </div>
      )}
      {mapState === 'failed' && <p className="text-xs text-amber-800">The map could not load. Use your location instead.</p>}
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <button type="button" className="btn-secondary px-3 py-1.5" onClick={locate} disabled={locating}>
          <Crosshair className="size-4" aria-hidden="true" />{locating ? 'Finding you...' : 'Use my location'}
        </button>
        {has ? (
          <>
            <a href={mapsLink(value.latitude, value.longitude)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-brand-700 hover:underline">
              <MapPin className="size-4" aria-hidden="true" />Pin set, check it on Google Maps
            </a>
            <button type="button" className="btn-ghost px-2 py-1 text-stone-500" onClick={() => onChange({ latitude: null, longitude: null })}>
              <X className="size-4" aria-hidden="true" />Remove pin
            </button>
          </>
        ) : <span className="text-stone-500">No pin yet</span>}
      </div>
      {geoError && <p className="text-xs text-red-700">{geoError}</p>}
      {error && <p className="text-xs font-medium text-red-700">{error}</p>}
    </div>
  )
}
