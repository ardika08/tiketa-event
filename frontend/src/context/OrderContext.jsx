import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { publicApi } from '../lib/api'
import { normalizeOrder } from '../lib/normalize'

const OrderContext = createContext(null)
const LAST_KEY = 'nontix.lastOrder'

const EMPTY_BUYER = { nama: '', email: '', whatsapp: '', instagram: '', tiktok: '', threads: '' }

export function OrderProvider({ children }) {
  const [draft, setDraft] = useState(null)
  const [order, setOrder] = useState(null)
  const [loading, setLoading] = useState(false)

  const startDraft = useCallback((event) => {
    setDraft({ event: typeof event === 'object' ? event : null, items: [], buyer: { ...EMPTY_BUYER }, voucher: null })
  }, [])

  const updateDraft = useCallback((patch) => {
    setDraft((d) => ({ ...(d || {}), ...patch }))
  }, [])

  const createOrder = useCallback(async (payload) => {
    setLoading(true)
    try {
      const res = await publicApi.createOrder(payload)
      const normalized = normalizeOrder(res.data)
      setOrder(normalized)
      if (normalized?.kode_order) localStorage.setItem(LAST_KEY, normalized.kode_order)
      return normalized
    } finally {
      setLoading(false)
    }
  }, [])

  const markPaid = useCallback(async () => {
    const kode = order?.kode_order || localStorage.getItem(LAST_KEY)
    if (!kode) return null
    setLoading(true)
    try {
      const res = await publicApi.payFake(kode)
      const normalized = normalizeOrder(res.order)
      setOrder(normalized)
      return normalized
    } finally {
      setLoading(false)
    }
  }, [order])

  const refreshOrder = useCallback(async (kode) => {
    const target = kode || order?.kode_order || localStorage.getItem(LAST_KEY)
    if (!target) return null
    const res = await publicApi.order(target)
    const normalized = normalizeOrder(res.data)
    setOrder(normalized)
    return normalized
  }, [order])

  const clear = useCallback(() => {
    setDraft(null)
    setOrder(null)
    localStorage.removeItem(LAST_KEY)
  }, [])

  const value = useMemo(
    () => ({ draft, order, loading, startDraft, updateDraft, createOrder, markPaid, refreshOrder, setOrder, clear }),
    [draft, order, loading, startDraft, updateDraft, createOrder, markPaid, refreshOrder, clear],
  )

  return <OrderContext.Provider value={value}>{children}</OrderContext.Provider>
}

export function useOrder() {
  const ctx = useContext(OrderContext)
  if (!ctx) throw new Error('useOrder harus dipakai di dalam OrderProvider')
  return ctx
}
