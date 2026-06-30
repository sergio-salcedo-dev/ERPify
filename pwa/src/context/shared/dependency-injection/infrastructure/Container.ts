import "reflect-metadata";
import { Container } from "inversify";
import { FetchHttpClient } from "@/context/shared/http-client/infrastructure/FetchHttpClient";
import { MockHttpClient } from "@/context/shared/http-client/infrastructure/MockHttpClient";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { ApiHealthCheckRepository as FrontOfficeApiHealthCheckRepository } from "../../../frontoffice/health/infrastructure/ApiHealthCheckRepository";
import { ApiHealthCheckRepository as BackOfficeApiHealthCheckRepository } from "../../../backoffice/health/infrastructure/ApiHealthCheckRepository";
import { ApiDatabaseHealthCheckRepository as BackOfficeApiDatabaseHealthCheckRepository } from "../../../backoffice/health/infrastructure/ApiDatabaseHealthCheckRepository";
import { CheckHealth as FrontOfficeCheckHealth } from "../../../frontoffice/health/application/CheckHealth";
import { CheckHealth as BackOfficeCheckHealth } from "../../../backoffice/health/application/CheckHealth";
import { CheckDatabaseHealth as BackOfficeCheckDatabaseHealth } from "../../../backoffice/health/application/CheckDatabaseHealth";
import { ApiBankRepository } from "../../../backoffice/bank/infrastructure/ApiBankRepository";
import { ApiBankCountReader } from "../../../backoffice/bank/infrastructure/ApiBankCountReader";
import { ApiBankSearchNavigator } from "../../../backoffice/bank/infrastructure/ApiBankSearchNavigator";
import { BankCrudRepository } from "../../../backoffice/bank/infrastructure/BankCrudRepository";
import { BankResourceNavigator } from "../../../backoffice/bank/infrastructure/BankResourceNavigator";
import { SearchBanks } from "../../../backoffice/bank/application/SearchBanks";
import { CountBanks } from "../../../backoffice/bank/application/CountBanks";
import { FindBank } from "../../../backoffice/bank/application/FindBank";
import { CreateBank } from "../../../backoffice/bank/application/CreateBank";
import { UpdateBank } from "../../../backoffice/bank/application/UpdateBank";
import { DeleteBank } from "../../../backoffice/bank/application/DeleteBank";
import { ApiAuditTimelineRepository } from "../../../backoffice/audit/infrastructure/ApiAuditTimelineRepository";
import { ApiAuditTimelineNavigator } from "../../../backoffice/audit/infrastructure/ApiAuditTimelineNavigator";
import { ApiAuditEventDetailRepository } from "../../../backoffice/audit/infrastructure/ApiAuditEventDetailRepository";
import { ApiBankAccountRepository } from "../../../backoffice/bankaccount/infrastructure/ApiBankAccountRepository";
import { ApiBankAccountSearchNavigator } from "../../../backoffice/bankaccount/infrastructure/ApiBankAccountSearchNavigator";
import { SearchBankAccounts } from "../../../backoffice/bankaccount/application/SearchBankAccounts";
import { FindBankAccount } from "../../../backoffice/bankaccount/application/FindBankAccount";
import { CreateBankAccount } from "../../../backoffice/bankaccount/application/CreateBankAccount";
import { UpdateBankAccount } from "../../../backoffice/bankaccount/application/UpdateBankAccount";
import { ChangeBankAccountStatus } from "../../../backoffice/bankaccount/application/ChangeBankAccountStatus";
import { DeleteBankAccount } from "../../../backoffice/bankaccount/application/DeleteBankAccount";
import { InMemoryUserRepository } from "../../../backoffice/user/infrastructure/InMemoryUserRepository";
import { InMemoryResourceNavigator } from "../../../shared/resource/infrastructure/InMemoryResourceNavigator";
import type { DebugTokenObserver } from "@/context/shared/debug-token/domain/DebugTokenObserver";
import { EventTargetDebugTokenObserver } from "@/context/shared/debug-token/infrastructure/EventTargetDebugTokenObserver";
import { NoopDebugTokenObserver } from "@/context/shared/debug-token/infrastructure/NoopDebugTokenObserver";
import { isDevToolsAvailable } from "../../dev-tools/domain/isDevToolsAvailable";

const container = new Container();

// The debug toolbar exists only outside production. The dev adapter carries the
// profiler token to the toolbar; prod binds the inert no-op so the feature is
// dead by construction even before the (also-absent) profiler header.
container
  .bind<DebugTokenObserver>("DebugTokenObserver")
  .to(isDevToolsAvailable() ? EventTargetDebugTokenObserver : NoopDebugTokenObserver)
  .inSingletonScope();

const useMockHttp = process.env.NODE_ENV === "test" || process.env.VITEST === "true";

if (useMockHttp) {
  container.bind<HttpClient>("HttpClient").to(MockHttpClient).inSingletonScope();
} else {
  container.bind<HttpClient>("HttpClient").to(FetchHttpClient).inSingletonScope();
}

container
  .bind<FrontOfficeApiHealthCheckRepository>("FrontOfficeHealthCheckRepository")
  .to(FrontOfficeApiHealthCheckRepository)
  .inSingletonScope();

container
  .bind<BackOfficeApiHealthCheckRepository>("BackOfficeHealthCheckRepository")
  .to(BackOfficeApiHealthCheckRepository)
  .inSingletonScope();

container
  .bind<BackOfficeApiDatabaseHealthCheckRepository>("BackOfficeDatabaseHealthCheckRepository")
  .to(BackOfficeApiDatabaseHealthCheckRepository)
  .inSingletonScope();

container.bind<FrontOfficeCheckHealth>("FrontOfficeCheckHealth").to(FrontOfficeCheckHealth);
container.bind<BackOfficeCheckHealth>("BackOfficeCheckHealth").to(BackOfficeCheckHealth);
container
  .bind<BackOfficeCheckDatabaseHealth>("BackOfficeCheckDatabaseHealth")
  .to(BackOfficeCheckDatabaseHealth);

container
  .bind<ApiBankRepository>("BackOfficeBankRepository")
  .to(ApiBankRepository)
  .inSingletonScope();

// Narrow read port for the banks total (projection read model) — deliberately
// separate from the search/CRUD repository so that contract stays untouched.
container
  .bind<ApiBankCountReader>("BackOfficeBankCountReader")
  .to(ApiBankCountReader)
  .inSingletonScope();

container
  .bind<ApiBankSearchNavigator>("BackOfficeBankSearchNavigator")
  .to(ApiBankSearchNavigator)
  .inSingletonScope();

// Generic resource-toolkit adapters over the bank ports: the banks list page
// runs on `useResourceList`, which consumes `{ items }`-shaped pages through the
// `CrudRepository`/`ResourceSearchNavigator` contracts.
container
  .bind<BankCrudRepository>("BackOfficeBankCrudRepository")
  .to(BankCrudRepository)
  .inSingletonScope();

container
  .bind<BankResourceNavigator>("BackOfficeBankResourceNavigator")
  .to(BankResourceNavigator)
  .inSingletonScope();

container.bind<SearchBanks>("BackOfficeSearchBanks").to(SearchBanks);
container.bind<CountBanks>("BackOfficeCountBanks").to(CountBanks);
container.bind<FindBank>("BackOfficeFindBank").to(FindBank);
container.bind<CreateBank>("BackOfficeCreateBank").to(CreateBank);
container.bind<UpdateBank>("BackOfficeUpdateBank").to(UpdateBank);
container.bind<DeleteBank>("BackOfficeDeleteBank").to(DeleteBank);

// BankAccount context: one repository serves both the per-bank search/navigation
// reads and the standalone `/bank-accounts` CRUD writes (mirrors Bank's single
// repository identifier).
container
  .bind<ApiBankAccountRepository>("BackOfficeBankAccountRepository")
  .to(ApiBankAccountRepository)
  .inSingletonScope();

container
  .bind<ApiBankAccountSearchNavigator>("BackOfficeBankAccountSearchNavigator")
  .to(ApiBankAccountSearchNavigator)
  .inSingletonScope();

container.bind<SearchBankAccounts>("BackOfficeSearchBankAccounts").to(SearchBankAccounts);
container.bind<FindBankAccount>("BackOfficeFindBankAccount").to(FindBankAccount);
container.bind<CreateBankAccount>("BackOfficeCreateBankAccount").to(CreateBankAccount);
container.bind<UpdateBankAccount>("BackOfficeUpdateBankAccount").to(UpdateBankAccount);
container
  .bind<ChangeBankAccountStatus>("BackOfficeChangeBankAccountStatus")
  .to(ChangeBankAccountStatus);
container.bind<DeleteBankAccount>("BackOfficeDeleteBankAccount").to(DeleteBankAccount);

// Audit investigation read context (epic 4): read-only ports over the 4.1 timeline read model —
// no write use case is bound, so a consumer can inject only the read capability (Backoffice
// consumes auditoría, never writes it, D5).
container
  .bind<ApiAuditTimelineRepository>("BackOfficeAuditTimelineRepository")
  .to(ApiAuditTimelineRepository)
  .inSingletonScope();

container
  .bind<ApiAuditTimelineNavigator>("BackOfficeAuditTimelineNavigator")
  .to(ApiAuditTimelineNavigator)
  .inSingletonScope();

// The audit event detail (the field-by-field diff) is a sibling read port over `/audit/events/{id}`,
// lifted on demand when a row opens so the keyset timeline stays slim.
container
  .bind<ApiAuditEventDetailRepository>("BackOfficeAuditEventDetailRepository")
  .to(ApiAuditEventDetailRepository)
  .inSingletonScope();

// User module (mocked). The navigator shares the SAME repository instance so it
// reads the same in-memory store — a singleton class binding would create two.
const userRepository = new InMemoryUserRepository();
container.bind("BackOfficeUserRepository").toConstantValue(userRepository);
container
  .bind("BackOfficeUserSearchNavigator")
  .toConstantValue(new InMemoryResourceNavigator(userRepository));

export { container };
