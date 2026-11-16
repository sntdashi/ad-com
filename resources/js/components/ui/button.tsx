import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "relative isolate inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl border text-sm font-semibold transition-[color,background-color,border-color,box-shadow] duration-150 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/35 focus-visible:ring-[4px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "border-brand-deep/20 bg-[linear-gradient(180deg,#c93242,#b52636)] text-white shadow-[inset_0_1px_0_rgba(255,255,255,.22),0_2px_4px_rgba(25,26,28,.08)] hover:brightness-95",
        destructive:
          "border-transparent bg-red-700 text-white hover:bg-red-800 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
        outline:
          "border-border bg-[var(--surface-1)] text-foreground shadow-xs hover:border-primary/40 hover:bg-[var(--surface-2)]",
        secondary:
          "border-border bg-secondary text-secondary-foreground hover:bg-muted",
        ghost:
          "border-transparent bg-transparent shadow-none hover:bg-muted hover:text-foreground",
        link: "border-transparent bg-transparent text-[var(--brand-text)] shadow-none backdrop-blur-none underline-offset-4 hover:translate-y-0 hover:underline",
      },
      size: {
        default: "h-11 px-4 py-2 has-[>svg]:px-3.5",
        sm: "h-9 rounded-xl px-3 has-[>svg]:px-2.5",
        lg: "h-12 rounded-2xl px-6 has-[>svg]:px-4",
        icon: "size-10 rounded-full",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
