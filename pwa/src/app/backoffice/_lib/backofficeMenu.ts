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
  Briefcase,
  SquareCheckBig,
  Landmark,
  RefreshCw,
  Receipt,
  DollarSign,
  ChartPie,
  Bot,
  Rocket,
  Package,
  Factory,
  Warehouse,
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
  Wrench,
} from "lucide-react";
import { Routes } from "@/context/shared/domain/types/routes";
import { bankRoutes } from "@/app/backoffice/banks/_lib/bankRoutes";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";

/**
 * Single source of truth for the back-office navigation. Both the sidebar
 * ({@link BackOfficeLayoutClient}, desktop + mobile) and the top-bar title
 * ({@link sectionTitleFor}) are built from the same data so the menu and the
 * section titles can never drift. Kept as plain data so the layout component
 * stays focused on rendering.
 */
export interface NavSubItem {
  name: string;
  path: string;
  icon?: LucideIcon;
  testId?: string;
}

export interface NavItem {
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
    label: "General",
    items: [
      { name: "Dashboard", icon: LayoutDashboard, path: BASE },
      { name: "Banks", icon: Building2, path: bankRoutes.list },
    ],
  },
  {
    label: "Commercial",
    items: [
      {
        name: "CRM",
        icon: Users,
        path: `${BASE}/clients`,
        subItems: [
          { name: "Clients", path: `${BASE}/clients`, icon: Users },
          { name: "Sales Follow-ups", path: `${BASE}/follow-ups`, icon: Activity },
        ],
      },
      {
        name: "Sales",
        icon: ShoppingCart,
        path: `${BASE}/quotes`,
        subItems: [
          { name: "Quotes", path: `${BASE}/quotes`, icon: FileText },
          { name: "Work Orders", path: `${BASE}/orders`, icon: ShoppingCart },
          { name: "Delivery Notes", path: `${BASE}/delivery-notes`, icon: Truck },
          { name: "Dynamic Pricing", path: `${BASE}/pricing`, icon: Tag },
          { name: "Commissions", path: `${BASE}/commissions`, icon: Coins },
        ],
      },
    ],
  },
  {
    label: "Operations",
    items: [
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
      {
        name: "Production",
        icon: HardHat,
        path: `${BASE}/projects`,
        subItems: [{ name: "Project Management", path: `${BASE}/projects`, icon: Briefcase }],
      },
      {
        name: "Collaboration",
        icon: SquareCheckBig,
        path: `${BASE}/tasks`,
        subItems: [{ name: "Team Tasks", path: `${BASE}/tasks`, icon: SquareCheckBig }],
      },
      {
        name: "Logistics",
        icon: Package,
        path: `${BASE}/products`,
        subItems: [
          { name: "Products Catalog", path: `${BASE}/products`, icon: Package },
          { name: "Brands & Manufacturers", path: `${BASE}/catalog/brands`, icon: Factory },
          { name: "Stock Control", path: `${BASE}/catalog/stock`, icon: Warehouse },
        ],
      },
    ],
  },
  {
    label: "Finance",
    items: [
      {
        name: "Finance",
        icon: Landmark,
        path: `${BASE}/finance/control`,
        subItems: [
          { name: "Management Control", path: `${BASE}/finance/control`, icon: Activity },
          { name: "Global Transactions", path: `${BASE}/finance/transactions`, icon: RefreshCw },
          { name: "Invoicing", path: `${BASE}/invoices`, icon: Receipt },
          { name: "Treasury & Banks", path: `${BASE}/finance/treasury`, icon: Landmark },
          { name: "Cash Flow", path: `${BASE}/finance/cash-flow`, icon: DollarSign },
          { name: "Cost Allocation", path: `${BASE}/finance/accounting`, icon: ChartPie },
        ],
      },
      {
        name: "AI Intelligence",
        icon: Bot,
        path: `${BASE}/ai-reports`,
        subItems: [
          { name: "AI Reports", path: `${BASE}/ai-reports`, icon: Bot },
          { name: "Roadmap & AI Labs", path: `${BASE}/roadmap`, icon: Rocket },
        ],
      },
    ],
  },
  {
    label: "Organization",
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
    label: "Resources",
    items: [
      {
        name: "Documentation",
        icon: BookOpen,
        path: `${BASE}/docs`,
        subItems: [
          { name: "Technical Explorer", path: `${BASE}/docs`, icon: Code },
          { name: "Data Dictionary", path: `${BASE}/docs/dictionary`, icon: Database },
          { name: "Domain Flows", path: `${BASE}/docs/flow`, icon: Activity },
        ],
      },
      {
        name: "Configuration",
        icon: SettingsIcon,
        path: `${BASE}/users`,
        subItems: [
          { name: "Users", path: `${BASE}/users`, icon: Users },
          { name: "Features & Modules", path: `${BASE}/settings/features`, icon: SlidersVertical },
          { name: "Audit Logs", path: `${BASE}/audit`, icon: History },
        ],
      },
    ],
  },
  {
    label: "System",
    items: [
      {
        name: "Administration",
        icon: SettingsIcon,
        path: `${BASE}/administration`,
        subItems: [{ name: "Service Health", path: `${BASE}/health`, icon: Activity }],
      },
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
 * groups. Logout points at `/` (home) rather than a back-office section.
 */
export const accountMenuItem: NavItem = {
  name: "User Profile",
  icon: User,
  path: `${BASE}/profile`,
  subItems: [
    { name: "Notifications", path: `${BASE}/profile/notifications`, icon: Bell },
    { name: "Settings", path: `${BASE}/profile/settings`, icon: SettingsIcon },
    { name: "Logout", path: "/", icon: LogOut },
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
