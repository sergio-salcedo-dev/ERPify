"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { ImageIcon } from "lucide-react";
import { cn } from "@/components/cn";
import { imageReadUrl } from "@/context/shared/images/infrastructure/imageReadUrl";

interface ImageDisplayProps {
  /** The id the owner holds — a presence flag and a cache key. `null`/`undefined` when none. */
  imageId: string | null | undefined;
  /**
   * What the image shows. Pass `""` when the owner's name is rendered beside it, so the image
   * adds no second accessible name (the same rule `<MonogramAvatar>` follows).
   */
  alt: string;
  /** Replaces the default glyph shown when there is no image or it did not load. */
  placeholder?: ReactNode;
  /** Extra classes; defaults to a 2.25rem square tile. */
  className?: string;
  /** Test id of the root; the image and the placeholder derive `__image` / `__placeholder`. */
  testId?: string;
}

type ImageState = "loaded" | "loading" | "placeholder";

function imageStateOf(
  src: string | null,
  failedSrc: string | null,
  loadedSrc: string | null,
): ImageState {
  if (src === null || failedSrc === src) return "placeholder";
  return loadedSrc === src ? "loaded" : "loading";
}

/** What a server-rendered `<img>` already settled to: pixels mean loaded, none mean failed. */
function settledOutcome(element: HTMLImageElement | null): "pending" | "failed" | "loaded" {
  if (element?.complete !== true) return "pending";
  return element.naturalWidth === 0 ? "failed" : "loaded";
}

/**
 * A stored image, or a placeholder in its place on ANY failure to show it.
 *
 * The placeholder renders when no id is held, while the image is still loading, and when the load
 * fails — a 404, an offline network, a corrupt body. An `<img>` does not expose why it failed, and
 * a fetch-to-blob read would route the bytes around `safeHref` and the CSP, so the element's own
 * `error` event is the whole signal: on it the `<img>` is unmounted, so the browser's broken-image
 * glyph is never painted.
 *
 * Load and failure state are remembered per URL and compared with the current `src`, so a replaced
 * image (a new id, hence a new URL) starts unloaded and unfailed even though the element is reused.
 */
export function ImageDisplay({
  imageId,
  alt,
  placeholder,
  className,
  testId,
}: Readonly<ImageDisplayProps>) {
  const src = imageReadUrl(imageId);
  const [failedSrc, setFailedSrc] = useState<string | null>(null);
  const [loadedSrc, setLoadedSrc] = useState<string | null>(null);
  const imageRef = useRef<HTMLImageElement>(null);

  // A server-rendered `<img>` can settle before React attaches `onLoad` / `onError`, and neither
  // event is replayed: a settled image is read after the fact, with pixels as loaded and without
  // as failed.
  useEffect(() => {
    const outcome = settledOutcome(imageRef.current);
    if (outcome === "failed") setFailedSrc(src);
    else if (outcome === "loaded") setLoadedSrc(src);
  }, [src]);

  const decorative = alt === "";
  const state = imageStateOf(src, failedSrc, loadedSrc);
  const showImage = state !== "placeholder";
  const showPlaceholder = state !== "loaded";
  // The "not available" name is true only when there is no image to show or it failed; while the
  // image is loading the placeholder is a decoration under it and must not be announced.
  const placeholderIsDecorative = decorative || showImage;

  return (
    <span
      data-testid={testId}
      data-image-state={state}
      className={cn(
        "bg-muted text-muted-foreground relative inline-grid size-9 flex-none place-items-center overflow-hidden rounded-lg",
        className,
      )}
    >
      {showPlaceholder ? (
        <span
          role={placeholderIsDecorative ? undefined : "img"}
          aria-label={placeholderIsDecorative ? undefined : `${alt} (not available)`}
          aria-hidden={placeholderIsDecorative ? "true" : undefined}
          data-testid={testId ? `${testId}__placeholder` : undefined}
          className="absolute inset-0 grid place-items-center"
        >
          {placeholder ?? <ImageIcon className="size-1/2" aria-hidden="true" />}
        </span>
      ) : null}
      {showImage && src !== null ? (
        // A raw <img>: the optimiser would proxy the read through a server that holds no session,
        // and this route is authenticated by the cookie the browser sends itself.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          ref={imageRef}
          src={src}
          alt={alt}
          decoding="async"
          loading="lazy"
          data-testid={testId ? `${testId}__image` : undefined}
          className={cn(
            "size-full object-cover transition-opacity",
            state === "loaded" ? "opacity-100" : "opacity-0",
          )}
          onLoad={() => setLoadedSrc(src)}
          onError={() => setFailedSrc(src)}
        />
      ) : null}
    </span>
  );
}
