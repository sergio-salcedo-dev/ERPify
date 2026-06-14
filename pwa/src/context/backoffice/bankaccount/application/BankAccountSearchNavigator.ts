import type { BankAccountSearchPage } from "../domain/BankAccountRepository";

/**
 * Application port for cursor-only page navigation. `follow` continues a search
 * by fetching a server-issued continuation link (`PageEnvelope.links.next` /
 * `.prev`) VERBATIM — the link is an opaque transport token the client never
 * parses, decodes or reconstructs.
 *
 * It lives in the application layer, NOT on the domain `BankAccountRepository`
 * port: the transport `string` never touches the domain. The single
 * infrastructure adapter is the only place that knows the link is a same-origin
 * relative URL and guards it before the GET.
 */
export interface BankAccountSearchNavigator {
  follow(link: string): Promise<BankAccountSearchPage>;
}
