// Loads the Google Maps JavaScript API once, on first use. Only pages with a map pay for it.
let loading = null

export function loadGoogleMaps(key) {
  if (window.google?.maps?.importLibrary) return Promise.resolve(window.google.maps)
  if (loading) return loading
  loading = new Promise((resolve, reject) => {
    const timer = setTimeout(() => fail(new Error('Google Maps did not load.')), 15000)
    function fail(err) {
      clearTimeout(timer)
      loading = null
      reject(err)
    }
    // Google calls this when the key is refused (wrong key, billing off, site not allowed).
    window.gm_authFailure = () => fail(new Error('Google Maps refused the key.'))
    window.__mpMapsReady = () => {
      clearTimeout(timer)
      resolve(window.google.maps)
    }
    const s = document.createElement('script')
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&v=weekly&loading=async&callback=__mpMapsReady`
    s.async = true
    s.onerror = () => fail(new Error('Google Maps could not be reached.'))
    document.head.appendChild(s)
  })
  return loading
}

/** A link that opens the pin in Google Maps (no key needed). */
export function mapsLink(lat, lng) {
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`
}

/** Turn-by-turn directions to the pin, for the store. */
export function directionsLink(lat, lng) {
  return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`
}
