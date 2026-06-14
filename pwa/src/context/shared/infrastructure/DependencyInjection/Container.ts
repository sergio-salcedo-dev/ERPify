import "reflect-metadata";
import { Container } from "inversify";
import { FetchHttpClient, MockHttpClient, type HttpClient } from "../HttpClient/HttpClient";
import { ApiHealthCheckRepository as FrontOfficeApiHealthCheckRepository } from "../../../frontoffice/health/infrastructure/ApiHealthCheckRepository";
import { ApiHealthCheckRepository as BackOfficeApiHealthCheckRepository } from "../../../backoffice/health/infrastructure/ApiHealthCheckRepository";
import { ApiDatabaseHealthCheckRepository as BackOfficeApiDatabaseHealthCheckRepository } from "../../../backoffice/health/infrastructure/ApiDatabaseHealthCheckRepository";
import { CheckHealth as FrontOfficeCheckHealth } from "../../../frontoffice/health/application/CheckHealth";
import { CheckHealth as BackOfficeCheckHealth } from "../../../backoffice/health/application/CheckHealth";
import { CheckDatabaseHealth as BackOfficeCheckDatabaseHealth } from "../../../backoffice/health/application/CheckDatabaseHealth";
import { ApiBankRepository } from "../../../backoffice/bank/infrastructure/ApiBankRepository";
import { ApiBankSearchNavigator } from "../../../backoffice/bank/infrastructure/ApiBankSearchNavigator";
import { BankCrudRepository } from "../../../backoffice/bank/infrastructure/BankCrudRepository";
import { BankResourceNavigator } from "../../../backoffice/bank/infrastructure/BankResourceNavigator";
import { SearchBanks } from "../../../backoffice/bank/application/SearchBanks";
import { FindBank } from "../../../backoffice/bank/application/FindBank";
import { CreateBank } from "../../../backoffice/bank/application/CreateBank";
import { UpdateBank } from "../../../backoffice/bank/application/UpdateBank";
import { DeleteBank } from "../../../backoffice/bank/application/DeleteBank";
import { ApiBankAccountRepository } from "../../../backoffice/bankaccount/infrastructure/ApiBankAccountRepository";
import { ApiBankAccountSearchNavigator } from "../../../backoffice/bankaccount/infrastructure/ApiBankAccountSearchNavigator";
import { SearchBankAccounts } from "../../../backoffice/bankaccount/application/SearchBankAccounts";
import { InMemoryUserRepository } from "../../../backoffice/user/infrastructure/InMemoryUserRepository";
import { InMemoryResourceNavigator } from "../../../shared/resource/infrastructure/InMemoryResourceNavigator";

const container = new Container();

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
container.bind<FindBank>("BackOfficeFindBank").to(FindBank);
container.bind<CreateBank>("BackOfficeCreateBank").to(CreateBank);
container.bind<UpdateBank>("BackOfficeUpdateBank").to(UpdateBank);
container.bind<DeleteBank>("BackOfficeDeleteBank").to(DeleteBank);

// BankAccount read context (CE-4): read-only ports — no write use case is bound,
// so a consumer of this surface can inject only the read capability.
container
  .bind<ApiBankAccountRepository>("BackOfficeBankAccountRepository")
  .to(ApiBankAccountRepository)
  .inSingletonScope();

container
  .bind<ApiBankAccountSearchNavigator>("BackOfficeBankAccountSearchNavigator")
  .to(ApiBankAccountSearchNavigator)
  .inSingletonScope();

container.bind<SearchBankAccounts>("BackOfficeSearchBankAccounts").to(SearchBankAccounts);

// User module (mocked). The navigator shares the SAME repository instance so it
// reads the same in-memory store — a singleton class binding would create two.
const userRepository = new InMemoryUserRepository();
container.bind("BackOfficeUserRepository").toConstantValue(userRepository);
container
  .bind("BackOfficeUserSearchNavigator")
  .toConstantValue(new InMemoryResourceNavigator(userRepository));

export { container };
