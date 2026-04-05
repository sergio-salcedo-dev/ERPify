import "reflect-metadata";
import { Container } from "inversify";
import { FetchHttpClient, MockHttpClient, type HttpClient } from "../HttpClient/HttpClient";
import { ApiHealthCheckRepository as FrontOfficeApiHealthCheckRepository } from "../../../frontoffice/health/infrastructure/ApiHealthCheckRepository";
import { ApiHealthCheckRepository as BackOfficeApiHealthCheckRepository } from "../../../backoffice/health/infrastructure/ApiHealthCheckRepository";
import { CheckHealth as FrontOfficeCheckHealth } from "../../../frontoffice/health/application/CheckHealth";
import { CheckHealth as BackOfficeCheckHealth } from "../../../backoffice/health/application/CheckHealth";

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

export { container };
