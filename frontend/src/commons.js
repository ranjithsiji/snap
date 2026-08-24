/**
 * Direct, read-only calls to the Commons API from the browser.
 *
 * Separate from api.js, which talks to this tool's own backend. Commons
 * sends `access-control-allow-origin: *` for anonymous reads (with
 * `origin=*` in the query), so descriptions can be fetched straight from
 * the CDN — no round trip through the webservice, and nothing extra to
 * store or keep in step with Commons.
 */

const API = 'https://commons.wikimedia.org/w/api.php'

// Descriptions do not change while a juror works, and they page back and
// forth between images constantly. Keyed by page id, cleared only on
// reload, so revisiting an image never refetches.
const cache = new Map()

/**
 * Turns the API's description HTML into plain text.
 *
 * Multilingual descriptions come back wrapped in markup — typically
 * `<div class="description mw-content-ltr en">…</div>`, sometimes with
 * italics or links inside. Only the text is wanted.
 *
 * Parsed through DOMParser rather than by assigning to innerHTML: an
 * element attached to this document runs handlers as it parses, so
 * `<img src=x onerror=…>` in a description executes — verified, not
 * assumed. Commons descriptions are editable by anyone, which makes that
 * a live XSS path. A DOMParser document is inert: no scripts run, no
 * handlers fire, and no resource is fetched.
 */
function toPlainText(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html')

  // Language wrappers stack, and each carries its translation as text
  // when several exist; collapsing whitespace keeps the result readable
  // rather than full of the source's indentation.
  return (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim()
}

/**
 * The description Commons holds for a file, or null when it has none.
 *
 * Never throws: a description is a nicety beside the photograph itself,
 * and a juror should not be shown an error — or blocked from voting —
 * because Commons was briefly unreachable.
 */
export async function fetchDescription(pageId) {
  if (pageId === undefined || pageId === null) return null
  if (cache.has(pageId)) return cache.get(pageId)

  const params = new URLSearchParams({
    action: 'query',
    format: 'json',
    formatversion: '2',
    // Required for the anonymous CORS response; without it the browser
    // refuses the cross-origin read.
    origin: '*',
    pageids: String(pageId),
    prop: 'imageinfo',
    iiprop: 'extmetadata',
    // Just the one field: extmetadata otherwise carries licence, author,
    // categories and more, which is a great deal of payload to transfer
    // per image for one paragraph of text.
    iiextmetadatafilter: 'ImageDescription',
  })

  try {
    const response = await fetch(`${API}?${params}`)

    if (!response.ok) {
      cache.set(pageId, null)
      return null
    }

    const data = await response.json()
    const page = data?.query?.pages?.[0]
    const raw = page?.imageinfo?.[0]?.extmetadata?.ImageDescription?.value
    const text = raw ? toPlainText(raw) : null
    const description = text === '' ? null : text

    cache.set(pageId, description)

    return description
  } catch {
    // Offline, blocked by an extension, or Commons down — all the same
    // from here: the panel simply shows no description.
    cache.set(pageId, null)

    return null
  }
}
