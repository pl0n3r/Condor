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
