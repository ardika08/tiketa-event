// Kolom tambahan untuk ekspor Data Pembeli.
//
// Sumbernya `form_export` dari API: server sudah menyaring hanya field formulir
// yang terdaftar untuk event + jenis tiket yang dibeli pembeli.
//
// Satu field = satu kolom, apa pun jenis tiketnya. Jadi "Nama Anak" tetap satu
// kolom walau diisi lewat beberapa jenis tiket; kalau jawabannya berbeda antar
// tiket, nilainya digabung dengan koma. Urutan kolom mengikuti urutan field di
// formulir, bukan urutan baris, supaya arti kolom tidak berubah antar unduhan.

const label = (value) => value === null || value === undefined ? '' : String(value).trim()

/** Satu kolom per label field; jawaban semua jenis tiket digabung. */
export function appendBuyerFormColumns([headers, ...rows], buyers) {
  const order = new Map()
  const answersByRow = buyers.map((buyer) => {
    const answers = new Map()
    for (const answer of Array.isArray(buyer.form_export) ? buyer.form_export : []) {
      const name = label(answer?.label)
      const value = label(answer?.value)
      if (!name || value === '') continue
      // Map menjaga urutan kemunculan pertama. Urutan field konsisten karena
      // server mengirimkannya berdasarkan `urutan` formulir.
      if (!order.has(name)) order.set(name, [])
      const values = order.get(name)
      if (!answers.has(name)) answers.set(name, [])
      const collected = answers.get(name)
      if (!collected.includes(value)) collected.push(value)
      if (!values.includes(value)) values.push(value)
    }
    return answers
  })

  const usedHeaders = new Set(headers.map((header) => String(header).toLowerCase()))
  const headings = new Map()
  for (const name of [...order.keys()]) {
    let unique = name
    for (let suffix = 2; usedHeaders.has(unique.toLowerCase()); suffix++) unique = `${name} (${suffix})`
    usedHeaders.add(unique.toLowerCase())
    headings.set(name, unique)
  }

  const keys = [...order.keys()]
  return [
    [...headers, ...keys.map((key) => headings.get(key))],
    ...rows.map((row, index) => [...row, ...keys.map((key) => answersByRow[index]?.get(key)?.join(', ') ?? '')]),
  ]
}
