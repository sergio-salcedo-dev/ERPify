import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import BankAccountsLayout, {
  metadata as bankAccountsLayoutMeta,
} from "@/app/backoffice/bank-accounts/layout";
import BankAccountDetailLayout, {
  metadata as bankAccountDetailLayoutMeta,
} from "@/app/backoffice/bank-accounts/[id]/layout";
import BankAccountEditLayout, {
  metadata as bankAccountEditLayoutMeta,
} from "@/app/backoffice/bank-accounts/[id]/edit/layout";
import BanksLayout, { metadata as banksLayoutMeta } from "@/app/backoffice/banks/layout";
import BankDetailLayout, {
  metadata as bankDetailLayoutMeta,
} from "@/app/backoffice/banks/[id]/layout";
import BankAccountsListLayout, {
  metadata as bankAccountsListLayoutMeta,
} from "@/app/backoffice/banks/[id]/accounts/layout";
import BankAccountEditNestedLayout, {
  metadata as bankAccountEditNestedLayoutMeta,
} from "@/app/backoffice/banks/[id]/accounts/[accountId]/edit/layout";
import BankEditLayout, {
  metadata as bankEditLayoutMeta,
} from "@/app/backoffice/banks/[id]/edit/layout";
import HealthLayout, { metadata as healthLayoutMeta } from "@/app/backoffice/health/layout";
import DashboardPage, { metadata as dashboardPageMeta } from "@/app/backoffice/page";
import QuotesPage, { metadata as quotesPageMeta } from "@/app/backoffice/quotes/page";
import CatalogPage, { metadata as catalogPageMeta } from "@/app/backoffice/catalog/page";
import CatalogDepartmentsPage, {
  metadata as catalogDepartmentsPageMeta,
} from "@/app/backoffice/catalog/departments/page";
import CatalogBrandsPage, {
  metadata as catalogBrandsPageMeta,
} from "@/app/backoffice/catalog/brands/page";
import CatalogStockPage, {
  metadata as catalogStockPageMeta,
} from "@/app/backoffice/catalog/stock/page";
import CompaniesPage, { metadata as companiesPageMeta } from "@/app/backoffice/companies/page";
import CompaniesEmployeesPage, {
  metadata as companiesEmployeesPageMeta,
} from "@/app/backoffice/companies/employees/page";
import { metadata as auditPageMeta } from "@/app/backoffice/audit/page";
import PavementStudiesPage, {
  metadata as pavementStudiesPageMeta,
} from "@/app/backoffice/pavement-studies/page";
import ConfiguratorPage, {
  metadata as configuratorPageMeta,
} from "@/app/backoffice/configurator/page";
import DocsPage, { metadata as docsPageMeta } from "@/app/backoffice/docs/page";
import DocsDictionaryPage, {
  metadata as docsDictionaryPageMeta,
} from "@/app/backoffice/docs/dictionary/page";
import PavementSystemsPage, {
  metadata as pavementSystemsPageMeta,
} from "@/app/backoffice/pavement-systems/page";
import AiReportsPage, { metadata as aiReportsPageMeta } from "@/app/backoffice/ai-reports/page";
import ProjectsPage, { metadata as projectsPageMeta } from "@/app/backoffice/projects/page";
import PricingPage, { metadata as pricingPageMeta } from "@/app/backoffice/pricing/page";
import FinancePage, { metadata as financePageMeta } from "@/app/backoffice/finance/page";
import FinanceCashFlowPage, {
  metadata as financeCashFlowPageMeta,
} from "@/app/backoffice/finance/cash-flow/page";
import FinanceControlPage, {
  metadata as financeControlPageMeta,
} from "@/app/backoffice/finance/control/page";
import FinanceTransactionsPage, {
  metadata as financeTransactionsPageMeta,
} from "@/app/backoffice/finance/transactions/page";
import FinanceAccountingPage, {
  metadata as financeAccountingPageMeta,
} from "@/app/backoffice/finance/accounting/page";
import TasksPage, { metadata as tasksPageMeta } from "@/app/backoffice/tasks/page";
import CommissionsPage, {
  metadata as commissionsPageMeta,
} from "@/app/backoffice/commissions/page";
import PurchaseOrdersPage, {
  metadata as purchaseOrdersPageMeta,
} from "@/app/backoffice/purchase-orders/page";
import ProviderPage, { metadata as providerPageMeta } from "@/app/backoffice/provider/page";
import InvoicesPage, { metadata as invoicesPageMeta } from "@/app/backoffice/invoices/page";
import OrdersPage, { metadata as ordersPageMeta } from "@/app/backoffice/orders/page";
import DeliveryNotesPage, {
  metadata as deliveryNotesPageMeta,
} from "@/app/backoffice/delivery-notes/page";
import PortalPage, { metadata as portalPageMeta } from "@/app/backoffice/portal/page";
import SuppliersPage, { metadata as suppliersPageMeta } from "@/app/backoffice/suppliers/page";
import SettingsPage, { metadata as settingsPageMeta } from "@/app/backoffice/settings/page";
import SettingsFeaturesPage, {
  metadata as settingsFeaturesPageMeta,
} from "@/app/backoffice/settings/features/page";
import FollowUpsPage, { metadata as followUpsPageMeta } from "@/app/backoffice/follow-ups/page";
import ClientsPage, { metadata as clientsPageMeta } from "@/app/backoffice/clients/page";
import ProductsPage, { metadata as productsPageMeta } from "@/app/backoffice/products/page";
import AboutPage, { metadata as aboutPageMeta } from "@/app/backoffice/about/page";
import RoadmapPage, { metadata as roadmapPageMeta } from "@/app/backoffice/roadmap/page";
import DocsFlowPage, { metadata as docsFlowPageMeta } from "@/app/backoffice/docs/flow/page";

interface LayoutComponent {
  (props: Readonly<{ children: React.ReactNode }>): React.ReactNode;
}

interface PageComponent {
  (): React.ReactNode;
}

/**
 * One entry per route, resolved by `tests/backoffice-route-titles.test.ts` (which reads the
 * source as text). That gate proves the title string exists and is distinct across routes; it
 * never imports or renders the module, so it contributes nothing to Vitest's own coverage of
 * these declarations. This does: the exact-title assertion catches the same accidental
 * deletion/typo the text-based gate is blind to from a different angle (a runtime import instead
 * of a static read), and the render assertion proves the module doesn't just declare a title —
 * it also mounts without throwing.
 */
const LAYOUTS: ReadonlyArray<{
  title: string;
  metadata: { title?: unknown };
  Layout: LayoutComponent;
}> = [
  { title: "Bank accounts", metadata: bankAccountsLayoutMeta, Layout: BankAccountsLayout },
  {
    title: "Bank account detail",
    metadata: bankAccountDetailLayoutMeta,
    Layout: BankAccountDetailLayout,
  },
  {
    title: "Edit bank account",
    metadata: bankAccountEditLayoutMeta,
    Layout: BankAccountEditLayout,
  },
  { title: "Banks", metadata: banksLayoutMeta, Layout: BanksLayout },
  { title: "Bank detail", metadata: bankDetailLayoutMeta, Layout: BankDetailLayout },
  {
    title: "Accounts of the bank",
    metadata: bankAccountsListLayoutMeta,
    Layout: BankAccountsListLayout,
  },
  {
    title: "Edit account",
    metadata: bankAccountEditNestedLayoutMeta,
    Layout: BankAccountEditNestedLayout,
  },
  { title: "Edit bank", metadata: bankEditLayoutMeta, Layout: BankEditLayout },
  { title: "System Health", metadata: healthLayoutMeta, Layout: HealthLayout },
];

const PAGES: ReadonlyArray<{
  title: string;
  metadata: { title?: unknown };
  Page: PageComponent;
}> = [
  { title: "Dashboard", metadata: dashboardPageMeta, Page: DashboardPage },
  { title: "Quotes", metadata: quotesPageMeta, Page: QuotesPage },
  { title: "Catalog", metadata: catalogPageMeta, Page: CatalogPage },
  { title: "Departments", metadata: catalogDepartmentsPageMeta, Page: CatalogDepartmentsPage },
  {
    title: "Brands & Manufacturers",
    metadata: catalogBrandsPageMeta,
    Page: CatalogBrandsPage,
  },
  { title: "Stock Control", metadata: catalogStockPageMeta, Page: CatalogStockPage },
  { title: "Companies", metadata: companiesPageMeta, Page: CompaniesPage },
  { title: "Employees", metadata: companiesEmployeesPageMeta, Page: CompaniesEmployeesPage },
  { title: "Technical Studies", metadata: pavementStudiesPageMeta, Page: PavementStudiesPage },
  { title: "Pavement Configurator", metadata: configuratorPageMeta, Page: ConfiguratorPage },
  { title: "Technical Explorer", metadata: docsPageMeta, Page: DocsPage },
  { title: "Data Dictionary", metadata: docsDictionaryPageMeta, Page: DocsDictionaryPage },
  { title: "Systems Catalog", metadata: pavementSystemsPageMeta, Page: PavementSystemsPage },
  { title: "AI Reports", metadata: aiReportsPageMeta, Page: AiReportsPage },
  { title: "Project Management", metadata: projectsPageMeta, Page: ProjectsPage },
  { title: "Price Lists", metadata: pricingPageMeta, Page: PricingPage },
  { title: "Finance", metadata: financePageMeta, Page: FinancePage },
  { title: "Cash Flow", metadata: financeCashFlowPageMeta, Page: FinanceCashFlowPage },
  { title: "Budget vs Actuals", metadata: financeControlPageMeta, Page: FinanceControlPage },
  {
    title: "Global Transactions",
    metadata: financeTransactionsPageMeta,
    Page: FinanceTransactionsPage,
  },
  { title: "Cost Allocation", metadata: financeAccountingPageMeta, Page: FinanceAccountingPage },
  { title: "Team Tasks", metadata: tasksPageMeta, Page: TasksPage },
  { title: "Commissions", metadata: commissionsPageMeta, Page: CommissionsPage },
  { title: "Purchase Orders", metadata: purchaseOrdersPageMeta, Page: PurchaseOrdersPage },
  { title: "Provider Portal", metadata: providerPageMeta, Page: ProviderPage },
  { title: "Invoicing", metadata: invoicesPageMeta, Page: InvoicesPage },
  { title: "Sales Orders", metadata: ordersPageMeta, Page: OrdersPage },
  { title: "Delivery Notes", metadata: deliveryNotesPageMeta, Page: DeliveryNotesPage },
  { title: "Client Portal", metadata: portalPageMeta, Page: PortalPage },
  { title: "Suppliers", metadata: suppliersPageMeta, Page: SuppliersPage },
  { title: "Settings", metadata: settingsPageMeta, Page: SettingsPage },
  { title: "Features & Modules", metadata: settingsFeaturesPageMeta, Page: SettingsFeaturesPage },
  { title: "Follow-ups", metadata: followUpsPageMeta, Page: FollowUpsPage },
  { title: "Clients", metadata: clientsPageMeta, Page: ClientsPage },
  { title: "Products Catalog", metadata: productsPageMeta, Page: ProductsPage },
  { title: "About ERPify", metadata: aboutPageMeta, Page: AboutPage },
  { title: "Product Roadmap", metadata: roadmapPageMeta, Page: RoadmapPage },
  { title: "How ERPify Works", metadata: docsFlowPageMeta, Page: DocsFlowPage },
];

/**
 * Metadata-only: each page's own screen already has dedicated render coverage elsewhere
 * (`auditInvestigationScreen.test.tsx`), and rendering the thin `page.tsx` wrapper here would only
 * add a Next router mock this file otherwise has no reason to carry.
 */
const METADATA_ONLY_PAGES: ReadonlyArray<{ title: string; metadata: { title?: unknown } }> = [
  { title: "Audit Logs", metadata: auditPageMeta },
];

describe("Back-office route metadata", () => {
  it.each(METADATA_ONLY_PAGES)("$title page declares its title", ({ title, metadata }) => {
    expect(metadata.title).toBe(title);
  });

  it.each(LAYOUTS)(
    "$title layout declares its title and passes children through",
    ({ title, metadata, Layout }) => {
      expect(metadata.title).toBe(title);
      render(<Layout>{`${title} child`}</Layout>);
      expect(screen.getByText(`${title} child`)).toBeInTheDocument();
    },
  );

  it.each(PAGES)("$title page declares its title and renders", ({ title, metadata, Page }) => {
    expect(metadata.title).toBe(title);
    const { container } = render(<Page />);
    expect(container).not.toBeEmptyDOMElement();
  });
});
