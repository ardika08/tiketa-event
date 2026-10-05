import { useEffect, useRef, useState } from 'react'
import { ImagePlus, Loader2, Upload, ImageOff } from 'lucide-react'
import { cn } from '../lib/utils'
import { partnerApi } from '../lib/api'

/**
 * Pemilih gambar dengan unggahan nyata ke server.
 * value    : URL gambar tersimpan
 * onChange : (url) => void
 * folder   : tickets | events | logos | seat-plans | proofs
 * uploadFn : (file, folder) => Promise<{data:{url}}>  (default: partnerApi.upload)
 */
export function ImagePicker({ value, onChange, folder = 'tickets', label = 'Unggah Gambar', className, previewClassName, uploadFn = partnerApi.upload }) {
  const inputRef = useRef(null)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState('')
  const [failed, setFailed] = useState(false)

  // Reset status error gambar setiap kali URL berubah.
  useEffect(() => {
    setFailed(false)
  }, [value])

  const pick = () => inputRef.current?.click()

  const handleFile = async (event) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return

    setError('')
    setUploading(true)
    try {
      const res = await uploadFn(file, folder)
      setFailed(false)
      onChange(res.data.url)
    } catch (err) {
      setError(err.message)
    } finally {
      setUploading(false)
    }
  }

  const hapus = () => {
    setError('')
    setFailed(false)
    onChange('')
  }

  return (
    <div className={cn('flex flex-wrap items-center gap-3', className)}>
      <button
        type="button"
        onClick={pick}
        disabled={uploading}
        className="inline-flex max-w-full shrink-0 cursor-pointer items-center gap-2 rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
      >
        {uploading ? <Loader2 size={15} className="animate-spin" /> : <Upload size={15} />}
        {uploading ? 'Mengunggah...' : (value ? 'Ganti Gambar' : label)}
      </button>
      <input ref={inputRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={handleFile} />

      {value && (
        <button
          type="button"
          onClick={hapus}
          className="shrink-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50"
        >
          Hapus
        </button>
      )}

      <div className={cn('h-11 w-16 shrink-0 overflow-hidden rounded-lg bg-slate-100', previewClassName)}>
        {value && !failed ? (
          <img
            src={value}
            alt="pratinjau"
            className="h-full w-full object-cover"
            onError={() => setFailed(true)}
          />
        ) : failed ? (
          <div className="grid h-full w-full place-items-center text-rose-300" title="Gambar gagal dimuat">
            <ImageOff size={16} />
          </div>
        ) : (
          <div className="grid h-full w-full place-items-center text-slate-300"><ImagePlus size={16} /></div>
        )}
      </div>

      {failed && <p className="w-full text-xs font-medium text-rose-600">Gambar tidak dapat ditampilkan. Coba unggah ulang.</p>}
      {error && <p className="w-full text-xs font-medium text-rose-600">{error}</p>}
    </div>
  )
}