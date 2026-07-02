import { inject, injectable } from "inversify";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { ResourceSearchPage } from "@/context/shared/resource/domain/CrudRepository";
import type { ResourceSearchNavigator } from "@/context/shared/resource/domain/ResourceSearchNavigator";
import type { BankAccountCollectionRow } from "../domain/BankAccountCollectionRow";
import {
  isBankAccountCollectionResponse,
  toBankAccountCollectionPage,
} from "./ApiBankAccountRepository";
import { toResourcePage } from "./bankAccountResourcePage";

/**
 * Cursor-only navigation for the global `/bank-accounts` collection, implementing
 * the shared {@link ResourceSearchNavigator} the `useResourceList` toolkit
 * consumes (`follow` → `{ items }`). It is the ONLY place that knows a pagination
 * `link` is an HTTP URL: it forwards the server-issued link VERBATIM — never
 * parsing it to extract a cursor or filters, never rebuilding the request — after
 * a same-origin/relative guard, then remaps the collection page to `{ items }`.
 * Errors propagate untouched.
 */
@injectable()
export class BankAccountResourceNavigator implements ResourceSearchNavigator<BankAccountCollectionRow> {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async follow(link: string): Promise<ResourceSearchPage<BankAccountCollectionRow>> {
    const target = this.assertSameOriginRelative(link);
    const response = await this.httpClient.get(target, isBankAccountCollectionResponse);
    return toResourcePage(toBankAccountCollectionPage(response));
  }

  /**
   * A server-composed pagination link is ALWAYS a path-absolute, same-origin
   * relative URL (e.g. `/api/v1/backoffice/bank-accounts?...`). Reject anything
   * else — an absolute URL, a protocol-relative `//host`, or a dangerous scheme —
   * before navigating, so a tampered or buggy link can never become an
   * open-redirect. `safeHref` strips the script-bearing schemes; the
   * leading-slash and origin checks add the same-origin guarantee `safeHref`
   * does not cover.
   */
  private assertSameOriginRelative(link: string): string {
    const safe = safeHref(link, "");
    if (safe === "" || !safe.startsWith("/") || safe.startsWith("//")) {
      throw new Error("Refusing to follow a non-relative pagination link.");
    }
    const sentinel = "https://pagination.invalid";
    if (new URL(safe, sentinel).origin !== sentinel) {
      throw new Error("Refusing to follow a cross-origin pagination link.");
    }
    return safe;
  }
}
