"use client";

import * as React from "react";

import { cn } from "@/components/cn";

// `htmlFor` is required and named rather than left to the spread: a vendor primitive that
// forwards it invisibly can only be trusted, while one that declares it can be checked — by the
// compiler at every call site, and by the label/control rule right here.
function Label({
  className,
  htmlFor,
  ...props
}: React.ComponentProps<"label"> & { htmlFor: string }) {
  return (
    <label
      htmlFor={htmlFor}
      data-slot="label"
      className={cn(
        "flex items-center gap-2 text-sm leading-none font-medium select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50",
        className,
      )}
      {...props}
    />
  );
}

export { Label };
