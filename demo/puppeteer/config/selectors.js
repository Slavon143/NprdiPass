export const formFields = {
  email: '#email',
  password: '#password',
  name: '#name',
  slug: '#slug',
  brand: '#brand',
  description: '#description',
  shortDescription: '#short_description',
  gtin: '#gtin',
  material: '#material',
  size: '#size',
  primaryColor: '#primary_color',
  waterResistance: '#water_resistance',
  recyclablePackaging: '#recyclable_packaging',
  countryOfManufacture: '#country_of_manufacture',
  warranty: '#warranty',
};

export const buttons = {
  createProduct: 'a[href*="products/create"]',
  createCategory: 'a[href*="categories/create"]',
  createAttribute: 'a[href*="attributes/create"]',
  inviteMember: 'a[href*="members/invite"]',
  save: 'button[type="submit"]',
  confirm: 'button::-p-text("Confirm")',
  delete: 'button::-p-text("Delete")',
  cancel: 'button::-p-text("Cancel")',
  addVariant: 'button::-p-text("Add variant")',
  addImage: 'button::-p-text("Add image")',
  addDocument: 'button::-p-text("Add document")',
};

export const pageTitles = {
  dashboard: 'h1::-p-text("Dashboard")',
  products: 'h1::-p-text("Products")',
  categories: 'h1::-p-text("Categories")',
  attributes: 'h1::-p-text("Attributes")',
  members: 'h1::-p-text("Members")',
  companySettings: 'h1::-p-text("Company")',
  audit: 'h1::-p-text("Activity log")',
  apiTokens: 'h1::-p-text("API tokens")',
};

export const feedback = {
  toast: '[role="alert"]',
  alert: '.alert',
  toastAlpine: '[x-data*="toast"]',
  successMessage: '[role="alert"]::-p-text("success"), .alert-success',
  errorMessage: '[role="alert"]::-p-text("error"), .alert-danger',
  validationError: '.invalid-feedback, .text-danger',
};

export const common = {
  loginForm: 'form[action*="login"]',
  loadingSpinner: '[role="status"]',
  modal: '[role="dialog"]',
  modalConfirm: '[role="dialog"] button::-p-text("Confirm")',
  modalCancel: '[role="dialog"] button::-p-text("Cancel")',
  dataTable: 'table',
  dataTableRow: 'table tbody tr',
  pagination: '[aria-label="Pagination"]',
  searchInput: 'input[type="search"], input[placeholder*="search" i], input[placeholder*="Search"]',
  emptyState: '::-p-text("No data"), ::-p-text("No results"), ::-p-text("Nothing found")',
  breadcrumb: '[aria-label="Breadcrumb"], .breadcrumb, nav[aria-label="breadcrumb"]',
  userMenu: '[data-testid="user-menu"], [aria-label="User menu"]',
  logoutButton: 'button::-p-text("Logout"), a::-p-text("Logout"), [data-testid="logout"]',
};

export default { formFields, buttons, pageTitles, feedback, common };
