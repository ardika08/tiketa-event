// Helper pengelompokan tiket per sesi.
// Fungsi murni tanpa data apa pun — dipakai halaman publik
// (Checkout, Payment, Invoice, EventDetail) dan halaman partner
// (MasterTickets, EventForm).

export function ticketSessionIds(ticket) {
  if (!ticket) return []
  if (Array.isArray(ticket.session_ids)) return ticket.session_ids
  return ticket.session_id != null ? [ticket.session_id] : []
}

export function isBundleTicket(ticket) {
  return ticketSessionIds(ticket).length > 1
}

export function ticketCoversSession(ticket, sessionId) {
  return ticketSessionIds(ticket).includes(sessionId)
}

export function sessionsForTicket(event, ticket) {
  const ids = ticketSessionIds(ticket)
  return [...(event?.sessions || [])]
    .sort((a, b) => a.urutan - b.urutan)
    .filter((session) => ids.includes(session.id))
}

export function ticketSessionsLabel(event, ticket) {
  const sessions = [...(event?.sessions || [])].sort((a, b) => a.urutan - b.urutan)
  const covered = sessionsForTicket(event, ticket)
  if (!sessions.length || !covered.length) return null
  if (covered.length === 1) return covered[0].label
  if (covered.length === sessions.length) return 'Semua hari'
  return covered.map((session) => session.label).join(' + ')
}

export function groupItemsBySession(event, items) {
  const sessions = [...(event?.sessions || [])].sort((a, b) => a.urutan - b.urutan)
  if (!sessions.length) return [{ session: null, items }]

  const bundles = items.filter((item) => isBundleTicket(item))
  const singles = items.filter((item) => !isBundleTicket(item))

  const groups = sessions
    .map((session) => ({
      session,
      items: singles.filter((item) => ticketCoversSession(item, session.id)),
    }))
    .filter((group) => group.items.length > 0)

  const assigned = new Set(groups.flatMap((group) => group.items.map((item) => item.id)))
  const unassigned = singles.filter((item) => !assigned.has(item.id))
  if (unassigned.length) groups.push({ session: null, items: unassigned })
  if (bundles.length) groups.push({ session: null, bundle: true, items: bundles })
  return groups.length ? groups : [{ session: null, items }]
}
