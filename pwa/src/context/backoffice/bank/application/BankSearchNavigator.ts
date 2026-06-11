import type { BankSearchPage } from "../domain/BankRepository";

/**
 * Application port for cursor-only page navigation. `follow` continues a search
 * by fetching a server-issued continuation link (`PageEnvelope.links.next` /
 * `.prev`) VERBATIM — the link is an opaque transport token the client never
 * parses, decodes or reconstructs (W2/W9/W11 of the PR3 execution contract).
 *
 * It lives in the application layer, NOT on the domain `BankRepository` port
 * (Modelo A2): the transport `string` never touches the domain. The single
 * infrastructure adapter ({@link import("../infrastructure/ApiBankSearchNavigator").ApiBankSearchNavigator})
 * is the only place that knows the link is a same-origin relative URL and
 * guards it before the GET. The cursor consequently only ever enters a request
 * through a server-composed link, never reconstructed client-side.
 */
export interface BankSearchNavigator {
  follow(link: string): Promise<BankSearchPage>;
}
