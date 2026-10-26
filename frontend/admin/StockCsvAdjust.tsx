import { ChangeEvent, FormEvent, useEffect, useRef, useState } from 'react';

type CsvRow = {
  line: number;
  sku?: string;
  branch_id?: string;
  status?: 'valid' | 'applied' | 'replayed' | 'unchanged';
  current_quantity?: number;
  target_quantity?: number;
  delta?: number;
  error?: string;
};

type CsvReport = {
  mode: 'preview' | 'commit';
  file_sha256: string;
  applied: number;
  replayed: number;
  unchanged: number;
  rows: CsvRow[];
};

type Props = Readonly<{
  canUpdate: boolean;
  csrfToken: string;
  onCommitted: () => void;
}>;

const MAX_CSV_BYTES = 65536;
const ERRORS: Readonly<Record<string, string>> = {
  CSV_COLUMNS_INVALID: 'Columnas incompletas o incorrectas.',
  CSV_ROW_INVALID: 'SKU, sede o cantidad inválida.',
  CSV_DUPLICATE_SKU_BRANCH: 'SKU repetido para la misma sede.',
  CSV_ROW_SCOPE_OR_STOCK_INVALID: 'No se puede ajustar esta variante o sede.',
};

function rowResult(row: CsvRow): string {
  if (row.error) {
    return ERRORS[row.error] ?? 'Fila inválida.';
  }
  if (row.status === 'valid') {
    return 'Lista para confirmar';
  }
  if (row.status === 'applied') {
    return 'Ajuste aplicado';
  }
  if (row.status === 'replayed') {
    return 'Ajuste ya registrado';
  }
  return row.status === 'unchanged' ? 'Sin cambios' : 'Sin resultado';
}

async function errorMessage(response: Response): Promise<string> {
  try {
    const payload = await response.json() as { message?: string; error?: string };
    return payload.message ?? payload.error ?? 'No fue posible procesar el archivo CSV.';
  } catch {
    return 'No fue posible procesar el archivo CSV.';
  }
}

export function StockCsvAdjust({ canUpdate, csrfToken, onCommitted }: Props) {
  const [csv, setCsv] = useState('');
  const [fileName, setFileName] = useState('');
  const [status, setStatus] = useState<'idle' | 'reading' | 'previewing' | 'ready' | 'committing' | 'done' | 'error'>('idle');
  const [preview, setPreview] = useState<CsvReport | null>(null);
  const [result, setResult] = useState<CsvReport | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [message, setMessage] = useState('');
  const sequence = useRef(0);

  useEffect(() => () => { sequence.current += 1; }, []);

  async function selectFile(event: ChangeEvent<HTMLInputElement>) {
    const file = event.currentTarget.files?.[0];
    const attempt = ++sequence.current;
    setCsv('');
    setFileName('');
    setPreview(null);
    setResult(null);
    setConfirmed(false);
    setMessage('');
    if (!file) {
      setStatus('idle');
      return;
    }
    if (file.size > MAX_CSV_BYTES) {
      setStatus('error');
      setMessage('El archivo supera el máximo de 64 KB.');
      return;
    }
    setStatus('reading');
    try {
      const text = await file.text();
      if (attempt !== sequence.current) {
        return;
      }
      if (!text || new TextEncoder().encode(text).length > MAX_CSV_BYTES) {
        throw new Error('El archivo CSV está vacío o supera los 64 KB.');
      }
      setCsv(text);
      setFileName(file.name);
      setStatus('idle');
    } catch (error) {
      if (attempt !== sequence.current) {
        return;
      }
      setStatus('error');
      setMessage(error instanceof Error ? error.message : 'No fue posible leer el CSV.');
    }
  }

  async function sendCsv(mode: 'preview' | 'commit', content: string): Promise<CsvReport> {
    const response = await fetch('/api/inventory/adjust-csv/' + mode, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
      body: JSON.stringify({ csv: content }),
    });
    if (!response.ok) {
      throw new Error(await errorMessage(response));
    }
    const payload = await response.json() as CsvReport;
    if (payload.mode !== mode || !Array.isArray(payload.rows)
        || !/^[0-9a-f]{64}$/.test(payload.file_sha256)) {
      throw new Error('El servidor devolvió un reporte CSV inválido.');
    }
    return payload;
  }

  async function previewCsv(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || !csv || status === 'previewing' || status === 'committing') {
      return;
    }
    const attempt = ++sequence.current;
    setStatus('previewing');
    setPreview(null);
    setResult(null);
    setConfirmed(false);
    setMessage('');
    try {
      const data = await sendCsv('preview', csv);
      if (attempt !== sequence.current) {
        return;
      }
      setPreview(data);
      setStatus('ready');
    } catch (error) {
      if (attempt !== sequence.current) {
        return;
      }
      setStatus('error');
      setMessage(error instanceof Error ? error.message : 'No fue posible revisar el CSV.');
    }
  }

  const hasErrors = preview?.rows.some((row) => Boolean(row.error)) ?? false;
  const hasValidRows = preview?.rows.some((row) => row.status === 'valid') ?? false;

  async function commitCsv() {
    if (!canUpdate || !confirmed || !preview || preview.mode !== 'preview'
        || !hasValidRows || hasErrors || !csv || status !== 'ready') {
      return;
    }
    const attempt = ++sequence.current;
    const expectedHash = preview.file_sha256;
    setStatus('committing');
    setMessage('');
    try {
      const data = await sendCsv('commit', csv);
      if (attempt !== sequence.current) {
        return;
      }
      if (data.file_sha256 !== expectedHash) {
        throw new Error('El archivo cambió desde la vista previa.');
      }
      // The server applies independent CSV rows and can return partial errors
      // if stock changed since preview; never display an all-green receipt.
      const partialFailure = data.rows.some((row) => (
        Boolean(row.error)
        || (row.status !== 'applied'
          && row.status !== 'replayed'
          && row.status !== 'unchanged')
      ));
      setResult(data);
      setStatus(partialFailure ? 'error' : 'done');
      if (partialFailure) {
        setMessage('El resultado contiene filas no aplicadas. Revisa cada fila y los movimientos antes de repetir.');
      }
      setConfirmed(false);
      onCommitted();
    } catch (error) {
      if (attempt !== sequence.current) {
        return;
      }
      setStatus('error');
      setMessage(error instanceof Error ? error.message : 'No fue posible confirmar el ajuste.');
    }
  }

  if (!canUpdate) {
    return null;
  }

  const report = result ?? preview;
  return (
    <section className="catalog-editor" aria-labelledby="stock-csv-title">
      <h3 id="stock-csv-title">Ajustar existencias desde CSV</h3>
      <p className="muted">
        Formato: sku,branch_id,quantity. Carga máximo 100 filas y 64 KB.
        Revisa cada resultado antes de confirmar; la vista previa no modifica existencias.
      </p>
      <form onSubmit={(event) => void previewCsv(event)}>
        <label className="field">
          <span>Archivo CSV de inventario</span>
          <input
            type="file"
            accept=".csv,text/csv"
            onChange={(event) => void selectFile(event)}
            disabled={status === 'previewing' || status === 'committing'}
          />
        </label>
        {fileName && <p className="muted">Archivo elegido: {fileName}</p>}
        <button
          className="button"
          type="submit"
          disabled={!csv || status === 'reading' || status === 'previewing' || status === 'committing'}
        >
          {status === 'previewing' ? 'Revisando…' : 'Ver vista previa'}
        </button>
      </form>

      {(status === 'reading' || status === 'previewing' || status === 'committing') && (
        <output aria-live="polite">
          {status === 'reading' ? 'Leyendo archivo…'
            : status === 'previewing' ? 'Validando filas…' : 'Aplicando ajustes…'}
        </output>
      )}
      {message && <p className="alert alert-error" role="alert">{message}</p>}
      {report && (
        <div className="catalog-card">
          <h4>{result ? 'Resultado del ajuste' : 'Vista previa del ajuste'}</h4>
          {report.rows.length === 0 ? (
            <p className="muted">El archivo no contiene filas para ajustar.</p>
          ) : (
            <div className="catalog-variant-list">
              <table>
                <caption>Validación de existencias por fila</caption>
                <thead>
                  <tr>
                    <th scope="col">Fila</th>
                    <th scope="col">SKU</th>
                    <th scope="col">Sede</th>
                    <th scope="col">Actual</th>
                    <th scope="col">Objetivo</th>
                    <th scope="col">Estado</th>
                  </tr>
                </thead>
                <tbody>
                  {report.rows.map((row) => (
                    <tr key={row.line}>
                      <th scope="row">{row.line}</th>
                      <td>{row.sku ?? '—'}</td>
                      <td>{row.branch_id ?? '—'}</td>
                      <td>{row.current_quantity ?? '—'}</td>
                      <td>{row.target_quantity ?? '—'}</td>
                      <td>{rowResult(row)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {!result && hasErrors && (
            <p className="alert alert-error" role="alert">
              Hay errores por fila. Corrige el archivo y vuelve a generar la vista previa.
              No se aplicará ningún ajuste.
            </p>
          )}
          {!result && hasValidRows && !hasErrors && status === 'ready' && (
            <div>
              <label className="field">
                <input
                  type="checkbox"
                  checked={confirmed}
                  onChange={(event) => setConfirmed(event.target.checked)}
                />
                <span>Confirmo los ajustes mostrados en la vista previa.</span>
              </label>
              <button
                className="button"
                type="button"
                onClick={() => void commitCsv()}
                disabled={!confirmed || hasErrors || !hasValidRows}
              >
                Confirmar ajustes
              </button>
            </div>
          )}
          {result && !result.rows.some((row) => (
            Boolean(row.error)
            || (row.status !== 'applied'
              && row.status !== 'replayed'
              && row.status !== 'unchanged')
          )) && (
            <output className="alert alert-success" aria-live="polite">
              Ajustes aplicados: {result.applied}. Repetidos: {result.replayed}.
              Sin cambios: {result.unchanged}.
            </output>
          )}
        </div>
      )}
    </section>
  );
}
