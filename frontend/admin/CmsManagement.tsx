import { FormEvent, useEffect, useState } from 'react';
import {
  cmsBlockPath,
  cmsBlocksPath,
  cmsPagePath,
  cmsPagePublishPath,
  cmsPagesPath,
} from './api';

type CmsTheme = {
  id: string;
  key: string;
  name: string;
};

type CmsBlock = {
  id: string;
  type: string;
  payload: Record<string, unknown>;
  sort_order: number;
};

type CmsPage = {
  id: string;
  slug: string;
  title: string;
  status: 'draft' | 'published';
  published_at: string | null;
  theme: CmsTheme;
  blocks: CmsBlock[];
};

type Props = Readonly<{
  branchId: string;
  permissions: string[];
  csrfToken: string;
}>;

type State =
  | { status: 'loading' }
  | { status: 'ready'; themes: CmsTheme[]; pages: CmsPage[] }
  | { status: 'error'; message: string };

const BLOCK_TYPES = [
  'text',
  'image',
  'hero',
  'cta',
  'gallery',
  'divider',
] as const;

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
    const payload = await response.json() as { message?: unknown };
    return typeof payload.message === 'string' ? payload.message : fallback;
  } catch {
    return fallback;
  }
}

export function CmsManagement({
  branchId,
  permissions,
  csrfToken,
}: Props) {
  const canView = permissions.includes('site.view');
  const canUpdate = permissions.includes('site.update');
  const [state, setState] = useState<State>({ status: 'loading' });
  const [notice, setNotice] = useState<string | null>(null);

  async function load() {
    if (!canView) {
      return;
    }
    setState({ status: 'loading' });
    try {
      const response = await fetch(cmsPagesPath(branchId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible cargar el contenido del sitio.',
        ));
      }
      const payload = await response.json() as {
        themes: CmsTheme[];
        pages: CmsPage[];
      };
      setState({ status: 'ready', ...payload });
    } catch (error) {
      setState({
        status: 'error',
        message: error instanceof Error
          ? error.message
          : 'No fue posible cargar el contenido del sitio.',
      });
    }
  }

  useEffect(() => {
    setNotice(null);
    if (canView) {
      void load();
    }
  }, [branchId, canView]);

  if (!canView) {
    return (
      <section className="catalog-panel" aria-labelledby="cms-title">
        <h2 id="cms-title">Sitio y contenido</h2>
        <div className="alert alert-error" role="alert">
          No tienes permiso para consultar el CMS de esta empresa.
        </div>
      </section>
    );
  }

  return (
    <section className="catalog-panel" aria-labelledby="cms-title">
      <div className="section-heading">
        <div>
          <h2 id="cms-title">Sitio y contenido</h2>
          <p className="muted">
            Edita borradores y publícalos cuando el contenido esté listo.
          </p>
        </div>
      </div>

      {notice && (
        <output className="catalog-notice" aria-live="polite">
          {notice}
        </output>
      )}

      {state.status === 'loading' && (
        <output className="catalog-state" aria-live="polite">
          Cargando páginas y bloques…
        </output>
      )}

      {state.status === 'error' && (
        <div className="catalog-state catalog-error" role="alert">
          <span>{state.message}</span>
          <button className="button button-secondary" onClick={() => void load()}>
            Reintentar
          </button>
        </div>
      )}

      {state.status === 'ready' && state.pages.length === 0 && (
        <div className="catalog-state">
          <strong>No hay páginas CMS configuradas.</strong>
          <span className="muted">
            El editor aparecerá cuando exista una página tenant-scoped.
          </span>
        </div>
      )}

      {state.status === 'ready' && (
        <div className="catalog-list">
          {state.pages.map((page) => (
            <CmsPageEditor
              key={page.id}
              branchId={branchId}
              page={page}
              themes={state.themes}
              canUpdate={canUpdate}
              csrfToken={csrfToken}
              onChanged={async (message) => {
                setNotice(message);
                await load();
              }}
            />
          ))}
        </div>
      )}
    </section>
  );
}

type PageEditorProps = Readonly<{
  branchId: string;
  page: CmsPage;
  themes: CmsTheme[];
  canUpdate: boolean;
  csrfToken: string;
  onChanged: (message: string) => Promise<void>;
}>;

function CmsPageEditor({
  branchId,
  page,
  themes,
  canUpdate,
  csrfToken,
  onChanged,
}: PageEditorProps) {
  const [title, setTitle] = useState(page.title);
  const [slug, setSlug] = useState(page.slug);
  const [themeId, setThemeId] = useState(page.theme.id);
  const [busy, setBusy] = useState(false);
  const [blockDraft, setBlockDraft] = useState({
    type: 'text',
    payload: '{"text":""}',
    sortOrder: page.blocks.length,
  });

  async function savePage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || page.status !== 'draft' || busy) {
      return;
    }
    setBusy(true);
    try {
      const response = await fetch(cmsPagePath(branchId, page.id), {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: mutationHeaders(csrfToken),
        body: JSON.stringify({
          theme_id: themeId,
          slug,
          title,
        }),
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible guardar la página.',
        ));
      }
      await onChanged('Borrador actualizado.');
    } catch (error) {
      await onChanged(
        error instanceof Error ? error.message : 'No fue posible guardar la página.',
      );
    } finally {
      setBusy(false);
    }
  }

  async function publishPage() {
    if (!canUpdate || page.status !== 'draft' || busy) {
      return;
    }
    setBusy(true);
    try {
      const response = await fetch(cmsPagePublishPath(branchId, page.id), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible publicar la página.',
        ));
      }
      await onChanged('Página publicada.');
    } catch (error) {
      await onChanged(
        error instanceof Error ? error.message : 'No fue posible publicar la página.',
      );
    } finally {
      setBusy(false);
    }
  }

  async function addBlock(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || page.status !== 'draft' || busy) {
      return;
    }
    let payload: Record<string, unknown>;
    try {
      const parsed: unknown = JSON.parse(blockDraft.payload);
      if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
        throw new Error();
      }
      payload = parsed as Record<string, unknown>;
    } catch {
      await onChanged('El payload del bloque debe ser un objeto JSON válido.');
      return;
    }

    setBusy(true);
    try {
      const response = await fetch(cmsBlocksPath(branchId, page.id), {
        method: 'POST',
        credentials: 'same-origin',
        headers: mutationHeaders(csrfToken),
        body: JSON.stringify({
          type: blockDraft.type,
          payload,
          sort_order: blockDraft.sortOrder,
        }),
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible añadir el bloque.',
        ));
      }
      await onChanged('Bloque añadido al borrador.');
    } catch (error) {
      await onChanged(
        error instanceof Error ? error.message : 'No fue posible añadir el bloque.',
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <article className="catalog-card">
      <div className="catalog-card-heading">
        <div>
          <span className="eyebrow">
            {page.status === 'draft' ? 'BORRADOR' : 'PUBLICADA'}
          </span>
          <h3>{page.title}</h3>
          <p className="muted">/{page.slug} · tema {page.theme.name}</p>
        </div>
        {canUpdate && page.status === 'draft' && (
          <button
            className="button button-secondary"
            type="button"
            disabled={busy}
            onClick={() => void publishPage()}
          >
            Publicar
          </button>
        )}
      </div>

      {canUpdate && page.status === 'draft' && (
        <form className="catalog-editor" onSubmit={savePage}>
          <div className="catalog-form-grid">
            <label className="field">
              <span>Título</span>
              <input value={title} onChange={(e) => setTitle(e.target.value)} />
            </label>
            <label className="field">
              <span>Slug</span>
              <input value={slug} onChange={(e) => setSlug(e.target.value)} />
            </label>
            <label className="field catalog-form-wide">
              <span>Tema</span>
              <select
                value={themeId}
                onChange={(e) => setThemeId(e.target.value)}
              >
                {themes.map((theme) => (
                  <option key={theme.id} value={theme.id}>{theme.name}</option>
                ))}
              </select>
            </label>
          </div>
          <div className="catalog-actions">
            <button className="button" disabled={busy}>Guardar borrador</button>
          </div>
        </form>
      )}

      <div className="catalog-variants-heading">
        <div>
          <strong>Bloques</strong>
          <span className="muted">{page.blocks.length} configurados</span>
        </div>
      </div>

      <div className="catalog-variant-list">
        {page.blocks.map((block) => (
          <CmsBlockEditor
            key={block.id}
            branchId={branchId}
            pageId={page.id}
            block={block}
            canUpdate={canUpdate && page.status === 'draft'}
            csrfToken={csrfToken}
            onChanged={onChanged}
          />
        ))}
      </div>

      {canUpdate && page.status === 'draft' && (
        <form className="catalog-variant-editor" onSubmit={addBlock}>
          <strong>Añadir bloque</strong>
          <div className="catalog-form-grid">
            <label className="field">
              <span>Tipo</span>
              <select
                value={blockDraft.type}
                onChange={(e) => setBlockDraft({
                  ...blockDraft,
                  type: e.target.value,
                })}
              >
                {BLOCK_TYPES.map((type) => (
                  <option key={type} value={type}>{type}</option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Orden</span>
              <input
                type="number"
                min="0"
                value={blockDraft.sortOrder}
                onChange={(e) => setBlockDraft({
                  ...blockDraft,
                  sortOrder: Number(e.target.value),
                })}
              />
            </label>
            <label className="field catalog-form-wide">
              <span>Payload JSON</span>
              <textarea
                value={blockDraft.payload}
                onChange={(e) => setBlockDraft({
                  ...blockDraft,
                  payload: e.target.value,
                })}
              />
            </label>
          </div>
          <div className="catalog-actions">
            <button className="button" disabled={busy}>Añadir bloque</button>
          </div>
        </form>
      )}
    </article>
  );
}

type BlockEditorProps = Readonly<{
  branchId: string;
  pageId: string;
  block: CmsBlock;
  canUpdate: boolean;
  csrfToken: string;
  onChanged: (message: string) => Promise<void>;
}>;

function CmsBlockEditor({
  branchId,
  pageId,
  block,
  canUpdate,
  csrfToken,
  onChanged,
}: BlockEditorProps) {
  const [payload, setPayload] = useState(
    JSON.stringify(block.payload, null, 2),
  );
  const [type, setType] = useState(block.type);
  const [sortOrder, setSortOrder] = useState(block.sort_order);
  const [busy, setBusy] = useState(false);

  if (!canUpdate) {
    return (
      <div className="catalog-variant-row">
        <div>
          <strong>{block.type}</strong>
          <code>{JSON.stringify(block.payload)}</code>
        </div>
        <span className="muted">Orden {block.sort_order}</span>
      </div>
    );
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) {
      return;
    }

    let parsed: Record<string, unknown>;
    try {
      const value: unknown = JSON.parse(payload);
      if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        throw new Error();
      }
      parsed = value as Record<string, unknown>;
    } catch {
      await onChanged('El payload del bloque debe ser un objeto JSON válido.');
      return;
    }

    setBusy(true);
    try {
      const response = await fetch(
        cmsBlockPath(branchId, pageId, block.id),
        {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: mutationHeaders(csrfToken),
          body: JSON.stringify({
            type,
            payload: parsed,
            sort_order: sortOrder,
          }),
        },
      );
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible guardar el bloque.',
        ));
      }
      await onChanged('Bloque actualizado.');
    } catch (error) {
      await onChanged(
        error instanceof Error ? error.message : 'No fue posible guardar el bloque.',
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="catalog-variant-editor" onSubmit={save}>
      <div className="catalog-form-grid">
        <label className="field">
          <span>Tipo</span>
          <select value={type} onChange={(e) => setType(e.target.value)}>
            {BLOCK_TYPES.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </select>
        </label>
        <label className="field">
          <span>Orden</span>
          <input
            type="number"
            min="0"
            value={sortOrder}
            onChange={(e) => setSortOrder(Number(e.target.value))}
          />
        </label>
        <label className="field catalog-form-wide">
          <span>Payload JSON</span>
          <textarea value={payload} onChange={(e) => setPayload(e.target.value)} />
        </label>
      </div>
      <div className="catalog-actions">
        <button className="button button-secondary" disabled={busy}>
          Guardar bloque
        </button>
      </div>
    </form>
  );
}
