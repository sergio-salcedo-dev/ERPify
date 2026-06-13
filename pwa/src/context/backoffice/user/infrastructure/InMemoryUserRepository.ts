import { injectable } from "inversify";
import { FilterOperator, type Filter } from "@/context/shared/domain/Search";
import {
  InMemoryCrudRepository,
  type InMemoryEntityConfig,
} from "@/context/shared/resource/infrastructure/InMemoryCrudRepository";
import { uuidV7 } from "@/lib/uuidV7";
import { User } from "../domain/User";
import type { UserInput } from "../domain/UserRepository";
import { userSeed } from "./userSeed";

const config: InMemoryEntityConfig<User, UserInput> = {
  matchesFilter: (user, filter: Filter): boolean => {
    if (filter.field === "email" && filter.operator === FilterOperator.Contains) {
      return user.email.toLowerCase().includes(String(filter.value).toLowerCase());
    }
    if (filter.field === "status" && filter.operator === FilterOperator.Eq) {
      return user.status === filter.value;
    }
    if (filter.field === "role" && filter.operator === FilterOperator.Eq) {
      return user.roles.includes(filter.value as User["roles"][number]);
    }
    return true;
  },
  compare: (field) => (a, b) => {
    if (field === "status") return a.status.localeCompare(b.status);
    if (field === "createdAt") return a.createdAt.localeCompare(b.createdAt);
    if (field === "updatedAt") return a.updatedAt.localeCompare(b.updatedAt);
    return a.email.localeCompare(b.email);
  },
  fromInput: (input, id, nowIso) =>
    new User(id, input.email, input.status, input.roles, input.permissions ?? [], nowIso, nowIso),
  applyInput: (existing, input, nowIso) =>
    new User(
      existing.id,
      existing.email,
      input.status,
      input.roles,
      input.permissions ?? existing.permissions,
      existing.createdAt,
      nowIso,
    ),
};

/**
 * Mocked user repository over the generic in-memory base. ~250ms latency makes
 * the loading skeletons visible. Swap for an ApiUserRepository to go live.
 */
@injectable()
export class InMemoryUserRepository extends InMemoryCrudRepository<User, UserInput> {
  constructor() {
    super(
      userSeed.map(User.fromPrimitives),
      config,
      () => uuidV7(),
      () => new Date().toISOString(),
      250,
    );
  }
}
