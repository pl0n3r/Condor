const ULID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{26}$/;

function safeUlid(value: string): string {
  if (!ULID_PATTERN.test(value)) {
    throw new Error('Identificador interno inválido.');
  }

  return value;
}

export function contextPath(branchId?: string): string {
  if (!branchId) {
    return '/api/v1/context';
  }

  const params = new URLSearchParams({
    branch: safeUlid(branchId),
  });

  return '/api/v1/context?' + params.toString();
}

export function rolesPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/roles';
}

export function rolePath(branchId: string, roleId: string): string {
  return rolesPath(branchId) + '/' + safeUlid(roleId);
}

export function membershipsPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/memberships';
}

export function membershipRolePath(
  branchId: string,
  membershipId: string,
  roleId: string,
): string {
  return (
    membershipsPath(branchId) +
    '/' +
    safeUlid(membershipId) +
    '/roles/' +
    safeUlid(roleId)
  );
}


export function platformOwnerContextPath(
  tenantId?: string,
  page = 1,
): string {
  const params = new URLSearchParams();

  if (tenantId) {
    params.set('tenant', safeUlid(tenantId));
  }

  if (page > 1) {
    params.set('page', String(Math.max(1, Math.trunc(page))));
  }

  const query = params.toString();

  return '/adminpl0n3r/api/context' + (query ? '?' + query : '');
}


export function platformStaffPath(): string {
  return '/adminpl0n3r/api/staff';
}

export function platformStaffInvitationPath(): string {
  return platformStaffPath() + '/invitations';
}

export function platformStaffGrantsPath(staffId: string): string {
  return platformStaffPath() + '/' + safeUlid(staffId) + '/grants';
}

export function platformStaffInvitationResendPath(
  staffId: string,
): string {
  return (
    platformStaffPath() +
    '/' +
    safeUlid(staffId) +
    '/invitations/resend'
  );
}

export function platformStaffInvitationRevokePath(
  staffId: string,
): string {
  return (
    platformStaffPath() +
    '/' +
    safeUlid(staffId) +
    '/invitations'
  );
}

export function platformTenantsPath(): string {
  return '/adminpl0n3r/api/tenants';
}

export function catalogProductsPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/catalog/products';
}

export function catalogProductPath(
  branchId: string,
  productId: string,
): string {
  return catalogProductsPath(branchId) + '/' + safeUlid(productId);
}

export function catalogVariantsPath(
  branchId: string,
  productId: string,
): string {
  return catalogProductPath(branchId, productId) + '/variants';
}

export function catalogVariantPath(
  branchId: string,
  productId: string,
  variantId: string,
): string {
  return (
    catalogVariantsPath(branchId, productId) +
    '/' +
    safeUlid(variantId)
  );
}


export function inventoryPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/inventory';
}

export function inventorySourcePath(branchId: string): string {
  return inventoryPath(branchId) + '/sources';
}

export function inventoryAdjustmentPath(branchId: string): string {
  return inventoryPath(branchId) + '/adjustments';
}

export function inventoryTransferPath(branchId: string): string {
  return inventoryPath(branchId) + '/transfers';
}


export function productionMaterialsPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/production/materials';
}

export function productionMaterialPath(
  branchId: string,
  materialId: string,
): string {
  return productionMaterialsPath(branchId) + '/' + safeUlid(materialId);
}

export function productionMaterialAdjustmentPath(
  branchId: string,
  materialId: string,
): string {
  return productionMaterialPath(branchId, materialId) + '/adjustments';
}

export function productionBomsPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/production/boms';
}

export function productionOrdersPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/production/orders';
}

export function productionOrderCompletePath(
  branchId: string,
  orderId: string,
): string {
  return productionOrdersPath(branchId)
    + '/'
    + safeUlid(orderId)
    + '/complete';
}


export function customersPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/customers';
}

export function customerPath(
  branchId: string,
  customerId: string,
): string {
  return customersPath(branchId) + '/' + safeUlid(customerId);
}

export function commercialCategoriesPath(branchId: string): string {
  return customersPath(branchId) + '/categories';
}

export function commercialCategoryPath(
  branchId: string,
  categoryId: string,
): string {
  return commercialCategoriesPath(branchId) + '/' + safeUlid(categoryId);
}

export function pricingPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/pricing';
}

export function priceListsPath(branchId: string): string {
  return pricingPath(branchId) + '/lists';
}

export function priceListPath(
  branchId: string,
  listId: string,
): string {
  return priceListsPath(branchId) + '/' + safeUlid(listId);
}

export function variantPricePath(
  branchId: string,
  listId: string,
  variantId: string,
): string {
  return (
    priceListPath(branchId, listId) +
    '/variants/' +
    safeUlid(variantId)
  );
}

export function categoryPriceListPath(
  branchId: string,
  categoryId: string,
): string {
  return (
    pricingPath(branchId) +
    '/categories/' +
    safeUlid(categoryId)
  );
}

export function effectivePricePath(
  branchId: string,
  variantId: string,
  customerId?: string,
  priceListId?: string,
): string {
  const params = new URLSearchParams({
    variant: safeUlid(variantId),
  });
  if (customerId) {
    params.set('customer', safeUlid(customerId));
  }
  if (priceListId) {
    params.set('price_list', safeUlid(priceListId));
  }

  return pricingPath(branchId) + '/effective?' + params.toString();
}


export function ordersPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/orders';
}

export function orderCancelPath(
  branchId: string,
  orderId: string,
): string {
  return ordersPath(branchId) + '/' + safeUlid(orderId) + '/cancel';
}

export function orderConsumePath(
  branchId: string,
  orderId: string,
): string {
  return ordersPath(branchId) + '/' + safeUlid(orderId) + '/consume';
}

export function cmsPagesPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/cms/pages';
}

export function cmsPagePath(branchId: string, pageId: string): string {
  return cmsPagesPath(branchId) + '/' + safeUlid(pageId);
}

export function cmsPagePublishPath(
  branchId: string,
  pageId: string,
): string {
  return cmsPagePath(branchId, pageId) + '/publish';
}

export function cmsBlocksPath(branchId: string, pageId: string): string {
  return cmsPagePath(branchId, pageId) + '/blocks';
}

export function cmsBlockPath(
  branchId: string,
  pageId: string,
  blockId: string,
): string {
  return cmsBlocksPath(branchId, pageId) + '/' + safeUlid(blockId);
}


/** M07-S06: únicamente fixtures de laboratorio; nunca una operación contable. */
export type FinanceClosureMode = 'empty' | 'reconciled';
export type FinanceClosureResult = Readonly<{
  state: 'REQUIERE_REVISION';
  synthetic_only: true;
  can_close: false;
  can_approve: false;
  matched_candidate_count: number;
  unmatched_movement_count: number;
  periods: readonly Readonly<{
    period_ref: string;
    review_candidate: boolean;
    can_close: false;
    state: 'REQUIERE_REVISION';
  }>[];
}>;

export class FinanceClosureAccessDenied extends Error {}

/** El token branch_access ya está presente en el mount del administrador. */
export function financeClosurePreviewPath(branchId: string): string {
  return '/api/v1/branches/' + safeUlid(branchId) + '/finance/closures/preview';
}

function financeClosureFixture(mode: FinanceClosureMode): Record<string, unknown> {
  const tenant_ref = 'fixture:tenant/current';
  const legal_entity_ref = 'fixture:legal-entity/current';
  const period_ref = 'fixture:finance-period/october';
  const entry_ref = 'fixture:finance-entry/demo';
  const common = { tenant_ref, legal_entity_ref };
  return {
    ...common,
    fixture_ref: 'fixture:finance-reconciliation/demo',
    as_of: '2026-11-05',
    periods: mode === 'empty' ? [] : [{
      ...common, period_ref, starts_on: '2026-10-01', ends_on: '2026-10-31',
      state: 'BORRADOR',
    }],
    budgets: mode === 'empty' ? [] : [{
      ...common, budget_ref: 'fixture:finance-budget/demo', period_ref,
      kind: 'INGRESO', planned_decimal: '20.00', state: 'BORRADOR',
    }],
    ledger_entries: mode === 'empty' ? [] : [{
      ...common, entry_ref, period_ref, budget_ref: 'fixture:finance-budget/demo',
      amount_decimal: '12.30', kind: 'INGRESO', booked_on: '2026-10-20',
      state: 'BORRADOR',
    }],
    bank_movements: mode === 'empty' ? [] : [{
      ...common, movement_ref: 'fixture:finance-movement/demo', entry_ref,
      amount_decimal: '12.30', kind: 'INGRESO', booked_on: '2026-10-21',
      state: 'BORRADOR',
    }],
  };
}

function isSafeFinanceClosure(value: unknown): value is FinanceClosureResult {
  if (typeof value !== 'object' || value === null) return false;
  const row = value as Record<string, unknown>;
  return row.synthetic_only === true && row.state === 'REQUIERE_REVISION'
    && row.can_close === false && row.can_approve === false
    && typeof row.matched_candidate_count === 'number'
    && typeof row.unmatched_movement_count === 'number'
    && Array.isArray(row.periods)
    && row.periods.every((period: unknown) => {
      if (typeof period !== 'object' || period === null) return false;
      const item = period as Record<string, unknown>;
      return typeof item.period_ref === 'string'
        && item.period_ref.startsWith('fixture:finance-period/')
        && item.can_close === false
        && item.state === 'REQUIERE_REVISION'
        && typeof item.review_candidate === 'boolean';
    });
}

/** HTTP autenticado, sin persistencia cliente, sin permitir mutaciones de cierre. */
export async function previewSyntheticFinanceClosure(
  branchId: string,
  mode: FinanceClosureMode,
  signal?: AbortSignal,
): Promise<FinanceClosureResult> {
  const csrfToken = document.getElementById('condor-admin-root')?.dataset.accessToken ?? '';
  if (!csrfToken) throw new FinanceClosureAccessDenied('CSRF no disponible');
  const response = await fetch(financeClosurePreviewPath(branchId), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify(financeClosureFixture(mode)),
    signal,
  });
  if ([401, 403, 404].includes(response.status)) {
    throw new FinanceClosureAccessDenied('No hay permiso o acceso a esta sede.');
  }
  if (!response.ok) throw new Error('No fue posible consultar el preview de cierre.');
  const body: unknown = await response.json();
  if (typeof body !== 'object' || body === null) throw new Error('Respuesta inválida.');
  const envelope = body as Record<string, unknown>;
  if (envelope.synthetic_only !== true || !isSafeFinanceClosure(envelope.closure_preview)) {
    throw new Error('Respuesta no sintética o insegura.');
  }
  return envelope.closure_preview;
}

/** API owner-only existente: esta pantalla no crea endpoints de facturación. */
export function platformPlanCatalogPath(): string {
  return '/adminpl0n3r/planes';
}

function safePlanKey(key: string): string {
  if (!/^[a-z][a-z0-9_-]{0,63}$/.test(key)) {
    throw new Error('Clave de plan inválida.');
  }
  return encodeURIComponent(key);
}

export function platformPlanLimitsPath(key: string): string {
  return '/adminpl0n3r/api/plans/' + safePlanKey(key);
}

export function platformPlanPricesPath(key: string): string {
  return platformPlanLimitsPath(key) + '/prices';
}

export function platformPlanAuditPath(page = 1): string {
  return '/adminpl0n3r/api/auditoria?type=commercial_plan&page=' + Math.max(1, Math.trunc(page));
}
