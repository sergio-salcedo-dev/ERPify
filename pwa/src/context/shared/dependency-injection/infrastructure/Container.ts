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
import { BankAccountCrudRepository } from "../../../backoffice/bankaccount/infrastructure/BankAccountCrudRepository";
import { BankAccountResourceNavigator } from "../../../backoffice/bankaccount/infrastructure/BankAccountResourceNavigator";
import { SearchBankAccounts } from "../../../backoffice/bankaccount/application/SearchBankAccounts";
import { FindBankAccount } from "../../../backoffice/bankaccount/application/FindBankAccount";
import { LookupBankAccountByIban } from "../../../backoffice/bankaccount/application/LookupBankAccountByIban";
import { CreateBankAccount } from "../../../backoffice/bankaccount/application/CreateBankAccount";
import { UpdateBankAccount } from "../../../backoffice/bankaccount/application/UpdateBankAccount";
import { ChangeBankAccountStatus } from "../../../backoffice/bankaccount/application/ChangeBankAccountStatus";
import { DeleteBankAccount } from "../../../backoffice/bankaccount/application/DeleteBankAccount";
import { ApiUserRepository } from "../../../backoffice/user/infrastructure/ApiUserRepository";
import { ApiUserSearchNavigator } from "../../../backoffice/user/infrastructure/ApiUserSearchNavigator";
import { ApiLoginRepository } from "../../../backoffice/user/infrastructure/ApiLoginRepository";
import type { LoginRepository } from "../../../backoffice/user/domain/LoginRepository";
import { ApiAcceptInvitationRepository } from "../../../backoffice/user/infrastructure/ApiAcceptInvitationRepository";
import type { AcceptInvitationRepository } from "../../../backoffice/user/domain/AcceptInvitationRepository";
import { ApiForgotPasswordRepository } from "../../../backoffice/user/infrastructure/ApiForgotPasswordRepository";
import type { ForgotPasswordRepository } from "../../../backoffice/user/domain/ForgotPasswordRepository";
import { ApiResetPasswordRepository } from "../../../backoffice/user/infrastructure/ApiResetPasswordRepository";
import type { ResetPasswordRepository } from "../../../backoffice/user/domain/ResetPasswordRepository";
import { ApiInviteUserRepository } from "../../../backoffice/user/infrastructure/ApiInviteUserRepository";
import type { InviteUserRepository } from "../../../backoffice/user/domain/InviteUserRepository";
import { InviteUser } from "../../../backoffice/user/application/InviteUser";
import { ApiChangeUserStatusRepository } from "../../../backoffice/user/infrastructure/ApiChangeUserStatusRepository";
import type { ChangeUserStatusRepository } from "../../../backoffice/user/domain/ChangeUserStatusRepository";
import { ChangeUserStatus } from "../../../backoffice/user/application/ChangeUserStatus";
import { ApiChangeUserRolesRepository } from "../../../backoffice/user/infrastructure/ApiChangeUserRolesRepository";
import type { ChangeUserRolesRepository } from "../../../backoffice/user/domain/ChangeUserRolesRepository";
import { ChangeUserRoles } from "../../../backoffice/user/application/ChangeUserRoles";
import { ApiRevokeInvitationRepository } from "../../../backoffice/user/infrastructure/ApiRevokeInvitationRepository";
import type { RevokeInvitationRepository } from "../../../backoffice/user/domain/RevokeInvitationRepository";
import { RevokeInvitation } from "../../../backoffice/user/application/RevokeInvitation";
import { ApiEraseIdentityRepository } from "../../../backoffice/user/infrastructure/ApiEraseIdentityRepository";
import type { EraseIdentityRepository } from "../../../backoffice/user/domain/EraseIdentityRepository";
import { FulfilIdentityErasure } from "../../../backoffice/user/application/FulfilIdentityErasure";
import { ApiIdentityRepository } from "@/context/shared/access/infrastructure/ApiIdentityRepository";
import type { IdentityRepository } from "@/context/shared/access/domain/IdentityRepository";
import { ApiSessionsRepository } from "@/context/shared/access/infrastructure/ApiSessionsRepository";
import type { SessionsRepository } from "@/context/shared/access/domain/SessionsRepository";
import type { DebugTokenObserver } from "@/context/shared/debug-token/domain/DebugTokenObserver";
import { EventTargetDebugTokenObserver } from "@/context/shared/debug-token/infrastructure/EventTargetDebugTokenObserver";
import { NoopDebugTokenObserver } from "@/context/shared/debug-token/infrastructure/NoopDebugTokenObserver";
import { telemetry, type Telemetry } from "@/context/shared/observability/infrastructure";
import { isDevToolsAvailable } from "../../dev-tools/domain/isDevToolsAvailable";

const container = new Container();

// The debug toolbar exists only outside production. The dev adapter carries the
// profiler token to the toolbar; prod binds the inert no-op so the feature is
// dead by construction even before the (also-absent) profiler header.
container
  .bind<DebugTokenObserver>("DebugTokenObserver")
  .to(isDevToolsAvailable() ? EventTargetDebugTokenObserver : NoopDebugTokenObserver)
  .inSingletonScope();

// FetchHttpClient reports transport failures (client-side, invisible to server
// logs) through the Telemetry port; bind the app singleton so autowiring resolves it.
container.bind<Telemetry>("Telemetry").toConstantValue(telemetry);

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

// Generic resource-toolkit adapters over the bank-account ports for the global,
// cross-bank list: `useResourceList` consumes `{ items }`-shaped pages of the
// collection projection (rows enriched with the owning bank name) through the
// `CrudRepository`/`ResourceSearchNavigator` contracts.
container
  .bind<BankAccountCrudRepository>("BackOfficeBankAccountCrudRepository")
  .to(BankAccountCrudRepository)
  .inSingletonScope();

container
  .bind<BankAccountResourceNavigator>("BackOfficeBankAccountResourceNavigator")
  .to(BankAccountResourceNavigator)
  .inSingletonScope();

container.bind<SearchBankAccounts>("BackOfficeSearchBankAccounts").to(SearchBankAccounts);
container.bind<FindBankAccount>("BackOfficeFindBankAccount").to(FindBankAccount);
container
  .bind<LookupBankAccountByIban>("BackOfficeLookupBankAccountByIban")
  .to(LookupBankAccountByIban);
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

// User read-side: live adapters over the injected HttpClient. Both are stateless
// (they hold no store), so each binds as its own singleton — the register list and
// its cursor navigation read the same backend endpoint.
container.bind("BackOfficeUserRepository").to(ApiUserRepository).inSingletonScope();
container.bind("BackOfficeUserSearchNavigator").to(ApiUserSearchNavigator).inSingletonScope();

// Identity write side: invitation is the alta, through a dedicated identity-shaped port — never the
// generic CrudRepository.create(), which stays a typed no-op. The stateless adapter is a singleton; the use
// case is transient like the other write use cases.
container
  .bind<InviteUserRepository>("BackOfficeInviteUserRepository")
  .to(ApiInviteUserRepository)
  .inSingletonScope();
container.bind<InviteUser>("BackOfficeInviteUser").to(InviteUser);

// Identity write side: status change (suspend/deactivate) through its own identity-shaped port, the sibling
// of invite — never the generic CrudRepository.update(). Stateless adapter singleton; transient use case.
container
  .bind<ChangeUserStatusRepository>("BackOfficeChangeUserStatusRepository")
  .to(ApiChangeUserStatusRepository)
  .inSingletonScope();
container.bind<ChangeUserStatus>("BackOfficeChangeUserStatus").to(ChangeUserStatus);

// Identity write side: role re-grant through its own identity-shaped port — authorization and lifecycle status
// are independent intents, so they never share a use case. Stateless adapter singleton; transient use case.
container
  .bind<ChangeUserRolesRepository>("BackOfficeChangeUserRolesRepository")
  .to(ApiChangeUserRolesRepository)
  .inSingletonScope();
container.bind<ChangeUserRoles>("BackOfficeChangeUserRoles").to(ChangeUserRoles);

// Identity write side: withdrawing a pending invitation through its own port — the counterpart of invite, and
// neither a lifecycle transition nor an erasure. Stateless adapter singleton; transient use case.
container
  .bind<RevokeInvitationRepository>("BackOfficeRevokeInvitationRepository")
  .to(ApiRevokeInvitationRepository)
  .inSingletonScope();
container.bind<RevokeInvitation>("BackOfficeRevokeInvitation").to(RevokeInvitation);

// Identity write side: GDPR erasure through its own destructive identity-shaped port — never the generic
// CrudRepository.delete(). Stateless adapter singleton; transient use case.
container
  .bind<EraseIdentityRepository>("BackOfficeEraseIdentityRepository")
  .to(ApiEraseIdentityRepository)
  .inSingletonScope();
container.bind<FulfilIdentityErasure>("BackOfficeFulfilIdentityErasure").to(FulfilIdentityErasure);

// Session sign-in: a real HTTP adapter over the injected HttpClient,
// which the container binds to MockHttpClient under test.
container
  .bind<LoginRepository>("BackOfficeLoginRepository")
  .to(ApiLoginRepository)
  .inSingletonScope();

// Invitation accept: a real HTTP adapter over the injected HttpClient (bound to
// MockHttpClient under test), mirroring the login adapter.
container
  .bind<AcceptInvitationRepository>("BackOfficeAcceptInvitationRepository")
  .to(ApiAcceptInvitationRepository)
  .inSingletonScope();

// Password recovery: request a reset link (uniform 202) and set a new credential
// with the emailed token (204 → signed in). Both are real HTTP adapters over the
// injected HttpClient, mirroring the accept adapter.
container
  .bind<ForgotPasswordRepository>("BackOfficeForgotPasswordRepository")
  .to(ApiForgotPasswordRepository)
  .inSingletonScope();
container
  .bind<ResetPasswordRepository>("BackOfficeResetPasswordRepository")
  .to(ApiResetPasswordRepository)
  .inSingletonScope();

// Identity / session subsystem: the AuthProvider hydrates from `/me`, and the
// "My sessions" surface reads/revokes the user's own session registry.
container
  .bind<IdentityRepository>("IdentityRepository")
  .to(ApiIdentityRepository)
  .inSingletonScope();
container
  .bind<SessionsRepository>("SessionsRepository")
  .to(ApiSessionsRepository)
  .inSingletonScope();

export { container };
