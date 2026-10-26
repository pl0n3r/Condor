import { ChangeEvent, FormEvent, useEffect, useState } from 'react';
import { ServiceItemsPanel } from './ServiceItemsPanel';
import {
  catalogProductPath,
  catalogProductsPath,
  catalogVariantPath,
  catalogVariantsPath,
} from './api';

type Variant = {
  id: string;
  sku: string;
  name: string;
};

type Product = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  allow_backorder: boolean;
  variants: Variant[];
};

type Props = Readonly<{
  branchId: string;
  permissions: string[];
  csrfToken: string;
}>;

type ProductDraft = {
  id: string | null;
  name: string;
  slug: string;
  description: string;
  allowBackorder: boolean;
};

type VariantDraft = {
  productId: string;
  id: string | null;
  sku: string;
  name: string;
};

type LoadState =
  | { status: 'loading' }
  | { status: 'ready'; products: Product[] }
  | { status: 'error'; message: string };

type Notice = {
  kind: 'success' | 'error';
  text: string;
};

const emptyProductDraft: ProductDraft = {
  id: null,
  name: '',
  slug: '',
  description: '',
  allowBackorder: false,
};

export function CatalogManagement({
  branchId,
  permissions,
  csrfToken,
}: Props) {
  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [productDraft, setProductDraft] =
    useState<ProductDraft>(emptyProductDraft);
  const [productEditorOpen, setProductEditorOpen] = useState(false);
  const [variantDraft, setVariantDraft] =
    useState<VariantDraft | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);

  const can = (permission: string) => permissions.includes(permission);
  const canView = can('catalog.view');
  const canCreate = can('catalog.create');
  const canUpdate = can('catalog.update');
  const canDelete = can('catalog.delete');

  async function loadCatalog() {
    if (!canView) {
      return;
    }

    setState({ status: 'loading' });

    try {
      const productEndpoint = catalogProductsPath(branchId);
      const response = await fetch(productEndpoint, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible cargar el catálogo.',
        ));
      }

      const payload = await response.json() as { products: Product[] };
      if (!Array.isArray(payload.products)) {
        throw new Error('La respuesta del catálogo no es válida.');
      }
      // El endpoint de productos también devuelve ítems de servicio, sin kind.
      // Excluirlos de la interfaz física usando el catálogo de servicios real.
      const serviceResponse = await fetch(
        productEndpoint.replace(/\/products$/, '/services'),
        { credentials: 'same-origin', headers: { Accept: 'application/json' } },
      );
      if (!serviceResponse.ok) {
        throw new Error(await responseMessage(
          serviceResponse,
          'No se pudo distinguir productos de servicios.',
        ));
      }
      const servicePayload = await serviceResponse.json() as {
        services?: Array<{ id: string }>;
      };
      if (!Array.isArray(servicePayload.services)) {
        throw new Error('No se pudo distinguir productos de servicios.');
      }
      const serviceIds = new Set(servicePayload.services.map(service => service.id));
      setState({
        status: 'ready',
        products: payload.products.filter(product => !serviceIds.has(product.id)),
      });
    } catch (error) {
      setState({
        status: 'error',
        message: error instanceof Error
          ? error.message
          : 'No fue posible cargar el catálogo.',
      });
    }
  }

  useEffect(() => {
    setProductDraft(emptyProductDraft);
    setProductEditorOpen(false);
    setVariantDraft(null);
    setNotice(null);

    if (canView) {
      void loadCatalog();
    }
  }, [branchId, canView]);

  async function saveProduct(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || (!canCreate && productDraft.id === null)) {
      return;
    }
    if (productDraft.id !== null && !canUpdate) {
      return;
    }

    setBusy(true);
    setNotice(null);

    try {
      const editing = productDraft.id !== null;
      const endpoint = editing
        ? catalogProductPath(branchId, productDraft.id as string)
        : catalogProductsPath(branchId);
      const response = await fetch(endpoint, {
        method: editing ? 'PATCH' : 'POST',
        credentials: 'same-origin',
        headers: mutationHeaders(csrfToken),
        body: JSON.stringify({
          name: productDraft.name,
          slug: productDraft.slug,
          description: productDraft.description.trim() || null,
          allow_backorder: productDraft.allowBackorder,
        }),
      });

      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          editing
            ? 'No fue posible actualizar el producto.'
            : 'No fue posible crear el producto.',
        ));
      }

      setProductDraft(emptyProductDraft);
      setProductEditorOpen(false);
      setNotice({
        kind: 'success',
        text: editing ? 'Producto actualizado.' : 'Producto creado.',
      });
      await loadCatalog();
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error
          ? error.message
          : 'No fue posible guardar el producto.',
      });
    } finally {
      setBusy(false);
    }
  }

  async function deactivateProduct(product: Product) {
    if (!canDelete || busy) {
      return;
    }

    setBusy(true);
    setNotice(null);

    try {
      const response = await fetch(
        catalogProductPath(branchId, product.id),
        {
          method: 'DELETE',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrfToken },
        },
      );
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible desactivar el producto.',
        ));
      }

      if (productDraft.id === product.id) {
        setProductDraft(emptyProductDraft);
        setProductEditorOpen(false);
      }
      if (variantDraft?.productId === product.id) {
        setVariantDraft(null);
      }
      setNotice({
        kind: 'success',
        text: 'Producto y sus variantes activas fueron desactivados.',
      });
      await loadCatalog();
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error
          ? error.message
          : 'No fue posible desactivar el producto.',
      });
    } finally {
      setBusy(false);
    }
  }

  async function saveVariant(
    event: FormEvent<HTMLFormElement>,
    product: Product,
  ) {
    event.preventDefault();
    if (variantDraft?.productId !== product.id || busy) {
      return;
    }
    if (variantDraft.id === null && !canCreate) {
      return;
    }
    if (variantDraft.id !== null && !canUpdate) {
      return;
    }

    setBusy(true);
    setNotice(null);

    try {
      const editing = variantDraft.id !== null;
      const endpoint = editing
        ? catalogVariantPath(
            branchId,
            product.id,
            variantDraft.id as string,
          )
        : catalogVariantsPath(branchId, product.id);
      const response = await fetch(endpoint, {
        method: editing ? 'PATCH' : 'POST',
        credentials: 'same-origin',
        headers: mutationHeaders(csrfToken),
        body: JSON.stringify({
          sku: variantDraft.sku,
          name: variantDraft.name,
        }),
      });

      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          editing
            ? 'No fue posible actualizar la variante.'
            : 'No fue posible crear la variante.',
        ));
      }

      setVariantDraft(null);
      setNotice({
        kind: 'success',
        text: editing ? 'Variante actualizada.' : 'Variante creada.',
      });
      await loadCatalog();
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error
          ? error.message
          : 'No fue posible guardar la variante.',
      });
    } finally {
      setBusy(false);
    }
  }

  async function deactivateVariant(
    product: Product,
    variant: Variant,
  ) {
    if (!canDelete || busy) {
      return;
    }

    setBusy(true);
    setNotice(null);

    try {
      const response = await fetch(
        catalogVariantPath(branchId, product.id, variant.id),
        {
          method: 'DELETE',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrfToken },
        },
      );
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible desactivar la variante.',
        ));
      }

      if (variantDraft?.id === variant.id) {
        setVariantDraft(null);
      }
      setNotice({
        kind: 'success',
        text: 'Variante desactivada.',
      });
      await loadCatalog();
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error
          ? error.message
          : 'No fue posible desactivar la variante.',
      });
    } finally {
      setBusy(false);
    }
  }

  if (!canView) {
    return (
      <section
        id="catalog"
        className="catalog-panel"
        aria-labelledby="catalog-title"
      >
        <span className="eyebrow">Productos</span>
        <h2 id="catalog-title">Catálogo</h2>
        <p className="muted">
          No tienes permiso para consultar el catálogo en esta sede.
        </p>
      </section>
    );
  }

  return (
    <section
      id="catalog"
      className="catalog-panel"
      aria-labelledby="catalog-title"
    >
      <div className="section-heading">
        <div>
          <span className="eyebrow">Productos físicos</span>
          <h2 id="catalog-title">Catálogo</h2>
          <p className="muted">
            Define productos, variantes y su política de backorder.
            Las existencias se gestionan en la sección Inventario.
          </p>
        </div>
        {canCreate && !productEditorOpen && (
          <button
            type="button"
            className="button catalog-primary-action"
            onClick={() => {
              setProductDraft(emptyProductDraft);
              setProductEditorOpen(true);
              setNotice(null);
            }}
          >
            Nuevo producto
          </button>
        )}
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

      {canCreate && (
        <AdminCsvImport key={branchId} kind="catalog" branchId={branchId}
          csrfToken={csrfToken} onCompleted={() => void loadCatalog()} />
      )}

      {productEditorOpen && (
        <form className="catalog-editor" onSubmit={saveProduct}>
          <div className="section-heading compact">
            <div>
              <span className="eyebrow">
                {productDraft.id ? 'Editar producto' : 'Nuevo producto'}
              </span>
              <h3>
                {productDraft.id
                  ? 'Actualizar información'
                  : 'Crear producto'}
              </h3>
            </div>
            <button
              type="button"
              className="button button-secondary"
              disabled={busy}
              onClick={() => {
                setProductDraft(emptyProductDraft);
                setProductEditorOpen(false);
              }}
            >
              Cancelar
            </button>
          </div>

          <div className="catalog-form-grid">
            <label className="field">
              <span>Nombre</span>
              <input
                required
                maxLength={160}
                value={productDraft.name}
                onChange={(event) => setProductDraft((current) => ({
                  ...current,
                  name: event.target.value,
                }))}
              />
            </label>
            <label className="field">
              <span>Slug</span>
              <input
                required
                maxLength={120}
                pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                placeholder="camiseta-negra"
                value={productDraft.slug}
                onChange={(event) => setProductDraft((current) => ({
                  ...current,
                  slug: event.target.value.toLowerCase(),
                }))}
              />
            </label>
            <label className="field catalog-form-wide">
              <span>Descripción</span>
              <textarea
                maxLength={5000}
                rows={4}
                value={productDraft.description}
                onChange={(event) => setProductDraft((current) => ({
                  ...current,
                  description: event.target.value,
                }))}
              />
            </label>
            <label className="field catalog-form-wide">
              <span>Disponibilidad</span>
              <span>
                <input
                  type="checkbox"
                  checked={productDraft.allowBackorder}
                  onChange={(event) => setProductDraft((current) => ({
                    ...current,
                    allowBackorder: event.target.checked,
                  }))}
                />
                {' '}Permitir backorder cuando no haya stock disponible
              </span>
            </label>
          </div>

          <button className="button" type="submit" disabled={busy}>
            {submitLabel(
              busy,
              productDraft.id !== null,
              'Crear producto',
              'Guardar cambios',
            )}
          </button>
        </form>
      )}

      {state.status === 'loading' && (
        <output className="catalog-state muted" aria-live="polite">
          Cargando catálogo…
        </output>
      )}

      {state.status === 'error' && (
        <div className="catalog-state catalog-error" role="alert">
          <strong>No pudimos cargar el catálogo.</strong>
          <span>{state.message}</span>
          <button
            type="button"
            className="button button-secondary"
            onClick={() => void loadCatalog()}
          >
            Reintentar
          </button>
        </div>
      )}

      {state.status === 'ready' && state.products.length === 0 && (
        <div className="catalog-state catalog-empty">
          <strong>Aún no hay productos físicos.</strong>
          <span className="muted">
            Crea el primer producto para empezar a construir el catálogo
            de esta empresa.
          </span>
          {!canCreate && (
            <span className="muted">
              Tu rol permite consultar, pero no crear productos.
            </span>
          )}
        </div>
      )}

      {state.status === 'ready' && state.products.length > 0 && (
        <div className="catalog-list">
          {state.products.map((product) => (
            <article className="catalog-card" key={product.id}>
              <div className="catalog-card-heading">
                <div>
                  <span className="tenant-slug">{product.slug}</span>
                  <h3>{product.name}</h3>
                  {product.description && (
                    <p className="muted">{product.description}</p>
                  )}
                  <p className="muted">
                    Backorder: {product.allow_backorder ? 'permitido' : 'desactivado'}
                  </p>
                </div>
                <div className="catalog-actions">
                  {canUpdate && (
                    <button
                      type="button"
                      className="button button-secondary"
                      disabled={busy}
                      onClick={() => {
                        setProductDraft({
                          id: product.id,
                          name: product.name,
                          slug: product.slug,
                          description: product.description ?? '',
                          allowBackorder: product.allow_backorder,
                        });
                        setProductEditorOpen(true);
                        setVariantDraft(null);
                        setNotice(null);
                      }}
                    >
                      Editar
                    </button>
                  )}
                  {canDelete && (
                    <button
                      type="button"
                      className="button button-secondary"
                      disabled={busy}
                      onClick={() => void deactivateProduct(product)}
                    >
                      Desactivar
                    </button>
                  )}
                </div>
              </div>

              <div className="catalog-variants-heading">
                <div>
                  <strong>Variantes</strong>
                  <span className="muted">
                    {product.variants.length === 1
                      ? '1 variante activa'
                      : `${product.variants.length} variantes activas`}
                  </span>
                </div>
                {canCreate && variantDraft?.productId !== product.id && (
                  <button
                    type="button"
                    className="button button-secondary"
                    disabled={busy}
                    onClick={() => {
                      setVariantDraft({
                        productId: product.id,
                        id: null,
                        sku: '',
                        name: '',
                      });
                      setNotice(null);
                    }}
                  >
                    Añadir variante
                  </button>
                )}
              </div>

              {product.variants.length === 0 && (
                <p className="catalog-variant-empty muted">
                  Este producto todavía no tiene variantes activas.
                </p>
              )}

              {product.variants.length > 0 && (
                <div className="catalog-variant-list">
                  {product.variants.map((variant) => (
                    <div className="catalog-variant-row" key={variant.id}>
                      <div>
                        <code>{variant.sku}</code>
                        <span>{variant.name}</span>
                      </div>
                      <div className="catalog-actions">
                        {canUpdate && (
                          <button
                            type="button"
                            className="button button-secondary"
                            disabled={busy}
                            onClick={() => {
                              setVariantDraft({
                                productId: product.id,
                                id: variant.id,
                                sku: variant.sku,
                                name: variant.name,
                              });
                              setNotice(null);
                            }}
                          >
                            Editar
                          </button>
                        )}
                        {canDelete && (
                          <button
                            type="button"
                            className="button button-secondary"
                            disabled={busy}
                            onClick={() => void deactivateVariant(
                              product,
                              variant,
                            )}
                          >
                            Desactivar
                          </button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}

              {variantDraft?.productId === product.id && (
                <form
                  className="catalog-variant-editor"
                  onSubmit={(event) => void saveVariant(event, product)}
                >
                  <div className="catalog-form-grid">
                    <label className="field">
                      <span>SKU</span>
                      <input
                        required
                        maxLength={120}
                        pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,119}"
                        value={variantDraft.sku}
                        onChange={(event) => setVariantDraft((current) => (
                          current === null
                            ? null
                            : {
                                ...current,
                                sku: event.target.value,
                              }
                        ))}
                      />
                    </label>
                    <label className="field">
                      <span>Nombre de variante</span>
                      <input
                        required
                        maxLength={160}
                        value={variantDraft.name}
                        onChange={(event) => setVariantDraft((current) => (
                          current === null
                            ? null
                            : {
                                ...current,
                                name: event.target.value,
                              }
                        ))}
                      />
                    </label>
                  </div>
                  <div className="catalog-actions">
                    <button
                      type="submit"
                      className="button"
                      disabled={busy}
                    >
                      {submitLabel(
                        busy,
                        variantDraft.id !== null,
                        'Crear variante',
                        'Guardar variante',
                      )}
                    </button>
                    <button
                      type="button"
                      className="button button-secondary"
                      disabled={busy}
                      onClick={() => setVariantDraft(null)}
                    >
                      Cancelar
                    </button>
                  </div>
                </form>
              )}
            </article>
          ))}
        </div>
      )}
      <ServiceItemsPanel
        branchId={branchId}
        permissions={permissions}
        csrfToken={csrfToken}
      />
    </section>
  );
}

type CsvRow = { row: number; status: 'valid' | 'existing' | 'error'; errors?: string[] };
type CsvPreview = {
  rows: CsvRow[];
  total: number;
  valid: number;
  existing: number;
  errors: number;
};
type CsvImportProps = Readonly<{
  kind: 'catalog' | 'customers';
  branchId: string;
  csrfToken: string;
  onCompleted: () => void;
}>;

/** The preview never writes. Only an explicit, second action calls commit. */
export function AdminCsvImport({
  kind, branchId, csrfToken, onCompleted,
}: CsvImportProps) {
  const [file, setFile] = useState<File | null>(null);
  const [csv, setCsv] = useState<string | null>(null);
  const [preview, setPreview] = useState<CsvPreview | null>(null);
  const [phase, setPhase] = useState<'preview' | 'commit' | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [fileKey, setFileKey] = useState(0);
  const title = kind === 'catalog' ? 'productos' : 'clientes';
  const endpoint = kind === 'catalog'
    ? '/api/catalog/import'
    : '/api/customers/import';

  function selectCsv(event: ChangeEvent<HTMLInputElement>) {
    setFile(event.currentTarget.files?.[0] ?? null);
    setPreview(null);
    setCsv(null);
    setNotice(null);
  }

  async function requestPreview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!file || phase !== null) return;
    setPhase('preview');
    setPreview(null);
    setCsv(null);
    setNotice(null);
    try {
      if (file.size === 0 || file.size > 262144) {
        throw new Error('Selecciona un CSV de máximo 256 KiB, no vacío.');
      }
      const text = await file.text();
      if (!text.trim()) throw new Error('El CSV está vacío.');
      const response = await fetch(endpoint + '/preview', {
        method: 'POST', credentials: 'same-origin',
        headers: mutationHeaders(csrfToken),
        body: JSON.stringify({ branch_id: branchId, csv: text }),
      });
      if (!response.ok) {
        throw new Error(await responseMessage(response, 'No fue posible previsualizar.'));
      }
      const data = await response.json() as CsvPreview;
      if (!Array.isArray(data.rows) ||
          !Number.isInteger(data.total) ||
          !Number.isInteger(data.valid) ||
          !Number.isInteger(data.existing) ||
          !Number.isInteger(data.errors) ||
          data.total !== data.rows.length) {
        throw new Error('Respuesta de vista previa inválida.');
      }
      setCsv(text);
      setPreview(data);
    } catch (error) {
      setNotice({ kind: 'error', text: error instanceof Error
        ? error.message : 'No fue posible leer el archivo.' });
    } finally {
      setPhase(null);
    }
  }

  async function confirmImport() {
    if (!preview || csv === null || preview.valid === 0 || phase !== null) return;
    setPhase('commit');
    setNotice(null);
    try {
      const response = await fetch(endpoint + '/commit', {
        method: 'POST', credentials: 'same-origin',
        headers: mutationHeaders(csrfToken),
        body: JSON.stringify({ branch_id: branchId, csv }),
      });
      if (!response.ok) {
        throw new Error(await responseMessage(response, 'No fue posible importar.'));
      }
      const result = await response.json() as {
        created: number; existing: number; errors: number;
      };
      setPreview(null);
      setCsv(null);
      setFile(null);
      setFileKey((current) => current + 1);
      setNotice({ kind: 'success',
        text: 'Importación completada: ' + result.created + ' nuevos, ' +
          result.existing + ' existentes y ' + result.errors + ' errores.' });
      onCompleted();
    } catch (error) {
      // A failed/ambiguous commit must never be blindly repeated.
      setPreview(null);
      setCsv(null);
      setNotice({ kind: 'error', text: error instanceof Error
        ? error.message : 'No fue posible importar.' });
    } finally {
      setPhase(null);
    }
  }

  return (
    <article className="catalog-card" aria-label={'Importar ' + title + ' desde CSV'}>
      <div>
        <span className="eyebrow">Importación masiva</span>
        <h3>Importar {title} desde CSV</h3>
        <p className="muted">Sube el CSV, revisa errores por fila y confirma.
          La vista previa no guarda datos.</p>
      </div>
      <form className="catalog-editor"
        onSubmit={(event) => void requestPreview(event)}>
        <label className="field">
          <span>Archivo CSV (máximo 256 KiB y 500 filas)</span>
          <input key={fileKey} type="file" accept=".csv,text/csv"
            onChange={selectCsv} disabled={phase !== null} />
        </label>
        <button className="button button-secondary" type="submit"
          disabled={!file || phase !== null}>
          {phase === 'preview' ? 'Validando CSV…' : 'Generar vista previa'}
        </button>
      </form>
      {notice && (
        <output aria-live="polite"
          role={notice.kind === 'error' ? 'alert' : 'status'}
          className={notice.kind === 'error'
            ? 'catalog-notice catalog-notice-error' : 'catalog-notice'}>
          {notice.text}
        </output>
      )}
      {preview && (
        <div className="catalog-editor">
          <h4>Vista previa de {title}</h4>
          <p role="status" aria-live="polite">
            {preview.total} filas: {preview.valid} nuevas, {preview.existing}
            {' '}existentes y {preview.errors} con error.
          </p>
          <div className="catalog-table-scroll">
            <table>
              <caption>Validación y errores por fila del CSV</caption>
              <thead><tr><th scope="col">Fila</th><th scope="col">Estado</th>
                <th scope="col">Observaciones</th></tr></thead>
              <tbody>
                {preview.rows.map((row, index) => (
                  <tr key={row.row + '-' + index}>
                    <td>{row.row}</td>
                    <td>{row.status === 'valid' ? 'Nueva' :
                      row.status === 'existing' ? 'Existente' : 'Error'}</td>
                    <td>{row.errors?.join('; ') || 'Sin errores'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {preview.errors > 0 && (
            <p className="muted" role="status">
              Las filas con errores se omitirán; revisa el archivo antes de confirmar.
            </p>
          )}
          <button type="button" className="button"
            disabled={phase !== null || preview.valid === 0}
            onClick={() => void confirmImport()}>
            {phase === 'commit' ? 'Importando…' : 'Confirmar importación'}
          </button>
        </div>
      )}
    </article>
  );
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

function submitLabel(
  busy: boolean,
  editing: boolean,
  createLabel: string,
  updateLabel: string,
): string {
  if (busy) {
    return 'Guardando…';
  }

  if (editing) {
    return updateLabel;
  }

  return createLabel;
}
