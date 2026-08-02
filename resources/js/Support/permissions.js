export const FULL_PERMISSIONS = {
  canView: true,
  canCreate: true,
  canUpdate: true,
  canDelete: true,
  canExport: true,
  canManageStops: true,
  canManageFares: true,
};

export const READ_ONLY_PERMISSIONS = {
  canView: true,
  canCreate: false,
  canUpdate: false,
  canDelete: false,
  canExport: true,
  canManageStops: false,
  canManageFares: false,
};

export const defaultPermissions = (overrides = {}) => ({ ...READ_ONLY_PERMISSIONS, ...overrides });
