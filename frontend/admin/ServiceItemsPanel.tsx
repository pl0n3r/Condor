import { FormEvent, useEffect, useState } from 'react';
import {
  catalogProductPath,
  catalogProductsPath,
  catalogVariantPath,
  pricingPath,
  variantPricePath,
} from './api';

type Price = { price_list_id: string; amount_minor: number; currency: string };
type Variant = { id: string; sku: string; name: string; prices: Price[] };
type Service = {
  id: string;
  name: string;
  slug: string;
  kind: 'service';
  description: string | null;
  duration_minutes: number | null;
  variants: Variant[];
};
type PriceList = { id: string; name: string; currency: string };
type State =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; services: Service[]; lists: PriceList[]; pricingError: string | null };
type Draft = {
  id: string | null;
  variantId: string | null;
  name: string;
  slug: string;
  description: string;
  sku: string;
  duration: string;
  priceListId: string;
  price: string;
};
type Props = Readonly<{ branchId: string; permissions: string[]; csrfToken: string }>;

const emptyDraft: Draft = {
  id: null, variantId: null, name: '', slug: '', description: '',
  sku: '', duration: '', priceListId: '', price: '',
};

function servicesPath(branchId: string): string {
  // Reuse the validated branch-ULID path from the existing API client.
  return catalogProductsPath(branchId).replace(/\/products$/, '/services');
}

function headers(csrfToken: string): HeadersInit {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken,
  };
}

async function responseError(response: Response, fallback: string): Promise<string> {
  try {
    const payload = await response.json() as { detail?: unknown; message?: unknown };
    if (typeof payload.detail === 'string') return payload.detail;
    if (typeof payload.message === 'string') return payload.message;
  } catch {
    // Errors without JSON use the safe, localized fallback.
  }
  return fallback;
}

function minorUnits(value: string): number | null {
  const match = /^(0|[1-9][0-9]{0,9})(?:[,.]([0-9]{1,2}))?$/.exec(value.trim());
  if (!match) return null;
  const total = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0') || 0);
  return Number.isSafeInteger(total) ? total : null;
}

function durationMinutes(value: string): number | null | undefined {
  if (value.trim() === '') return null;
  if (!/^[1-9][0-9]*$/.test(value)) return undefined;
  const number = Number(value);
  return Number.isSafeInteger(number) && number <= 525600 ? number : undefined;
}

function priceText(amountMinor: number): string {
  const whole = Math.trunc(amountMinor / 100);
  const cents = Math.abs(amountMinor % 100);
  return String(whole) + (cents ? ',' + String(cents).padStart(2, '0') : '');
}

function formatPrice(price: Price): string {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: price.currency,
  }).format(price.amount_minor / 100);
}

function startEditing(service: Service): Draft {
  const variant = service.variants[0];
  const price = variant?.prices[0];
  return {
    id: service.id,
    variantId: variant?.id ?? null,
    name: service.name,
    slug: service.slug,
    description: service.description ?? '',
    sku: variant?.sku ?? '',
    duration: service.duration_minutes === null ? '' : String(service.duration_minutes),
    priceListId: price?.price_list_id ?? '',
    price: price ? priceText(price.amount_minor) : '',
  };
}

export function ServiceItemsPanel({ branchId, permissions, csrfToken }: Props) {
  const canView = permissions.includes('catalog.view');
  const canCreate = permissions.includes('catalog.create');
  const canUpdate = permissions.includes('catalog.update');
  const canReadPrices = permissions.includes('pricing.view');
  const canUpdatePrices = permissions.includes('pricing.update');

  const [state, setState] = useState<State>({ status: 'loading' });
  const [draft, setDraft] = useState<Draft | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ kind: 'error' | 'success'; text: string } | null>(null);
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    setDraft(null);
    setNotice(null);
  }, [branchId]);

  useEffect(() => {
    let current = true;
    if (!canView) return () => { current = false; };
    setState({ status: 'loading' });
    void (async () => {
      try {
        const response = await fetch(servicesPath(branchId), {
          credentials: 'same-origin', headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(await responseError(response, 'No se pudieron cargar los servicios.'));
        const payload = await response.json() as { services?: Service[] };
        if (!Array.isArray(payload.services)) throw new Error('La respuesta del catálogo no es válida.');
        let lists: PriceList[] = [];
        let pricingError: string | null = null;
        if (canReadPrices) {
          try {
            const prices = await fetch(pricingPath(branchId), {
              credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            if (!prices.ok) throw new Error(await responseError(prices, 'No se pudieron consultar las listas de precios.'));
            const pricesPayload = await prices.json() as { price_lists?: PriceList[] };
            if (!Array.isArray(pricesPayload.price_lists)) throw new Error('Las listas de precios no son válidas.');
            lists = pricesPayload.price_lists;
          } catch (error) {
            pricingError = error instanceof Error ? error.message : 'No se pudieron cargar los precios.';
          }
        }
        if (current) setState({ status: 'ready', services: payload.services, lists, pricingError });
      } catch (error) {
        if (current) setState({
          status: 'error',
          message: error instanceof Error ? error.message : 'No se pudieron cargar los servicios.',
        });
      }
    })();
    return () => { current = false; };
  }, [branchId, canView, canReadPrices, refresh]);

  function change<K extends keyof Draft>(field: K, value: Draft[K]) {
    setDraft(current => current ? { ...current, [field]: value } : null);
  }

  async function checkedMutation(url: string, method: string, data: unknown, fallback: string) {
    const response = await fetch(url, {
      method, credentials: 'same-origin', headers: headers(csrfToken),
      body: JSON.stringify(data),
    });
    if (!response.ok) throw new Error(await responseError(response, fallback));
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!draft || busy || state.status !== 'ready') return;
    const editing = draft.id !== null;
    if (editing ? !canUpdate : !canCreate) return;

    const name = draft.name.trim();
    const slug = draft.slug.trim();
    const sku = draft.sku.trim();
    const duration = durationMinutes(draft.duration);
    const amount = minorUnits(draft.price);
    if (!name || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)
      || !/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/.test(sku)
      || duration === undefined) {
      setNotice({ kind: 'error', text: 'Revisa nombre, slug, SKU y duración (de 1 a 525600 minutos).' });
      return;
    }
    if (!editing && (!draft.priceListId || amount === null)) {
      setNotice({ kind: 'error', text: 'Elige una lista de precios y un precio válido.' });
      return;
    }
    if (editing && draft.priceListId && amount === null) {
      setNotice({ kind: 'error', text: 'El precio debe ser un valor válido.' });
      return;
    }

    setBusy(true);
    setNotice(null);
    try {
      if (!editing) {
        await checkedMutation(servicesPath(branchId), 'POST', {
          name, slug, description: draft.description.trim() || null,
          sku, duration_minutes: duration, price_list_id: draft.priceListId,
          amount_minor: amount,
        }, 'No fue posible crear el servicio.');
      } else {
        // The existing generic endpoints update metadata and pricing, not duration.
        await checkedMutation(catalogProductPath(branchId, draft.id as string), 'PATCH', {
          name, slug, description: draft.description.trim() || null,
        }, 'No fue posible actualizar el servicio.');
        if (draft.variantId !== null) {
          await checkedMutation(catalogVariantPath(branchId, draft.id as string, draft.variantId), 'PATCH', {
            sku, name,
          }, 'Se actualizó la descripción, pero no se pudo actualizar el SKU.');
          if (canUpdatePrices && draft.priceListId && amount !== null) {
            await checkedMutation(variantPricePath(branchId, draft.priceListId, draft.variantId), 'PUT', {
              amount_minor: amount,
            }, 'Se actualizaron los datos, pero no se pudo guardar el precio.');
          }
        }
      }
      setDraft(null);
      setNotice({ kind: 'success', text: editing ? 'Servicio actualizado.' : 'Servicio creado.' });
      setRefresh(value => value + 1);
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error ? error.message : 'No se pudo guardar el servicio.',
      });
      setRefresh(value => value + 1);
    } finally {
      setBusy(false);
    }
  }

  if (!canView) return null;

  return (
    <section className="catalog-panel" aria-labelledby="service-items-title">
      <div className="section-heading">
        <div>
          <span className="eyebrow">Servicios · sin inventario</span>
          <h3 id="service-items-title">Servicios vendibles</h3>
          <p className="muted">Los servicios tienen precio por lista y duración opcional. No generan movimientos de stock.</p>
        </div>
        {canCreate && draft === null && state.status === 'ready' && (
          <button type="button" className="button" onClick={() => {
            setDraft({ ...emptyDraft, priceListId: state.lists[0]?.id ?? '' });
            setNotice(null);
          }}>Nuevo servicio</button>
        )}
      </div>
      {notice && (
        <output className={notice.kind === 'error' ? 'catalog-notice catalog-notice-error' : 'catalog-notice'} aria-live="polite">
          {notice.text}
        </output>
      )}
      {state.status === 'loading' && <output className="catalog-state muted" aria-live="polite">Cargando servicios…</output>}
      {state.status === 'error' && (
        <div className="catalog-state catalog-error" role="alert">
          <span>{state.message}</span>
          <button type="button" className="button button-secondary" onClick={() => setRefresh(value => value + 1)}>Reintentar</button>
        </div>
      )}
      {state.status === 'ready' && (
        <>
          {state.pricingError && <p role="alert">{state.pricingError}</p>}
          {!canReadPrices && canCreate && <p className="muted">Necesitas permiso para consultar listas de precios antes de crear servicios.</p>}
          {state.services.length === 0 && (
            <div className="catalog-state catalog-empty"><strong>Aún no hay servicios.</strong><span>Crea un servicio para ofrecerlo sin controlar existencias.</span></div>
          )}
          <div className="catalog-list">
            {state.services.filter(service => service.kind === 'service').map(service => (
              <article className="catalog-card" key={service.id}>
                <div className="catalog-card-heading">
                  <div>
                    <span className="tenant-slug">Servicio · {service.slug}</span>
                    <h3>{service.name}</h3>
                    <p className="muted">{service.description || 'Sin descripción'}</p>
                    <p className="muted">Duración: {service.duration_minutes === null ? 'No especificada' : String(service.duration_minutes) + ' min'}</p>
                    {service.variants.map(variant => (
                      <div key={variant.id}>
                        <code>{variant.sku}</code>
                        {variant.prices.map(price => (
                          <p className="muted" key={price.price_list_id}>
                            {state.lists.find(list => list.id === price.price_list_id)?.name ?? 'Lista de precios'}: {formatPrice(price)}
                          </p>
                        ))}
                      </div>
                    ))}
                  </div>
                  {canUpdate && draft === null && (
                    <button type="button" className="button button-secondary" onClick={() => {
                      setDraft(startEditing(service));
                      setNotice(null);
                    }}>Editar servicio</button>
                  )}
                </div>
              </article>
            ))}
          </div>
          {draft !== null && (
            <form className="catalog-editor" onSubmit={event => void save(event)}>
              <div className="section-heading compact">
                <h3>{draft.id === null ? 'Nuevo servicio' : 'Editar servicio'}</h3>
                <button type="button" className="button button-secondary" disabled={busy} onClick={() => setDraft(null)}>Cancelar</button>
              </div>
              <div className="catalog-form-grid">
                <label className="field"><span>Nombre</span>
                  <input required maxLength={160} value={draft.name} onChange={event => change('name', event.target.value)} />
                </label>
                <label className="field"><span>Slug</span>
                  <input required maxLength={120} pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value={draft.slug} onChange={event => change('slug', event.target.value.toLowerCase())} />
                </label>
                <label className="field"><span>SKU</span>
                  <input required maxLength={120} pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,119}" value={draft.sku} onChange={event => change('sku', event.target.value)} />
                </label>
                <label className="field"><span>Duración opcional (minutos)</span>
                  <input type="number" min={1} max={525600} step={1} value={draft.duration}
                    disabled={draft.id !== null}
                    onChange={event => change('duration', event.target.value)} />
                </label>
                {draft.id !== null && <p className="muted">La API actual no permite cambiar la duración de un servicio existente.</p>}
                <label className="field catalog-form-wide"><span>Descripción</span>
                  <textarea rows={3} maxLength={5000} value={draft.description}
                    onChange={event => change('description', event.target.value)} />
                </label>
                <label className="field"><span>Lista de precios</span>
                  <select required={draft.id === null} value={draft.priceListId}
                    disabled={!canReadPrices || (draft.id !== null && !canUpdatePrices)}
                    onChange={event => {
                      change('priceListId', event.target.value);
                      if (draft.id !== null && state.services.length > 0) {
                        const service = state.services.find(item => item.id === draft.id);
                        const price = service?.variants[0]?.prices.find(item => item.price_list_id === event.target.value);
                        change('price', price ? priceText(price.amount_minor) : '');
                      }
                    }}>
                    <option value="">Selecciona una lista</option>
                    {state.lists.map(list => <option value={list.id} key={list.id}>{list.name} ({list.currency})</option>)}
                  </select>
                </label>
                <label className="field"><span>Precio en la moneda de la lista (sin miles)</span>
                  <input required={draft.id === null || !!draft.priceListId} inputMode="decimal"
                    placeholder="25000,00" pattern="[0-9]+([,.][0-9]{1,2})?"
                    disabled={draft.id !== null && !canUpdatePrices}
                    value={draft.price} onChange={event => change('price', event.target.value)} />
                </label>
              </div>
              <button className="button" type="submit"
                disabled={busy || (draft.id === null && (!canReadPrices || state.lists.length === 0))}>
                {busy ? 'Guardando…' : draft.id === null ? 'Crear servicio' : 'Guardar cambios'}
              </button>
            </form>
          )}
        </>
      )}
    </section>
  );
}
