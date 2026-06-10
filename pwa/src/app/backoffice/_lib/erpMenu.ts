import {
  LucideIcon,
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
} from "lucide-react";
import { Routes } from "@/context/shared/domain/types/routes";

/**
 * Sidebar navigation model shared by {@link BackOfficeLayoutClient} (desktop
 * + mobile menus) and {@link sectionTitleFor} (top-bar title). Kept here as
 * plain data so the layout component stays focused on rendering.
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
 * ERP navigation sections. Labels are in English to match the rest of the
 * backoffice; every route is a placeholder page under {@link Routes.BACKOFFICE}.
 */
export const erpMenuGroups: NavGroup[] = [
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
];

/**
 * Flattened `[path, title]` rules for the top-bar section title, derived from
 * {@link erpMenuGroups}. Order is irrelevant here: the sole consumer
 * ({@link sectionTitleFor}'s `SECTION_RULES`) re-sorts the merged set
 * longest-match-first, so this list is left in declaration order.
 */
export const erpSectionRules: ReadonlyArray<readonly [string, string]> = erpMenuGroups
  .flatMap((group) => group.items)
  .flatMap((item) =>
    item.subItems && item.subItems.length > 0
      ? item.subItems.map((sub) => [sub.path, sub.name] as const)
      : [[item.path, item.name] as const],
  );
