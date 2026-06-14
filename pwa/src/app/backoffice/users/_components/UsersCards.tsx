"use client";

import Link from "next/link";
import type { User } from "@/context/backoffice/user/domain/User";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import { useIsTruncated } from "@/lib/useIsTruncated";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { safeHref } from "@/lib/safeHref";
import { userRoutes } from "../_lib/userRoutes";
import { UserStatusBadge } from "./UserStatusBadge";
import { RolesBadges } from "./RolesBadges";
import { UserRowActions } from "./UserRowActions";

interface UsersCardsProps {
  users: User[];
  onUserDeleted?: (id: string) => void;
  onUserDeleteFailed: (id: string, problem: ProblemDetails) => void;
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
  density?: "compact" | "comfortable";
}

/**
 * Card title (email) doubles as the whole-card navigation overlay (stretched
 * `::after`). The full-value tooltip mounts only when the 2-line clamp actually
 * truncates; the link is the card's tab stop, so the tooltip opens on keyboard
 * focus for free.
 */
function UserCardEmail({ user, detailHref }: Readonly<{ user: User; detailHref: string }>) {
  const { ref, truncated } = useIsTruncated<HTMLAnchorElement>(user.email);
  const link = (
    <Link
      ref={ref}
      href={detailHref}
      className="users-cards__email text-foreground line-clamp-2 block min-h-[2.7em] leading-[1.35] font-semibold [overflow-wrap:anywhere] after:absolute after:inset-0 hover:underline focus-visible:underline focus-visible:outline-none"
      data-testid={`users-cards__email-${user.id}`}
    >
      {user.email}
    </Link>
  );
  if (!truncated) return link;
  return (
    <Tooltip>
      <TooltipTrigger render={link} />
      <TooltipContent className="max-h-28 max-w-[360px] overflow-y-auto break-words whitespace-pre-wrap">
        {user.email}
      </TooltipContent>
    </Tooltip>
  );
}

export function UsersCards({
  users,
  onUserDeleted,
  onUserDeleteFailed,
  selectedIds,
  onToggleSelect,
  density = "compact",
}: Readonly<UsersCardsProps>) {
  return (
    <ul
      className="users-cards grid list-none auto-rows-fr grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
      data-testid="users-cards"
    >
      {users.map((user) => {
        const detailHref = safeHref(userRoutes.detail(user.id));
        const selected = selectedIds?.has(user.id) ?? false;
        return (
          <li
            key={user.id}
            className="users-cards__item h-full"
            data-testid={`users-cards__item-${user.id}`}
          >
            <Card
              size={density === "comfortable" ? "default" : "sm"}
              className={cn(
                "users-cards__card hover:shadow-elevation-1 hover:ring-foreground/20 relative flex h-full flex-col transition-shadow motion-reduce:transition-none",
                selected && "ring-primary bg-row-selected ring-2",
              )}
            >
              <div className="users-cards__controls flex min-w-0 items-center gap-2 px-4 group-data-[size=sm]/card:px-3">
                {onToggleSelect ? (
                  <input
                    type="checkbox"
                    aria-label={`Select user ${user.email}`}
                    checked={selected}
                    onChange={() => onToggleSelect(user.id)}
                    className="users-cards__select accent-primary border-border relative z-10 size-4 flex-none cursor-pointer rounded"
                    data-testid={`users-cards__select-${user.id}`}
                  />
                ) : null}
                <UserStatusBadge status={user.status} testId={`users-cards__status-${user.id}`} />
                <UserRowActions
                  id={user.id}
                  email={user.email}
                  surface="cards"
                  reveal="card"
                  onUserDeleted={onUserDeleted}
                  onUserDeleteFailed={onUserDeleteFailed}
                  className="users-cards__actions relative z-10 ml-auto"
                />
              </div>
              <div className="users-cards__title px-4 group-data-[size=sm]/card:px-3">
                <UserCardEmail user={user} detailHref={detailHref} />
              </div>
              <div className="users-cards__roles px-4 group-data-[size=sm]/card:px-3">
                <RolesBadges roles={user.roles} testId={`users-cards__roles-${user.id}`} />
              </div>
              <CardContent className="users-cards__footer border-border mt-auto border-t pt-3">
                <dl className="users-cards__meta grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs tabular-nums">
                  <dt className="text-muted-foreground">Created</dt>
                  <dd
                    className="users-cards__created text-foreground"
                    title={dateTimeProvider.formatIsoToLocalDateTime(user.createdAt)}
                    data-testid={`users-cards__created-${user.id}`}
                  >
                    {dateTimeProvider.formatIsoToRelative(user.createdAt)}
                  </dd>
                </dl>
              </CardContent>
            </Card>
          </li>
        );
      })}
    </ul>
  );
}
