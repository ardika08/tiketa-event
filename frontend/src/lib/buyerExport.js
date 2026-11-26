// Kolom tambahan untuk ekspor Data Pembeli.
//
// Sumbernya `form_export` dari API: server sudah menyaring hanya field formulir
// yang terdaftar untuk event + jenis tiket yang dibeli. Di sini hanya menyusun
// kolom, dan identitas kolom memakai ID jenis tiket (bukan urutan baris) supaya
// kolom yang sama tidak berpindah arti antar unduhan.

const label = (value) => value === null || value === undefined ? '' : String(value).trim()

/** Satu kolom per (field, jenis tiket); urutan mengikuti field lalu ID tiket. */
export function appendBuyerFormColumns([headers, ...rows], buyers) {
  const columns = new Map()
  const usedHeaders = new Set(headers.map((header) => String(header).toLowerCase()))
  const answersByRow = buyers.map((buyer) => {
    const answers = new Map()
    const perType = buyer.ticket_names && typeof buyer.ticket_names === 'object' ? buyer.ticket_names : {}
    for (const answer of Array.isArray(buyer.form_export) ? buyer.form_export : []) {
      const name = label(answer?.label)
      const value = label(answer?.value)
      if (!name || value === '') continue
      const ticketId = answer.ticket_id === null || answer.ticket_id === undefined ? null : String(answer.ticket_id)
      const key = JSON.stringify([ticketId, name])
      if (!columns.has(key)) {
        // Jenis tiket selalu ikut di judul kolom, meski ada dua tiket bernama sama.
        const heading = ticketId === null ? name : `${name} — ${perType[ticketId] || `Jenis tiket #${ticketId}`}`
        let unique = heading
        for (let suffix = 2; usedHeaders.has(unique.toLowerCase()); suffix++) unique = `${heading} (${suffix})`
        usedHeaders.add(unique.toLowerCase())
        columns.set(key, unique)
      }
      answers.set(key, value)
    }
    return answers
  })

  const keys = [...columns.keys()]
  return [
    [...headers, ...columns.values()],
    ...rows.map((row, index) => [...row, ...keys.map((key) => answersByRow[index]?.get(key) ?? '')]),
  ]
}
