import { inject, injectable } from "inversify";
import { safeHref } from "@/lib/safeHref";
import type { HttpClient } from "../../../shared/infrastructure/HttpClient/HttpClient";
import type { BankSearchNavigator } from "../application/BankSearchNavigator";
import type { BankSearchPage } from "../domain/BankRepository";
import { isBankSearchResponse, toBankSearchPage } from "./ApiBankRepository";

/**
 * Infrastructure adapter for {@link BankSearchNavigator}: the ONLY place that
 * knows a pagination `link` is an HTTP URL. It forwards the server-issued link
 * VERBATIM (W11) — it never parses it to extract a cursor or filters and never
 * rebuilds the request (that decompose/recompose would be the W2/W9 violation;
 * the command/parse pattern belongs to the API server, not this client). The
 * link is fetched as-is after a same-origin/relative guard.
 */
@injectable()
export class ApiBankSearchNavigator implements BankSearchNavigator {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async follow(link: string): Promise<BankSearchPage> {
    const target = this.assertSameOriginRelative(link);
    const response = await this.httpClient.get(target, isBankSearchResponse);
    return toBankSearchPage(response);
  }

  /**
   * A server-composed pagination link is ALWAYS a path-absolute, same-origin
   * relative URL (e.g. `/api/v1/backoffice/banks?...`). Reject anything else —
   * an absolute URL, a protocol-relative `//host`, or a dangerous scheme —
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
