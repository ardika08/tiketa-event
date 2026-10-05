/**
 * Normalisasi response API backend agar sesuai dengan bentuk yang
 * dipakai komponen frontend.
 */

export function normalizeTicketType(t) {
  if (!t) return t
  const sessionIds = Array.isArray(t.session_ids)
    ? t.session_ids
    : t.session_id != null
      ? [t.session_id]
      : []
  return {
    ...t,
    id: t.id,
    harga: Number(t.harga ?? 0),
    sisa_kuota: Number(t.sisa_kuota ?? 0),
    kuota: Number(t.kuota ?? 0),
    max_per_order: Number(t.max_per_order ?? 1),
    session_ids: sessionIds,
    session_id: sessionIds[0] ?? null,
  }
}

export function normalizeEvent(e) {
  if (!e) return null
  const tiket = (e.tiket || []).map(normalizeTicketType)
  const organizer =
    typeof e.organizer === 'string'
      ? e.organizer
      : e.organizer?.nama_penyelenggara || ''
  return {
    ...e,
    organizer,
    tiket,
    total_sisa_kuota:
      e.total_sisa_kuota ?? tiket.reduce((sum, t) => sum + (t.sisa_kuota || 0), 0),
  }
}

export function normalizePass(p) {
  if (!p) return p
  return {
    ...p,
    session_id: p.event_session_id ?? p.session_id ?? null,
    status: p.status_kehadiran ?? p.status ?? 'belum_hadir',
  }
}

export function normalizeTicket(t) {
  if (!t) return t
  return {
    ...t,
    passes: (t.passes || []).map(normalizePass),
  }
}

export function normalizeOrder(o) {
  if (!o) return null
  const items = (o.items || []).map((it) => ({
    ...it,
    harga: Number(it.harga_satuan ?? it.harga ?? 0),
    ticketId: it.ticket_type_id,
    session_ids: it.session_ids || [],
  }))
  const tickets = (o.tickets || []).map(normalizeTicket)
  return {
    ...o,
    event: normalizeEvent(o.event),
    items,
    tickets,
    subtotal: Number(o.subtotal ?? 0),
    diskon: Number(o.diskon ?? 0),
    total: Number(o.total_harga ?? o.total ?? 0),
    total_harga: Number(o.total_harga ?? o.total ?? 0),
    biaya_layanan: Number(o.biaya_layanan ?? 0),
    jumlah_tiket: tickets.length || items.reduce((sum, it) => sum + it.jumlah, 0),
    metode: o.metode || (o.payment_status ? { nama: 'Mayar' } : null),
  }
}
