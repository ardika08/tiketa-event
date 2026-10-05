const BASE = import.meta.env.VITE_API_URL || '/api'
const TOKEN_KEY = 'nontix.token'

let token = typeof localStorage !== 'undefined' ? localStorage.getItem(TOKEN_KEY) : null

export function setToken(value) {
  token = value || null
  if (typeof localStorage === 'undefined') return
  if (value) localStorage.setItem(TOKEN_KEY, value)
  else localStorage.removeItem(TOKEN_KEY)
}

export function getToken() {
  return token
}

export class ApiError extends Error {
  constructor(message, status, errors) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors || null
  }
}

function query(params) {
  if (!params) return ''
  const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')
  if (!entries.length) return ''
  return '?' + new URLSearchParams(entries).toString()
}

async function request(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (auth && token) headers.Authorization = `Bearer ${token}`

  let res
  try {
    res = await fetch(`${BASE}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch (err) {
    throw new ApiError('Tidak dapat terhubung ke server.', 0, null)
  }

  const text = await res.text()
  let data = null
  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      data = text
    }
  }

  if (!res.ok) {
    const message = data?.message || (typeof data === 'string' ? data : 'Terjadi kesalahan.')
    throw new ApiError(message, res.status, data?.errors || null)
  }

  return data
}

async function upload(path, file, extra = {}, auth = true) {
  const form = new FormData()
  form.append('file', file)
  Object.entries(extra).forEach(([key, value]) => form.append(key, value))

  const headers = { Accept: 'application/json' }
  if (auth && token) headers.Authorization = `Bearer ${token}`

  let res
  try {
    res = await fetch(`${BASE}${path}`, { method: 'POST', headers, body: form })
  } catch {
    throw new ApiError('Tidak dapat terhubung ke server.', 0, null)
  }

  const text = await res.text()
  let data = null
  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      data = text
    }
  }

  if (!res.ok) {
    const message = data?.message || (typeof data === 'string' ? data : 'Gagal mengunggah file.')
    throw new ApiError(message, res.status, data?.errors || null)
  }

  return data
}

export const api = {
  get: (path, opts) => request(path, { ...opts }),
  post: (path, body, opts) => request(path, { method: 'POST', body, ...opts }),
  put: (path, body, opts) => request(path, { method: 'PUT', body, ...opts }),
  delete: (path, opts) => request(path, { method: 'DELETE', ...opts }),
}

export const authApi = {
  login: (payload) => api.post('/auth/login', payload, { auth: false }),
  registerPartner: (payload) => api.post('/auth/partner/register', payload, { auth: false }),
  me: () => api.get('/auth/me'),
  logout: () => api.post('/auth/logout'),
}

export const publicApi = {
  events: (params) => api.get(`/events${query(params)}`, { auth: false }),
  event: (slug) => api.get(`/events/${slug}`, { auth: false }),
  validateVoucher: (payload) => api.post('/vouchers/validate', payload, { auth: false }),
  createOrder: (payload) => api.post('/orders', payload, { auth: false }),
  order: (kode) => api.get(`/orders/${kode}`, { auth: false }),
  tickets: (params) => api.get(`/tickets${query(params)}`, { auth: false }),
  resendTicket: (payload) => api.post('/tickets/resend', payload, { auth: false }),
  payFake: (kode) => api.get(`/payments/${kode}/fake`, { auth: false }),
  paymentStatus: (kode) => api.get(`/payments/${kode}/status`, { auth: false }),
  syncPayment: (kode) => api.get(`/payments/${kode}/sync`, { auth: false }),
  gateways: () => api.get('/payments/gateways', { auth: false }),
}

export const partnerApi = {
  events: () => api.get('/partner/events'),
  event: (id) => api.get(`/partner/events/${id}`),
  createEvent: (payload) => api.post('/partner/events', payload),
  updateEvent: (id, payload) => api.put(`/partner/events/${id}`, payload),
  deleteEvent: (id) => api.delete(`/partner/events/${id}`),
  createSession: (eventId, payload) => api.post(`/partner/events/${eventId}/sessions`, payload),
  updateSession: (eventId, sessionId, payload) => api.put(`/partner/events/${eventId}/sessions/${sessionId}`, payload),
  deleteSession: (eventId, sessionId) => api.delete(`/partner/events/${eventId}/sessions/${sessionId}`),

  ticketTypes: (params) => api.get(`/partner/ticket-types${query(params)}`),
  createTicketType: (payload) => api.post('/partner/ticket-types', payload),
  updateTicketType: (id, payload) => api.put(`/partner/ticket-types/${id}`, payload),
  deleteTicketType: (id) => api.delete(`/partner/ticket-types/${id}`),
  syncTicketSessions: (id, sessionIds) => api.put(`/partner/ticket-types/${id}/sessions`, { session_ids: sessionIds }),

  vouchers: () => api.get('/partner/vouchers'),
  createVoucher: (payload) => api.post('/partner/vouchers', payload),
  updateVoucher: (id, payload) => api.put(`/partner/vouchers/${id}`, payload),
  deleteVoucher: (id) => api.delete(`/partner/vouchers/${id}`),

  gates: () => api.get('/partner/gates'),
  createGate: (payload) => api.post('/partner/gates', payload),
  updateGate: (id, payload) => api.put(`/partner/gates/${id}`, payload),
  deleteGate: (id) => api.delete(`/partner/gates/${id}`),

  staffs: () => api.get('/partner/staffs'),
  createStaff: (payload) => api.post('/partner/staffs', payload),
  updateStaff: (id, payload) => api.put(`/partner/staffs/${id}`, payload),
  deleteStaff: (id) => api.delete(`/partner/staffs/${id}`),

  formFields: (params) => api.get(`/partner/form-fields${query(params)}`),
  createFormField: (payload) => api.post('/partner/form-fields', payload),
  updateFormField: (id, payload) => api.put(`/partner/form-fields/${id}`, payload),
  deleteFormField: (id) => api.delete(`/partner/form-fields/${id}`),

  seatPlan: (eventId) => api.get(`/partner/events/${eventId}/seat-plan`),
  saveSeatPlan: (eventId, payload) => api.put(`/partner/events/${eventId}/seat-plan`, payload),

  profile: () => api.get('/partner/profile'),
  updateProfile: (payload) => api.put('/partner/profile', payload),
  updateLegal: (payload) => api.put('/partner/profile/legal', payload),

  analytics: (params) => api.get(`/partner/analytics${query(params)}`),
  buyers: () => api.get('/partner/buyers'),
  finance: () => api.get('/partner/finance'),
  sales: () => api.get('/partner/sales'),

  payouts: () => api.get('/partner/payouts'),
  createPayout: (payload) => api.post('/partner/payouts', payload),
  cancelPayout: (id) => api.delete(`/partner/payouts/${id}`),

  upload: (file, folder) => upload('/partner/uploads', file, folder ? { folder } : {}),

  scanSessions: (eventId) => api.get(`/partner/scan/sessions/${eventId}`),
  scan: (payload) => api.post('/partner/scan', payload),
  attendance: (params) => api.get(`/partner/attendance${query(params)}`),
}

export const adminApi = {
  dashboard: (params) => api.get(`/admin/dashboard${query(params)}`),
  partners: () => api.get('/admin/partners'),
  partner: (id) => api.get(`/admin/partners/${id}`),
  updatePartner: (id, payload) => api.put(`/admin/partners/${id}`, payload),
  events: () => api.get('/admin/events'),
  revenue: (params) => api.get(`/admin/revenue${query(params)}`),
  reports: (params) => api.get(`/admin/reports${query(params)}`),
  payouts: () => api.get('/admin/payouts'),
  updatePayout: (id, payload) => api.put(`/admin/payouts/${id}`, payload),
  upload: (file) => upload('/admin/uploads', file),
  paymentSettings: () => api.get('/admin/payment-settings'),
  updatePaymentSettings: (payload) => api.put('/admin/payment-settings', payload),
}
