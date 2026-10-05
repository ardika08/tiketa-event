import { useCallback, useEffect, useState } from 'react'

/**
 * Hook sederhana untuk memanggil API saat mount / deps berubah.
 * fn harus mengembalikan Promise.
 */
export function useApi(fn, deps = []) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const load = useCallback(() => {
    setLoading(true)
    setError(null)
    return Promise.resolve()
      .then(fn)
      .then((res) => {
        setData(res)
        return res
      })
      .catch((err) => {
        setError(err)
        return null
      })
      .finally(() => setLoading(false))
  }, deps)

  useEffect(() => {
    load()
  }, [load])

  return { data, loading, error, reload: load, setData }
}
