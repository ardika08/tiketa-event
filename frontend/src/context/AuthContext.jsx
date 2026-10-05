import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { authApi, getToken, setToken } from '../lib/api'

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

  // Sinkronkan sesi dari token saat aplikasi dimuat.
  useEffect(() => {
    let active = true
    if (getToken()) {
      authApi
        .me()
        .then((res) => active && setUser(mapUser(res.user)))
        .catch(() => {
          if (active) {
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
