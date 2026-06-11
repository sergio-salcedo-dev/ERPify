import "reflect-metadata";
import { Container } from "inversify";
import { FetchHttpClient, MockHttpClient, type HttpClient } from "../HttpClient/HttpClient";
import { ApiHealthCheckRepository as FrontOfficeApiHealthCheckRepository } from "../../../frontoffice/health/infrastructure/ApiHealthCheckRepository";
import { ApiHealthCheckRepository as BackOfficeApiHealthCheckRepository } from "../../../backoffice/health/infrastructure/ApiHealthCheckRepository";
import { CheckHealth as FrontOfficeCheckHealth } from "../../../frontoffice/health/application/CheckHealth";
import { CheckHealth as BackOfficeCheckHealth } from "../../../backoffice/health/application/CheckHealth";
import { ApiBankRepository } from "../../../backoffice/bank/infrastructure/ApiBankRepository";
import { ApiBankSearchNavigator } from "../../../backoffice/bank/infrastructure/ApiBankSearchNavigator";
import { SearchBanks } from "../../../backoffice/bank/application/SearchBanks";
import { FindBank } from "../../../backoffice/bank/application/FindBank";
import { CreateBank } from "../../../backoffice/bank/application/CreateBank";
import { UpdateBank } from "../../../backoffice/bank/application/UpdateBank";
import { DeleteBank } from "../../../backoffice/bank/application/DeleteBank";

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

container.bind<FrontOfficeCheckHealth>("FrontOfficeCheckHealth").to(FrontOfficeCheckHealth);
container.bind<BackOfficeCheckHealth>("BackOfficeCheckHealth").to(BackOfficeCheckHealth);

container
  .bind<ApiBankRepository>("BackOfficeBankRepository")
  .to(ApiBankRepository)
  .inSingletonScope();

container
  .bind<ApiBankSearchNavigator>("BackOfficeBankSearchNavigator")
  .to(ApiBankSearchNavigator)
  .inSingletonScope();

container.bind<SearchBanks>("BackOfficeSearchBanks").to(SearchBanks);
container.bind<FindBank>("BackOfficeFindBank").to(FindBank);
container.bind<CreateBank>("BackOfficeCreateBank").to(CreateBank);
container.bind<UpdateBank>("BackOfficeUpdateBank").to(UpdateBank);
container.bind<DeleteBank>("BackOfficeDeleteBank").to(DeleteBank);

export { container };
