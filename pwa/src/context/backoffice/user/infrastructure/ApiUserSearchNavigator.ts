import { inject, injectable } from "inversify";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { ResourceSearchNavigator } from "@/context/shared/resource/domain/ResourceSearchNavigator";
import type { ResourceSearchPage } from "@/context/shared/resource/domain/CrudRepository";
import type { User } from "../domain/User";
import { isUserSearchResponse, toUserSearchPage } from "./ApiUserRepository";

/**
 * Infrastructure adapter for cursor-only navigation of the identity register: the
 * ONLY place that knows a pagination `link` is an HTTP URL. It forwards the
 * server-issued link VERBATIM — never parses it to extract a cursor or filters and
 * never rebuilds the request. The link is fetched as-is after a same-origin guard.
 */
@injectable()
export class ApiUserSearchNavigator implements ResourceSearchNavigator<User> {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async follow(link: string): Promise<ResourceSearchPage<User>> {
    const target = this.assertSameOriginRelative(link);
    const response = await this.httpClient.get(target, isUserSearchResponse);
    return toUserSearchPage(response);
  }

  /**
   * A server-composed pagination link is ALWAYS a path-absolute, same-origin
   * relative URL (e.g. `/api/v1/backoffice/users?...`). Reject anything else — an
   * absolute URL, a protocol-relative `//host`, or a dangerous scheme — before
   * navigating, so a tampered or buggy link can never become an open-redirect.
   * `safeHref` strips the script-bearing schemes; the leading-slash and origin
   * checks add the same-origin guarantee `safeHref` does not cover.
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
