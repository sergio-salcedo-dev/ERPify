import { Routes } from "@/context/shared/routing/domain/Routes";

/**
 * A back-office route that is reserved in the navigation but whose module is not built yet.
 *
 * The registry is what makes the navigation honest: the sidebar marks every entry whose path is
 * listed here as "Soon", and the route itself renders {@link PlannedModulePage} instead of an empty
 * heading — so an operator learns that a module does not exist yet from the menu, before clicking,
 * rather than from a blank page after it. Shipping a module means deleting its line here; nothing
 * else has to be told.
 */
export interface PlannedModule {
  /** The route the module will live at — the same string its menu entry points at. */
  path: string;
  /** The page heading. Equal to the route's `metadata.title`, which a test pins. */
  heading: string;
  /**
   * The roadmap module that owns this surface. Its user needs are what the planned page lists as
   * "what it will cover", read from the roadmap rather than restated, so the two cannot drift.
   */
  roadmapCode?: string;
}

const BASE = Routes.BACKOFFICE;

export const PlannedModules = {
  CLIENTS: { path: `${BASE}/clients`, heading: "Clients", roadmapCode: "1.2" },
  FOLLOW_UPS: { path: `${BASE}/follow-ups`, heading: "Follow-ups", roadmapCode: "1.2" },
  QUOTES: { path: `${BASE}/quotes`, heading: "Quotes", roadmapCode: "1.2c" },
  ORDERS: { path: `${BASE}/orders`, heading: "Sales Orders", roadmapCode: "1.2" },
  DELIVERY_NOTES: { path: `${BASE}/delivery-notes`, heading: "Delivery Notes", roadmapCode: "1.5" },
  PRICING: { path: `${BASE}/pricing`, heading: "Price Lists", roadmapCode: "1.6" },
  COMMISSIONS: { path: `${BASE}/commissions`, heading: "Commissions", roadmapCode: "2.7" },
  PROJECTS: { path: `${BASE}/projects`, heading: "Projects", roadmapCode: "1.3" },
  TASKS: { path: `${BASE}/tasks`, heading: "Tasks", roadmapCode: "1.3" },
  PAVEMENT_SYSTEMS: {
    path: `${BASE}/pavement-systems`,
    heading: "Systems Catalog",
    roadmapCode: "1.6",
  },
  CONFIGURATOR: {
    path: `${BASE}/configurator`,
    heading: "Pavement Configurator",
    roadmapCode: "1.6",
  },
  PAVEMENT_STUDIES: {
    path: `${BASE}/pavement-studies`,
    heading: "Technical Studies",
    roadmapCode: "1.4",
  },
  SUPPLIERS: { path: `${BASE}/suppliers`, heading: "Suppliers", roadmapCode: "1.5" },
  PURCHASE_ORDERS: {
    path: `${BASE}/purchase-orders`,
    heading: "Purchase Orders",
    roadmapCode: "1.5",
  },
  PRODUCTS: { path: `${BASE}/products`, heading: "Products Catalog", roadmapCode: "1.5" },
  CATALOG: { path: `${BASE}/catalog`, heading: "Catalog", roadmapCode: "1.5" },
  BRANDS: {
    path: `${BASE}/catalog/brands`,
    heading: "Brands & Manufacturers",
    roadmapCode: "1.5",
  },
  STOCK: { path: `${BASE}/catalog/stock`, heading: "Stock Control", roadmapCode: "1.5" },
  DEPARTMENTS: { path: `${BASE}/catalog/departments`, heading: "Departments", roadmapCode: "1.1" },
  INVOICES: { path: `${BASE}/invoices`, heading: "Invoicing", roadmapCode: "2.3" },
  FINANCE: { path: `${BASE}/finance`, heading: "Finance", roadmapCode: "2.3" },
  TRANSACTIONS: {
    path: `${BASE}/finance/transactions`,
    heading: "Transactions",
    roadmapCode: "2.3",
  },
  CASH_FLOW: { path: `${BASE}/finance/cash-flow`, heading: "Cash Flow", roadmapCode: "2.3" },
  PROJECT_COSTS: {
    path: `${BASE}/finance/accounting`,
    heading: "Project Costs",
    roadmapCode: "2.5",
  },
  BUDGET_VS_ACTUALS: {
    path: `${BASE}/finance/control`,
    heading: "Budget vs Actuals",
    roadmapCode: "1.4",
  },
  AI_REPORTS: { path: `${BASE}/ai-reports`, heading: "AI Reports", roadmapCode: "4.2" },
  COMPANIES: { path: `${BASE}/companies`, heading: "Companies", roadmapCode: "1.1" },
  EMPLOYEES: { path: `${BASE}/companies/employees`, heading: "Employees", roadmapCode: "2.1" },
  CLIENT_PORTAL: { path: `${BASE}/portal`, heading: "Client Portal", roadmapCode: "2.6" },
  PROVIDER_PORTAL: { path: `${BASE}/provider`, heading: "Provider Portal", roadmapCode: "2.6" },
  SETTINGS: { path: `${BASE}/settings`, heading: "Settings" },
  FEATURES: {
    path: `${BASE}/settings/features`,
    heading: "Features & Modules",
    roadmapCode: "3.3",
  },
  TECHNICAL_EXPLORER: { path: `${BASE}/docs`, heading: "Technical Explorer" },
  DATA_DICTIONARY: { path: `${BASE}/docs/dictionary`, heading: "Data Dictionary" },
  NOTIFICATIONS: {
    path: `${Routes.PROFILE}/notifications`,
    heading: "Notifications",
    roadmapCode: "3.2",
  },
  PROFILE_SETTINGS: {
    path: `${Routes.PROFILE}/settings`,
    heading: "Profile settings",
    roadmapCode: "1.1",
  },
} as const satisfies Record<string, PlannedModule>;

const PLANNED_PATHS: ReadonlySet<string> = new Set(
  Object.values(PlannedModules).map((module) => module.path),
);

/**
 * Whether `path` is a reserved route whose module is not built yet. Exact match: a planned parent
 * never makes a live child planned, and the reverse.
 */
export function isPlannedPath(path: string): boolean {
  return PLANNED_PATHS.has(path);
}
