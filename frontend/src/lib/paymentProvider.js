/**
 * Nama provider pembayaran yang boleh disebut ke pembeli.
 *
 * Sumber tunggal: field `payment_provider` dari OrderResource backend
 * (`xendit` | `mayar` | `fake` | null). Jangan pernah hardcode nama provider
 * di halaman pembeli — begitu gateway produksi diganti, semua copy jadi bohong.
 */

const PROVIDER_LABELS = {
  xendit: 'Xendit',
  mayar: 'Mayar',
  // `fake` = mode uji internal; jangan sebut nama provider ke pembeli.
  fake: null,
}

/**
 * Label provider untuk ditampilkan, atau `null` bila tidak ada / mode uji.
 * Provider baru yang belum terdaftar ditampilkan apa adanya.
 */
export function paymentProviderLabel(provider) {
  if (!provider) return null
  if (provider in PROVIDER_LABELS) return PROVIDER_LABELS[provider]
  return provider
}

/**
 * True bila pembeli membayar di halaman hosted provider (bukan mode uji).
 * Dipakai untuk memutuskan redirect vs simulasi lunas.
 */
export function isHostedProvider(provider) {
  return paymentProviderLabel(provider) !== null
}
