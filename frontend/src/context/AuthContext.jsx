import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { authApi, getToken, setPendengarSesiBerakhir, setToken } from '../lib/api'

const AuthContext = createContext(null)
const STORAGE_KEY = 'nontix.auth'

const BACKEND_ROLE = { admin: 'admin_platform', partner: 'partner', staff: 'staff' }
const FRONTEND_ROLE = { admin_platform: 'admin', partner: 'partner', staff: 'staff' }

function mapUser(user) {
  if (!user) return null
  return { ...user, peran: FRONTEND_ROLE[user.peran] || user.peran }
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => {
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      return raw ? JSON.parse(raw) : null
    } catch {
      return null
    }
  })
  const [ready, setReady] = useState(false)

  useEffect(() => {
    if (user) localStorage.setItem(STORAGE_KEY, JSON.stringify(user))
    else localStorage.removeItem(STORAGE_KEY)
  }, [user])

  // Fase 2 — sesi berakhir di tengah pemakaian. Transport (lib/api.js) sudah
  // membuang tokennya; di sini cukup mengosongkan state. ProtectedRoute lalu
  // mengalihkan ke halaman masuk, dan halaman masuk membaca alasannya dari
  // sessionStorage untuk menampilkan pemberitahuan. Efek ini sengaja ditulis
  // SEBELUM efek sinkronisasi di bawah agar pendengarnya sudah terpasang saat
  // authApi.me() dipanggil.
  useEffect(() => {
    setPendengarSesiBerakhir(() => setUser(null))
    return () => setPendengarSesiBerakhir(null)
  }, [])

  // Sinkronkan sesi dari token saat aplikasi dimuat.
  useEffect(() => {
    let active = true
    if (getToken()) {
      authApi
        .me()
        .then((res) => active && setUser(mapUser(res.user)))
        .catch((err) => {
          // 401 sudah ditangani transport (token dibuang + pemberitahuan
          // "sesi berakhir"). Selain 401 — misalnya koneksi putus — token
          // JANGAN dibuang: dulu gangguan jaringan sekejap membuat pengguna
          // ter-logout diam-diam padahal sesinya masih sah.
          if (active && err?.status === 401) {
            setToken(null)
            setUser(null)
          }
        })
        .finally(() => active && setReady(true))
    } else {
      setReady(true)
    }
    return () => {
      active = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const login = useCallback(async (role, credentials = {}) => {
    const res = await authApi.login({
      email: credentials.email,
      password: credentials.password,
      peran: BACKEND_ROLE[role] || role,
    })
    setToken(res.token)
    const mapped = mapUser(res.user)
    setUser(mapped)
    return mapped
  }, [])

  const register = useCallback(async (payload) => {
    const res = await authApi.registerPartner(payload)
    setToken(res.token)
    const mapped = mapUser(res.user)
    setUser(mapped)
    return mapped
  }, [])

  const logout = useCallback(async () => {
    try {
      await authApi.logout()
    } catch {
      // abaikan bila token sudah tidak valid
    }
    setToken(null)
    setUser(null)
  }, [])

  const value = useMemo(() => ({ user, ready, login, register, logout }), [user, ready, login, register, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth harus dipakai di dalam AuthProvider')
  return ctx
}
