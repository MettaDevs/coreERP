import * as React from "react"
import { CircleAlert } from "lucide-react"

import { cn } from "../utils"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "./tooltip"

function Card({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card"
      className={cn(
        "flex flex-col gap-6 rounded-xl border bg-card py-6 text-card-foreground shadow-sm",
        className
      )}
      {...props}
    />
  )
}

function CardHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-header"
      className={cn(
        "@container/card-header grid grid-cols-[auto_auto_1fr] items-center gap-2 px-6 [&>:nth-child(3)]:justify-self-end [.border-b]:pb-6",
        className
      )}
      {...props}
    />
  )
}

function CardTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-title"
      className={cn("leading-none font-semibold", className)}
      {...props}
    />
  )
}

function CardDescription({
  className,
  children,
  ...props
}: React.ComponentProps<"button">) {
  return (
    <Tooltip clickToPin>
      <TooltipTrigger asChild>
        <button
          type="button"
          data-slot="card-description"
          aria-label="Lihat deskripsi"
          className={cn(
            "shrink-0 text-muted-foreground hover:text-foreground",
            className
          )}
          {...props}
        >
          <CircleAlert className="size-4" />
        </button>
      </TooltipTrigger>
      <TooltipContent side="left" className="max-w-72">
        {children}
      </TooltipContent>
    </Tooltip>
  )
}

function CardAction({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-action"
      className={cn(
        "col-start-3 row-start-1 self-start justify-self-end",
        className
      )}
      {...props}
    />
  )
}

function CardContent({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-content"
      className={cn("px-6", className)}
      {...props}
    />
  )
}

function CardFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-footer"
      className={cn("flex items-center px-6 [.border-t]:pt-6", className)}
      {...props}
    />
  )
}

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
}
