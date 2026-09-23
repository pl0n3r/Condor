import { FormEvent, useEffect, useState } from 'react';
import {
  categoryPriceListPath,
  commercialCategoriesPath,
  commercialCategoryPath,
  customerPath,
  customersPath,
  priceListPath,
  priceListsPath,
  pricingPath,
  variantPricePath,
} from './api';

type Category = {
  id: string;
  name: string;
  slug: string;
  preferred_price_list_id: string | null;
};

type Customer = {
  id: string;
  name: string;
  email: string | null;
  phone: string | null;
  notes: string | null;
  commercial_category_id: string | null;
};

type PriceList = {
  id: string;
  name: string;
  slug: string;
  currency: string;
};

type Variant = {
  id: string;
  sku: string;
  name: string;
  product_name: string;
};

type VariantPrice = {
  id: string;
  price_list_id: string;
  variant_id: string;
  amount_minor: number;
};

type CustomerSnapshot = {
  customers: Customer[];
  categories: Category[];
};

type PricingSnapshot = {
  price_lists: PriceList[];
  variants: Variant[];
  variant_prices: VariantPrice[];
  rules: unknown[];
};

type Props = Readonly<{
  branchId: string;
  permissions: string[];
  csrfToken: string;
}>;

type LoadState =
  | { status: 'loading' }
  | {
      status: 'ready';
      customers: CustomerSnapshot | null;
      pricing: PricingSnapshot | null;
    }
  | { status: 'error'; message: string };

type Notice = { kind: 'success' | 'error'; text: string };

type CategoryDraft = {
  id: string | null;
  name: string;
  slug: string;
};

type CustomerDraft = {
  id: string | null;
  name: string;
  email: string;
  phone: string;
  notes: string;
  categoryId: string;
};

type PriceListDraft = {
  id: string | null;
  name: string;
  slug: string;
};

const emptyCategory: CategoryDraft = { id: null, name: '', slug: '' };
const emptyCustomer: CustomerDraft = {
  id: null,
  name: '',
  email: '',
  phone: '',
  notes: '',
  categoryId: '',
};
const emptyList: PriceListDraft = { id: null, name: '', slug: '' };

export function CommerceManagement({
  branchId,
  permissions,
  csrfToken,
}: Props) {
  const can = (permission: string) => permissions.includes(permission);
  const canViewCustomers = can('customers.view');
  const canCreateCustomers = can('customers.create');
  const canUpdateCustomers = can('customers.update');
  const canDeleteCustomers = can('customers.delete');
  const canViewPricing = can('pricing.view');
  const canCreatePricing = can('pricing.create');
  const canUpdatePricing = can('pricing.update');
  const canDeletePricing = can('pricing.delete');

  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [categoryDraft, setCategoryDraft] =
    useState<CategoryDraft>(emptyCategory);
  const [customerDraft, setCustomerDraft] =
    useState<CustomerDraft>(emptyCustomer);
  const [listDraft, setListDraft] =
    useState<PriceListDraft>(emptyList);
  const [priceListId, setPriceListId] = useState('');
  const [priceVariantId, setPriceVariantId] = useState('');
  const [pricePesos, setPricePesos] = useState('');

  async function loadCommerce() {
    if (!canViewCustomers && !canViewPricing) {
      return;
    }

    setState((current) => (
      current.status === 'ready' ? current : { status: 'loading' }
    ));
    try {
      const customerRequest = canViewCustomers
        ? fetch(customersPath(branchId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          })
        : Promise.resolve(null);
      const pricingRequest = canViewPricing
        ? fetch(pricingPath(branchId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          })
        : Promise.resolve(null);
      const [customerResponse, pricingResponse] = await Promise.all([
        customerRequest,
        pricingRequest,
      ]);

      if (customerResponse && !customerResponse.ok) {
        throw new Error(await responseMessage(
          customerResponse,
          'No fue posible cargar clientes.',
        ));
      }
      if (pricingResponse && !pricingResponse.ok) {
        throw new Error(await responseMessage(
          pricingResponse,
          'No fue posible cargar precios.',
        ));
      }

      const customers = customerResponse
        ? await customerResponse.json() as CustomerSnapshot
        : null;
      const pricing = pricingResponse
        ? await pricingResponse.json() as PricingSnapshot
        : null;

      setState({ status: 'ready', customers, pricing });
      setPriceListId((current) => (
        pricing?.price_lists.some((list) => list.id === current)
          ? current
          : pricing?.price_lists[0]?.id ?? ''
      ));
      setPriceVariantId((current) => (
        pricing?.variants.some((variant) => variant.id === current)
          ? current
          : pricing?.variants[0]?.id ?? ''
      ));
    } catch (error) {
      setState({
        status: 'error',
        message: error instanceof Error
          ? error.message
          : 'No fue posible cargar la información comercial.',
      });
    }
  }

  useEffect(() => {
    setNotice(null);
    setCategoryDraft(emptyCategory);
    setCustomerDraft(emptyCustomer);
    setListDraft(emptyList);
    setPriceListId('');
    setPriceVariantId('');
    setPricePesos('');

    if (canViewCustomers || canViewPricing) {
      void loadCommerce();
    }
  }, [branchId, canViewCustomers, canViewPricing]);

  async function saveCategory(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const editing = categoryDraft.id !== null;
    if (busy || (editing ? !canUpdateCustomers : !canCreateCustomers)) {
      return;
    }

    const endpoint = editing
      ? commercialCategoryPath(branchId, categoryDraft.id as string)
      : commercialCategoriesPath(branchId);
    const succeeded = await mutate(
      endpoint,
      editing ? 'PATCH' : 'POST',
      { name: categoryDraft.name, slug: categoryDraft.slug },
      editing ? 'Categoría actualizada.' : 'Categoría creada.',
    );
    if (succeeded) {
      setCategoryDraft(emptyCategory);
    }
  }

  async function saveCustomer(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const editing = customerDraft.id !== null;
    if (busy || (editing ? !canUpdateCustomers : !canCreateCustomers)) {
      return;
    }

    const endpoint = editing
      ? customerPath(branchId, customerDraft.id as string)
      : customersPath(branchId);
    const succeeded = await mutate(
      endpoint,
      editing ? 'PATCH' : 'POST',
      {
        name: customerDraft.name,
        email: customerDraft.email.trim() || null,
        phone: customerDraft.phone.trim() || null,
        notes: customerDraft.notes.trim() || null,
        category_id: customerDraft.categoryId || null,
      },
      editing ? 'Cliente actualizado.' : 'Cliente creado.',
    );
    if (succeeded) {
      setCustomerDraft(emptyCustomer);
    }
  }

  async function saveList(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const editing = listDraft.id !== null;
    if (busy || (editing ? !canUpdatePricing : !canCreatePricing)) {
      return;
    }

    const endpoint = editing
      ? priceListPath(branchId, listDraft.id as string)
      : priceListsPath(branchId);
    const succeeded = await mutate(
      endpoint,
      editing ? 'PATCH' : 'POST',
      { name: listDraft.name, slug: listDraft.slug },
      editing ? 'Lista actualizada.' : 'Lista creada.',
    );
    if (succeeded) {
      setListDraft(emptyList);
    }
  }

  async function saveVariantPrice(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdatePricing || busy || !priceListId || !priceVariantId) {
      return;
    }
    const pesos = Number(pricePesos);
    const maxSafePesos = Math.floor(Number.MAX_SAFE_INTEGER / 100);
    if (
      !Number.isSafeInteger(pesos)
      || pesos < 0
      || pesos > maxSafePesos
    ) {
      setNotice({
        kind: 'error',
        text: 'El precio debe ser un entero dentro del rango monetario permitido.',
      });
      return;
    }
    const amountMinor = pesos * 100;

    const succeeded = await mutate(
      variantPricePath(branchId, priceListId, priceVariantId),
      'PUT',
      { amount_minor: amountMinor },
      'Precio guardado.',
    );
    if (succeeded) {
      setPricePesos('');
    }
  }

  async function assignPreferredList(
    category: Category,
    listId: string,
  ) {
    if (!canUpdatePricing || busy) {
      return;
    }

    await mutate(
      categoryPriceListPath(branchId, category.id),
      'PATCH',
      { preferred_price_list_id: listId || null },
      'Lista preferida actualizada.',
    );
  }

  async function deactivate(
    endpoint: string,
    success: string,
  ) {
    await mutate(endpoint, 'DELETE', null, success);
  }

  async function mutate(
    endpoint: string,
    method: string,
    payload: Record<string, unknown> | null,
    success: string,
  ): Promise<boolean> {
    setBusy(true);
    setNotice(null);
    try {
      const response = await fetch(endpoint, {
        method,
        credentials: 'same-origin',
        headers: payload === null
          ? { 'X-CSRF-Token': csrfToken }
          : mutationHeaders(csrfToken),
        body: payload === null ? undefined : JSON.stringify(payload),
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible completar la operación.',
        ));
      }

      setNotice({ kind: 'success', text: success });
      await loadCommerce();
      return true;
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error
          ? error.message
          : 'No fue posible completar la operación.',
      });
      return false;
    } finally {
      setBusy(false);
    }
  }

  if (!canViewCustomers && !canViewPricing) {
    return (
      <section
        id="commerce"
        className="catalog-panel"
        aria-labelledby="commerce-title"
      >
        <span className="eyebrow">Ventas</span>
        <h2 id="commerce-title">Comercial</h2>
        <p className="muted">
          No tienes permisos para consultar clientes ni precios.
        </p>
      </section>
    );
  }

  const ready = state.status === 'ready' ? state : null;
  const customers = ready?.customers;
  const pricing = ready?.pricing;

  return (
    <section
      id="commerce"
      className="catalog-panel"
      aria-labelledby="commerce-title"
    >
      <div className="section-heading">
        <div>
          <span className="eyebrow">Ventas</span>
          <h2 id="commerce-title">Comercial</h2>
          <p className="muted">
            Clientes, categorías y precios reutilizables por los canales
            de venta. Pedidos y pagos se incorporan en slices posteriores.
          </p>
        </div>
      </div>

      {notice && (
        <output
          className={
            notice.kind === 'error'
              ? 'catalog-notice catalog-notice-error'
              : 'catalog-notice'
          }
          aria-live="polite"
        >
          {notice.text}
        </output>
      )}

      {state.status === 'loading' && (
        <output className="catalog-state muted" aria-live="polite">
          Cargando clientes y precios…
        </output>
      )}

      {state.status === 'error' && (
        <div className="catalog-state catalog-error" role="alert">
          <strong>No pudimos cargar el módulo comercial.</strong>
          <span>{state.message}</span>
          <button
            type="button"
            className="button button-secondary"
            onClick={() => void loadCommerce()}
          >
            Reintentar
          </button>
        </div>
      )}

      {customers && (
        <div className="catalog-list">
          <article className="catalog-card">
            <div>
              <span className="eyebrow">Segmentación</span>
              <h3>Categorías comerciales</h3>
              <p className="muted">
                Usa nombres propios de tu negocio; Condor no fija
                «detal» ni «mayorista».
              </p>
            </div>

            {(canCreateCustomers || categoryDraft.id) && (
              <form
                className="catalog-editor"
                onSubmit={saveCategory}
              >
                <div className="catalog-form-grid">
                  <label className="field">
                    <span>Nombre de categoría</span>
                    <input
                      required
                      maxLength={160}
                      value={categoryDraft.name}
                      onChange={(event) => setCategoryDraft((current) => ({
                        ...current,
                        name: event.target.value,
                      }))}
                    />
                  </label>
                  <label className="field">
                    <span>Slug de categoría</span>
                    <input
                      required
                      maxLength={120}
                      pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                      value={categoryDraft.slug}
                      onChange={(event) => setCategoryDraft((current) => ({
                        ...current,
                        slug: event.target.value.toLowerCase(),
                      }))}
                    />
                  </label>
                </div>
                <div className="catalog-actions">
                  <button type="submit" className="button" disabled={busy}>
                    {categoryDraft.id ? 'Guardar categoría' : 'Crear categoría'}
                  </button>
                  {categoryDraft.id && (
                    <button
                      type="button"
                      className="button button-secondary"
                      onClick={() => setCategoryDraft(emptyCategory)}
                    >
                      Cancelar
                    </button>
                  )}
                </div>
              </form>
            )}

            <div className="catalog-variant-list">
              {customers.categories.map((category) => (
                <div className="catalog-variant-row" key={category.id}>
                  <div>
                    <strong>{category.name}</strong>
                    <span className="muted">{category.slug}</span>
                  </div>
                  <div className="catalog-actions">
                    {canViewPricing && pricing && (
                      <label className="field">
                        <span>Lista preferida</span>
                        <select
                          aria-label={'Lista preferida de ' + category.name}
                          value={category.preferred_price_list_id ?? ''}
                          disabled={!canUpdatePricing}
                          onChange={(event) => void assignPreferredList(
                            category,
                            event.target.value,
                          )}
                        >
                          <option value="">Sin preferencia</option>
                          {pricing.price_lists.map((list) => (
                            <option value={list.id} key={list.id}>
                              {list.name}
                            </option>
                          ))}
                        </select>
                      </label>
                    )}
                    {canUpdateCustomers && (
                      <button
                        type="button"
                        className="button button-secondary"
                        onClick={() => setCategoryDraft({
                          id: category.id,
                          name: category.name,
                          slug: category.slug,
                        })}
                      >
                        Editar
                      </button>
                    )}
                    {canDeleteCustomers && (
                      <button
                        type="button"
                        className="button button-secondary"
                        disabled={busy}
                        onClick={() => void deactivate(
                          commercialCategoryPath(branchId, category.id),
                          'Categoría desactivada.',
                        )}
                      >
                        Desactivar
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </article>

          <article className="catalog-card">
            <div>
              <span className="eyebrow">Base comercial</span>
              <h3>Clientes</h3>
            </div>

            {(canCreateCustomers || customerDraft.id) && (
              <form className="catalog-editor" onSubmit={saveCustomer}>
                <div className="catalog-form-grid">
                  <label className="field">
                    <span>Nombre del cliente</span>
                    <input
                      required
                      maxLength={180}
                      value={customerDraft.name}
                      onChange={(event) => setCustomerDraft((current) => ({
                        ...current,
                        name: event.target.value,
                      }))}
                    />
                  </label>
                  <label className="field">
                    <span>Correo del cliente</span>
                    <input
                      type="email"
                      maxLength={254}
                      value={customerDraft.email}
                      onChange={(event) => setCustomerDraft((current) => ({
                        ...current,
                        email: event.target.value,
                      }))}
                    />
                  </label>
                  <label className="field">
                    <span>Teléfono del cliente</span>
                    <input
                      type="tel"
                      maxLength={40}
                      value={customerDraft.phone}
                      onChange={(event) => setCustomerDraft((current) => ({
                        ...current,
                        phone: event.target.value,
                      }))}
                    />
                  </label>
                  <label className="field">
                    <span>Observaciones</span>
                    <textarea
                      maxLength={5000}
                      value={customerDraft.notes}
                      onChange={(event) => setCustomerDraft((current) => ({
                        ...current,
                        notes: event.target.value,
                      }))}
                    />
                  </label>
                  <label className="field">
                    <span>Categoría</span>
                    <select
                      value={customerDraft.categoryId}
                      onChange={(event) => setCustomerDraft((current) => ({
                        ...current,
                        categoryId: event.target.value,
                      }))}
                    >
                      <option value="">Sin categoría</option>
                      {customers.categories.map((category) => (
                        <option value={category.id} key={category.id}>
                          {category.name}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
                <div className="catalog-actions">
                  <button type="submit" className="button" disabled={busy}>
                    {customerDraft.id ? 'Guardar cliente' : 'Crear cliente'}
                  </button>
                  {customerDraft.id && (
                    <button
                      type="button"
                      className="button button-secondary"
                      onClick={() => setCustomerDraft(emptyCustomer)}
                    >
                      Cancelar
                    </button>
                  )}
                </div>
              </form>
            )}

            {customers.customers.length === 0 ? (
              <p className="catalog-variant-empty muted">
                Todavía no hay clientes activos.
              </p>
            ) : (
              <div className="catalog-variant-list">
                {customers.customers.map((customer) => {
                  const category = customers.categories.find(
                    (candidate) => (
                      candidate.id === customer.commercial_category_id
                    ),
                  );
                  return (
                    <div className="catalog-variant-row" key={customer.id}>
                      <div>
                        <strong>{customer.name}</strong>
                        <span className="muted">
                          {customer.email ?? 'Sin correo'}
                          {' · '}
                          {customer.phone ?? 'Sin teléfono'}
                          {' · '}
                          {category?.name ?? 'Sin categoría'}
                        </span>
                        {customer.notes && (
                          <span className="muted">{customer.notes}</span>
                        )}
                      </div>
                      <div className="catalog-actions">
                        {canUpdateCustomers && (
                          <button
                            type="button"
                            className="button button-secondary"
                            onClick={() => setCustomerDraft({
                              id: customer.id,
                              name: customer.name,
                              email: customer.email ?? '',
                              phone: customer.phone ?? '',
                              notes: customer.notes ?? '',
                              categoryId:
                                customer.commercial_category_id ?? '',
                            })}
                          >
                            Editar
                          </button>
                        )}
                        {canDeleteCustomers && (
                          <button
                            type="button"
                            className="button button-secondary"
                            disabled={busy}
                            onClick={() => void deactivate(
                              customerPath(branchId, customer.id),
                              'Cliente desactivado.',
                            )}
                          >
                            Desactivar
                          </button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </article>
        </div>
      )}

      {pricing && (
        <div className="catalog-list">
          <article className="catalog-card">
            <div>
              <span className="eyebrow">Tarifas</span>
              <h3>Listas de precios</h3>
              <p className="muted">
                Una lista define el precio base; las reglas avanzadas
                resuelven como máximo un ajuste ganador.
              </p>
            </div>

            {(canCreatePricing || listDraft.id) && (
              <form className="catalog-editor" onSubmit={saveList}>
                <div className="catalog-form-grid">
                  <label className="field">
                    <span>Nombre de lista</span>
                    <input
                      required
                      maxLength={160}
                      value={listDraft.name}
                      onChange={(event) => setListDraft((current) => ({
                        ...current,
                        name: event.target.value,
                      }))}
                    />
                  </label>
                  <label className="field">
                    <span>Slug de lista</span>
                    <input
                      required
                      maxLength={120}
                      pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                      value={listDraft.slug}
                      onChange={(event) => setListDraft((current) => ({
                        ...current,
                        slug: event.target.value.toLowerCase(),
                      }))}
                    />
                  </label>
                </div>
                <div className="catalog-actions">
                  <button type="submit" className="button" disabled={busy}>
                    {listDraft.id ? 'Guardar lista' : 'Crear lista'}
                  </button>
                  {listDraft.id && (
                    <button
                      type="button"
                      className="button button-secondary"
                      onClick={() => setListDraft(emptyList)}
                    >
                      Cancelar
                    </button>
                  )}
                </div>
              </form>
            )}

            <div className="catalog-variant-list">
              {pricing.price_lists.map((list) => (
                <div className="catalog-variant-row" key={list.id}>
                  <div>
                    <strong>{list.name}</strong>
                    <span className="muted">
                      {list.slug} · {list.currency}
                    </span>
                  </div>
                  <div className="catalog-actions">
                    {canUpdatePricing && (
                      <button
                        type="button"
                        className="button button-secondary"
                        onClick={() => setListDraft({
                          id: list.id,
                          name: list.name,
                          slug: list.slug,
                        })}
                      >
                        Editar
                      </button>
                    )}
                    {canDeletePricing && (
                      <button
                        type="button"
                        className="button button-secondary"
                        disabled={busy}
                        onClick={() => void deactivate(
                          priceListPath(branchId, list.id),
                          'Lista desactivada.',
                        )}
                      >
                        Desactivar
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </article>

          {canUpdatePricing
            && pricing.price_lists.length > 0
            && pricing.variants.length > 0 && (
            <form className="catalog-card" onSubmit={saveVariantPrice}>
              <div>
                <span className="eyebrow">Precio base</span>
                <h3>Precio por variante</h3>
              </div>
              <div className="catalog-form-grid">
                <label className="field">
                  <span>Lista</span>
                  <select
                    value={priceListId}
                    onChange={(event) => setPriceListId(event.target.value)}
                  >
                    {pricing.price_lists.map((list) => (
                      <option value={list.id} key={list.id}>
                        {list.name}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="field">
                  <span>Variante</span>
                  <select
                    value={priceVariantId}
                    onChange={(event) => setPriceVariantId(
                      event.target.value,
                    )}
                  >
                    {pricing.variants.map((variant) => (
                      <option value={variant.id} key={variant.id}>
                        {variant.product_name} · {variant.sku}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="field">
                  <span>Precio (COP)</span>
                  <input
                    required
                    inputMode="numeric"
                    pattern="[0-9]+"
                    value={pricePesos}
                    onChange={(event) => setPricePesos(event.target.value)}
                  />
                </label>
              </div>
              <button type="submit" className="button" disabled={busy}>
                Guardar precio
              </button>
            </form>
          )}

          {pricing.variant_prices.length > 0 && (
            <article className="catalog-card">
              <div>
                <span className="eyebrow">Precios vigentes</span>
                <h3>Precios configurados</h3>
              </div>
              <div className="catalog-variant-list">
                {pricing.variant_prices.map((price) => {
                  const list = pricing.price_lists.find(
                    (candidate) => candidate.id === price.price_list_id,
                  );
                  const variant = pricing.variants.find(
                    (candidate) => candidate.id === price.variant_id,
                  );
                  return (
                    <div className="catalog-variant-row" key={price.id}>
                      <div>
                        <strong>
                          {variant?.product_name ?? 'Variante'}
                          {' · '}
                          {variant?.sku ?? price.variant_id}
                        </strong>
                        <span className="muted">
                          {list?.name ?? 'Lista'}
                          {' · '}
                          {formatCop(price.amount_minor)}
                        </span>
                      </div>
                    </div>
                  );
                })}
              </div>
            </article>
          )}
        </div>
      )}
    </section>
  );
}

function formatCop(amountMinor: number): string {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(amountMinor / 100);
}

function mutationHeaders(csrfToken: string): HeadersInit {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken,
  };
}

async function responseMessage(
  response: Response,
  fallback: string,
): Promise<string> {
  try {
    const payload = await response.json() as {
      message?: string;
      error?: string;
    };
    return payload.message ?? payload.error ?? fallback;
  } catch {
    return fallback;
  }
}
