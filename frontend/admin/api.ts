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


export function platformOwnerContextPath(tenantId?: string): string {
  if (!tenantId) {
    return '/adminpl0n3r/api/context';
  }

  const params = new URLSearchParams({
    tenant: safeUlid(tenantId),
  });

  return '/adminpl0n3r/api/context?' + params.toString();
}
