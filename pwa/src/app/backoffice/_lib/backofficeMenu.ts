import {
  LucideIcon,
  LayoutDashboard,
  Users,
  Activity,
  ShoppingCart,
  FileText,
  Truck,
  Tag,
  Coins,
  SlidersVertical,
  Layers,
  Grid3x3,
  ClipboardList,
  HardHat,
  SquareCheckBig,
  Landmark,
  RefreshCw,
  Receipt,
  DollarSign,
  ChartPie,
  BarChart3,
  Bot,
  Rocket,
  Package,
  Factory,
  Warehouse,
  Handshake,
  PackageSearch,
  Building2,
  Network,
  ShieldCheck,
  CircleUser,
  BookOpen,
  Code,
  Database,
  Settings as SettingsIcon,
  History,
  Bell,
  LogOut,
  User,
  Wallet,
  Wrench,
  MonitorSmartphone,
  Info,
} from "lucide-react";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { Permission } from "@/context/shared/access/domain/Permission";
import { bankRoutes } from "@/app/backoffice/banks/_lib/bankRoutes";
import { bankAccountRoutes } from "@/app/backoffice/bank-accounts/_lib/bankAccountRoutes";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";

/**
 * Single source of truth for the back-office navigation. Both the sidebar
 * ({@link BackOfficeLayoutClient}, desktop + mobile) and the top-bar title
 * ({@link sectionTitleFor}) are built from the same data so the menu and the
 * section titles can never drift. Kept as plain data so the layout component
 * stays focused on rendering.
 */
/**
 * What a menu entry *means*, when that is not simply "go to `path`". An entry without one is
 * a plain link; the layout's navigation handler branches on this and never on the destination,
 * so where sign-out lands stays a routing detail rather than the trigger.
 */
export type NavAction = "sign-out";

/**
 * The permission an entry needs before it is painted. Declared on both levels because either can
 * point at a gated surface. It is an affordance filter and nothing more: the page behind the entry
 * keeps its own gate, since a URL can always be typed — hiding a door is not locking it.
 *
 * Exported so the one filter that reads it binds to the NAMED type: spelled structurally at the filter, a
 * second field added here would be advertised by every entry and honoured by nothing.
 */
export interface NavPermission {
  permission?: Permission;
}

export interface NavSubItem extends NavPermission {
  name: string;
  path: string;
  icon?: LucideIcon;
  testId?: string;
  action?: NavAction;
}

export interface NavItem extends NavPermission {
  name: string;
  icon: LucideIcon;
  path: string;
  subItems?: NavSubItem[];
  testId?: string;
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

const BASE = Routes.BACKOFFICE;

/**
 * The full sidebar, top to bottom. Labels are in English to match the rest of
 * the back-office. ERP section routes are placeholder pages under
 * {@link Routes.BACKOFFICE}; Dashboard and Banks are the live features. Group
 * labels and order are presentational only — no test depends on them.
 */
export const backofficeMenuGroups: NavGroup[] = [
  {
    label: "Home",
    items: [{ name: "Dashboard", icon: LayoutDashboard, path: BASE }],
  },
  {
    label: "Sales & CRM",
    items: [
      {
        name: "Customers",
        icon: Users,
        path: `${BASE}/clients`,
        subItems: [
          { name: "Clients", path: `${BASE}/clients`, icon: Users },
          { name: "Follow-ups", path: `${BASE}/follow-ups`, icon: Activity },
        ],
      },
      {
        name: "Sales",
        icon: ShoppingCart,
        path: `${BASE}/quotes`,
        subItems: [
          { name: "Quotes", path: `${BASE}/quotes`, icon: FileText },
          { name: "Sales Orders", path: `${BASE}/orders`, icon: ShoppingCart },
          { name: "Delivery Notes", path: `${BASE}/delivery-notes`, icon: Truck },
          { name: "Price Lists", path: `${BASE}/pricing`, icon: Tag },
          { name: "Commissions", path: `${BASE}/commissions`, icon: Coins },
        ],
      },
    ],
  },
  {
    label: "Projects",
    items: [
      { name: "Projects", icon: HardHat, path: `${BASE}/projects` },
      { name: "Tasks", icon: SquareCheckBig, path: `${BASE}/tasks` },
      {
        name: "Technical Office",
        icon: SlidersVertical,
        path: `${BASE}/pavement-systems`,
        subItems: [
          { name: "Systems Catalog", path: `${BASE}/pavement-systems`, icon: Layers },
          { name: "Pavement Configurator", path: `${BASE}/configurator`, icon: Grid3x3 },
          { name: "Technical Studies", path: `${BASE}/pavement-studies`, icon: ClipboardList },
        ],
      },
    ],
  },
  {
    label: "Purchasing & Inventory",
    items: [
      { name: "Suppliers", icon: Handshake, path: `${BASE}/suppliers` },
      { name: "Purchase Orders", icon: PackageSearch, path: `${BASE}/purchase-orders` },
      { name: "Products Catalog", icon: Package, path: `${BASE}/products` },
      { name: "Brands & Manufacturers", icon: Factory, path: `${BASE}/catalog/brands` },
      { name: "Stock Control", icon: Warehouse, path: `${BASE}/catalog/stock` },
    ],
  },
  {
    label: "Finance",
    items: [
      { name: "Invoicing", icon: Receipt, path: `${BASE}/invoices` },
      {
        name: "Treasury",
        icon: Landmark,
        path: bankRoutes.list,
        subItems: [
          { name: "Banks", path: bankRoutes.list, icon: Building2 },
          { name: "Bank Accounts", path: bankAccountRoutes.list, icon: Wallet },
          { name: "Transactions", path: `${BASE}/finance/transactions`, icon: RefreshCw },
          { name: "Cash Flow", path: `${BASE}/finance/cash-flow`, icon: DollarSign },
        ],
      },
      { name: "Project Costs", icon: ChartPie, path: `${BASE}/finance/accounting` },
    ],
  },
  {
    label: "Organization & People",
    items: [
      {
        name: "Organization",
        icon: Building2,
        path: `${BASE}/companies`,
        subItems: [
          { name: "Companies", path: `${BASE}/companies`, icon: Building2 },
          { name: "Employees", path: `${BASE}/companies/employees`, icon: Users },
          { name: "Departments", path: `${BASE}/catalog/departments`, icon: Network },
        ],
      },
      {
        name: "External Portals",
        icon: ShieldCheck,
        path: `${BASE}/portal`,
        subItems: [
          { name: "Client Portal", path: `${BASE}/portal`, icon: CircleUser },
          { name: "Provider Portal", path: `${BASE}/provider`, icon: Truck },
        ],
      },
    ],
  },
  {
    label: "Insights",
    items: [
      {
        name: "Reports & Analytics",
        icon: BarChart3,
        path: `${BASE}/finance/control`,
        subItems: [
          { name: "Budget vs Actuals", path: `${BASE}/finance/control`, icon: Activity },
          { name: "AI Reports", path: `${BASE}/ai-reports`, icon: Bot },
        ],
      },
    ],
  },
  {
    label: "System",
    items: [
      {
        name: "Configuration",
        icon: SettingsIcon,
        path: `${BASE}/users`,
        subItems: [
          {
            name: "Users",
            path: `${BASE}/users`,
            icon: Users,
            permission: Permission.USERS_READ,
          },
          { name: "Features & Modules", path: `${BASE}/settings/features`, icon: SlidersVertical },
          { name: "Audit Logs", path: `${BASE}/audit`, icon: History },
          { name: "System Health", path: `${BASE}/health`, icon: Activity },
        ],
      },
    ],
  },
  {
    label: "Help & Guides",
    items: [
      { name: "About ERPify", icon: Info, path: `${BASE}/about` },
      { name: "How ERPify Works", icon: BookOpen, path: `${BASE}/docs/flow` },
      { name: "Product Roadmap", icon: Rocket, path: `${BASE}/roadmap` },
      { name: "Technical Explorer", icon: Code, path: `${BASE}/docs` },
      { name: "Data Dictionary", icon: Database, path: `${BASE}/docs/dictionary` },
    ],
  },
  // Dev/QA-only group. Absent from production builds via the same
  // `isDevToolsAvailable()` gate that guards the route at `app/dev-tools`.
  ...(isDevToolsAvailable()
    ? [
        {
          label: "Development",
          items: [
            {
              name: "Dev Tools",
              icon: Wrench,
              path: Routes.DEV_TOOLS,
              testId: "bo-layout__sidebar-dev-tools",
            },
          ],
        },
      ]
    : []),
];

/**
 * Account entry pinned to the sidebar footer, rendered apart from the scrolling
 * groups. Logout carries `action: "sign-out"`, which is the only thing that triggers it — so an
 * ordinary entry may point at `Routes.HOME` without revoking anything. Its own `path` is inert on
 * that branch: the handler hard-codes the destination, and a test pins the two to agree.
 *
 * "My profile" repeats the parent's own path on purpose: an expanded desktop sidebar
 * only toggles the sub-item list when the parent is clicked, so the account landing
 * page is unreachable from that state without an explicit leaf of its own.
 */
export const accountMenuItem: NavItem = {
  name: "User Profile",
  icon: User,
  path: Routes.PROFILE,
  subItems: [
    {
      name: "My profile",
      path: Routes.PROFILE,
      icon: User,
      testId: "bo-layout__sidebar-profile",
    },
    {
      name: "Notifications",
      path: `${Routes.PROFILE}/notifications`,
      icon: Bell,
      testId: "bo-layout__sidebar-notifications",
    },
    {
      name: "Active sessions",
      path: `${Routes.PROFILE}/sessions`,
      icon: MonitorSmartphone,
      testId: "bo-layout__sidebar-sessions",
    },
    {
      name: "Settings",
      path: `${Routes.PROFILE}/settings`,
      icon: SettingsIcon,
      testId: "bo-layout__sidebar-settings",
    },
    {
      name: "Logout",
      path: Routes.HOME,
      icon: LogOut,
      testId: "bo-layout__sidebar-logout",
      action: "sign-out",
    },
  ],
};

/**
 * Landing titles for the bare parent segments that group nested routes but are
 * not themselves menu entries (e.g. `/finance`, reachable by URL truncation).
 * Their leaf children still resolve to their own titles via longest-match-first.
 */
const SECTION_LANDING_RULES: ReadonlyArray<readonly [string, string]> = [
  [`${BASE}/finance`, "Finance"],
  [`${BASE}/catalog`, "Catalog"],
  [`${BASE}/settings`, "Settings"],
];

/**
 * `[path, title]` rules for {@link sectionTitleFor}, derived from the menu so
 * the titles never drift from the navigation. For each item the parent rule is
 * emitted first and then its sub-items, so a sub-item that shares its parent's
 * path (the ERP convention) wins with the more specific leaf name. The
 * Dashboard root (`/backoffice`) and the Logout target (`/`) are skipped: as
 * prefixes of every path they would shadow the whole tree. Order is irrelevant
 * here — the consumer re-sorts longest-match-first.
 */
export const sectionTitleRules: ReadonlyArray<readonly [string, string]> = (() => {
  const rules = new Map<string, string>();
  const add = (path: string, name: string): void => {
    if (path === BASE || path === "/") return;
    rules.set(path, name);
  };
  const consider = (item: NavItem): void => {
    add(item.path, item.name);
    item.subItems?.forEach((sub) => add(sub.path, sub.name));
  };
  backofficeMenuGroups.flatMap((group) => group.items).forEach(consider);
  consider(accountMenuItem);
  SECTION_LANDING_RULES.forEach(([path, name]) => add(path, name));
  return [...rules.entries()];
})();
